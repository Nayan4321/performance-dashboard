<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Call;
use App\Models\Guest;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * CallGear interactive call processing: on every incoming call CallGear requests
 *   /webhooks/callgear/incoming?token=SECRET&cdr_id=..&start_time=..&numa=CALLER&numb=DIALED
 * We log the call, match the caller to a Zenoti guest and the dialed number to a branch,
 * and answer with the (optional) routing instruction from CALLGEAR_INCOMING_REPLY.
 * Always answers 200 quickly so a problem here never holds up a live call.
 */
class CallGearIncomingController extends Controller
{
    public function __invoke(Request $request)
    {
        $secret = config('callgear.webhook_secret');
        $token = $request->query('token') ?? $request->header('X-Webhook-Token');
        if (! filled($secret) || ! is_string($token) || ! hash_equals($secret, $token)) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $params = $request->except('token');
        $event = WebhookEvent::create(['provider' => 'callgear', 'event_type' => 'incoming_call', 'payload' => $params]);

        try {
            $caller = (string) ($params['numa'] ?? '');
            $dialed = (string) ($params['numb'] ?? '');
            $guest = ($key = Guest::phoneKey($caller)) ? Guest::where('phone_key', $key)->latest('id')->first() : null;
            $dialedKey = Guest::phoneKey($dialed);
            $branch = $dialedKey ? Branch::whereNotNull('phone')->get()->first(fn ($b) => Guest::phoneKey($b->phone) === $dialedKey) : null;

            Call::updateOrCreate(
                ['external_id' => filled($params['cdr_id'] ?? null) ? (string) $params['cdr_id'] : 'in-'.$event->id],
                [
                    'direction' => 'in',
                    'status' => 'incoming',
                    'caller' => $caller,
                    'callee' => $dialed,
                    'guest_id' => $guest?->id,
                    'branch_id' => $branch?->id ?? $guest?->branch_id,
                    'started_at' => $this->startTime($params['start_time'] ?? null),
                    'raw' => $params,
                ]
            );
            $event->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (Throwable $e) {
            report($e);
            $event->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
        }

        $reply = json_decode((string) config('callgear.incoming_reply'), true);

        return response()->json(is_array($reply) ? $reply : new \stdClass);
    }

    private function startTime($value): Carbon
    {
        try {
            if (is_numeric($value) && $value > 1000000000) {
                return Carbon::createFromTimestamp((int) $value);
            }
            if (filled($value) && ! is_numeric($value)) {
                return Carbon::parse($value);
            }
        } catch (Throwable) {
        }

        return now();
    }
}

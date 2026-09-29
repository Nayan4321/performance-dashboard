<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Http\Request;
use Throwable;

/**
 * POST /webhooks/{provider}?token=SECRET
 * Stores the payload first (nothing is lost if processing fails), then applies it immediately
 * because shared hosting has no queue worker.
 */
class IntegrationWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, IntegrationManager $integrations)
    {
        $source = $integrations->get($provider);

        if (! $source->verifyWebhookToken($request->query('token') ?? $request->header('X-Webhook-Token'))) {
            return response()->json(['message' => 'Invalid token'], 401);
        }

        $payload = $request->json()->all() ?: $request->all();
        $event = WebhookEvent::create([
            'provider' => $provider,
            'event_type' => $payload['event_type'] ?? $payload['EventType'] ?? $payload['event'] ?? $payload['type'] ?? null,
            'payload' => $payload,
        ]);

        try {
            $source->handleWebhook($event);
        } catch (Throwable $e) {
            report($e);
            $event->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);
        }

        return response()->json(['received' => true, 'status' => $event->status]);
    }
}

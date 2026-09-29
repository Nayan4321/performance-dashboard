<?php

namespace App\Console\Commands;

use App\Models\SyncRequest;
use App\Services\Integrations\IntegrationManager;
use App\Services\Zenoti\ZenotiSource;
use Illuminate\Console\Command;

/** Runs the syncs requested with "Sync now" on Admin › Integrations. Called every minute by the scheduler. */
class RunSyncRequests extends Command
{
    public const HEARTBEAT = 'scheduler.heartbeat';

    public static function heartbeatFile(): string
    {
        return storage_path('framework/scheduler-heartbeat');
    }

    public static function beat(): void
    {
        @file_put_contents(static::heartbeatFile(), now()->toIso8601String().' PHP '.PHP_VERSION);
    }

    public static function lastBeat(): ?\Illuminate\Support\Carbon
    {
        $raw = @file_get_contents(static::heartbeatFile());

        return $raw ? \Illuminate\Support\Carbon::parse(strtok($raw, ' ')) : null;
    }

    protected $signature = 'integrations:run-requests {--web : started from the web page, not cron (leaves the cron heartbeat alone)}';

    protected $description = 'Run queued "Sync now" requests from the Integrations page';

    public function handle(IntegrationManager $integrations): int
    {
        if (! $this->option('web')) {
            static::beat();
        }
        @set_time_limit(0);
        $web = (bool) $this->option('web');
        // One runner at a time: the Integrations page may ask for the next round while one still works.
        $lock = \Illuminate\Support\Facades\Cache::lock('integrations:run-requests', $web ? 300 : 3600);
        if (! $lock->get()) {
            $this->line('Another sync runner is working.');

            return self::SUCCESS;
        }

        try {
            $this->runQueued($integrations, $web);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    private function runQueued(IntegrationManager $integrations, bool $web): void
    {
        // A run the server killed (time limit, restart) shows no sign of life for minutes.
        // Guest profiles pick up where they stopped, so those go back in the queue.
        SyncRequest::where('status', 'running')->where('updated_at', '<', now()->subMinutes(5))->where('entity', 'guests')
            ->update(['status' => 'queued', 'message' => 'Continuing after the server stopped the last round.']);
        SyncRequest::where('status', 'running')->where('updated_at', '<', now()->subMinutes(30))
            ->update(['status' => 'failed', 'message' => 'Stopped without finishing: the server ended the process. Run it again, or over SSH for long syncs.', 'finished_at' => now()]);

        $done = [];
        while ($request = SyncRequest::where('status', 'queued')->whereNotIn('id', $done)->oldest('id')->first()) {
            $done[] = $request->id;
            $request->update(['status' => 'running', 'started_at' => $request->started_at ?? now()]);
            \App\Services\Integrations\SyncRecorder::$stopCheck = function () use ($request) {
                $request->touch(); // sign of life, see above

                return SyncRequest::whereKey($request->id)->value('status') === 'cancelling';
            };
            try {
                $source = $integrations->get($request->provider);
                if ($source instanceof ZenotiSource) {
                    $source->withAllGuestProfiles();
                    if ($request->days) {
                        $source->withLookbackDays($request->days);
                    }
                    if ($request->branch_id || $request->tag) {
                        $source->scopeTo($request->branch_id, $request->tag);
                    }
                    if ($web) {
                        $source->withGuestProfileSeconds((int) config('zenoti.web_guest_seconds', 90));
                    }
                } elseif ($source instanceof \App\Services\CallGear\CallGearSource && $request->days) {
                    $source->withLookbackDays($request->days);
                }
                $runs = collect($source->sync($request->entity));
                $failed = $runs->where('status', 'failed');
                $left = $source instanceof ZenotiSource ? (int) $source->guestProfilesLeft : 0;
                if ($web && $left > 0 && $failed->isEmpty()) {
                    // Short rounds: queue the rest; the Integrations page (or cron) starts the next round.
                    $fetched = (int) $request->progress + (int) $runs->sum('updated_count');
                    $request->update(['status' => 'queued', 'entity' => 'guests', 'progress' => $fetched,
                        'message' => sprintf('Continuing: %s guest profile(s) fetched so far, %s left.', number_format($fetched), number_format($left))]);
                    $this->line("#{$request->id} continues: $left guest profile(s) left");

                    continue;
                }
                $request->update([
                    'status' => $failed->isEmpty() ? 'done' : 'failed',
                    'message' => sprintf('%d step(s): %d new, %d updated%s', $runs->count(), $runs->sum('created_count'), $runs->sum('updated_count'),
                        $failed->isEmpty() ? '' : '. Failed: '.$failed->map(fn ($r) => $r->entity.' ('.str($r->message)->limit(120).')')->implode('; ')),
                    'finished_at' => now(),
                ]);
            } catch (\App\Services\Integrations\SyncCancelled $e) {
                $request->update(['status' => 'cancelled', 'message' => 'Cancelled.', 'finished_at' => now()]);
            } catch (\Throwable $e) {
                $request->update(['status' => 'failed', 'message' => str($e->getMessage())->limit(500), 'finished_at' => now()]);
            }
            $this->line("#{$request->id} {$request->provider} {$request->status}: {$request->message}");
        }
    }
}

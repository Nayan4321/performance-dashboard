<?php

namespace App\Console\Commands;

use App\Models\SyncRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * The automatic sync, run by the server's cron every 5 minutes whether or not anyone has the site open.
 * Order (Nayan, 2026-10-18): the Call agents' data first, then everything else.
 * One run at a time; each step that fails is logged and the next one still runs.
 */
class AutoSyncAll extends Command
{
    protected $signature = 'integrations:auto-sync';

    protected $description = 'Automatic sync: Call agents\' data first (sales, appointments, their clients, calls), then everything else';

    /** Cache key with the last finished run: time and one line per step. */
    public const LAST_RUN = 'autosync.cron.last_run';

    /**
     * [name, command arguments, seconds between runs (0 = every run), Zenoti step]. Call agents' data comes first.
     * Zenoti can't filter sales or appointments by employee, so the first steps pull what the agents'
     * numbers are made of (invoices they create, appointments they book, their clients' profiles),
     * then their calls, and only then the data the agents don't need.
     */
    public static function steps(): array
    {
        $agents = \App\Models\Tag::AGENTS;

        return [
            // 1. Call agents first.
            ['Call agents: sales (revenue)', ['provider' => 'zenoti', '--entity' => 'sales'], 0, true],
            // Invoices close days after the sale (closing decides what counts), so two weeks are re-read every 3 hours.
            ['Call agents: sales, last 14 days re-check', ['provider' => 'zenoti', '--entity' => 'sales', '--days' => 14], 3 * 3600, true],
            ['Call agents: appointments booked', ['provider' => 'zenoti', '--entity' => 'appointments'], 0, true],
            ['Call agents: their clients\' profiles', ['provider' => 'zenoti', '--entity' => 'guests', '--tag' => $agents], 0, true],
            ['Call agents: calls (CallGear)', ['provider' => 'callgear'], 0, false],
            // 2. Then everything else.
            ['Other clients\' profiles', ['provider' => 'zenoti', '--entity' => 'guests'], 900, true],
            ['Leads', ['provider' => 'zenoti', '--entity' => 'leads'], 900, true],
            ['Employees', ['provider' => 'zenoti', '--entity' => 'employees'], 3600, true],
            ['Branches', ['provider' => 'zenoti', '--entity' => 'centers'], 86400, true],
        ];
    }

    public function handle(): int
    {
        @set_time_limit(0);
        $lock = Cache::lock('integrations:auto-sync', 3 * 3600);
        if (! $lock->get()) {
            $this->line('The previous automatic sync is still running.');

            return self::SUCCESS;
        }
        $started = now();
        $lines = [];
        try {
            foreach (self::steps() as [$name, $args, $every, $zenoti]) {
                // A "Sync now" from Integrations is already pulling Zenoti; don't run two Zenoti syncs at once (60 calls/min).
                if ($zenoti && SyncRequest::where('status', 'running')->where('provider', 'zenoti')->exists()) {
                    $lines[] = "$name: skipped, a Sync now is running";

                    continue;
                }
                if ($every && ! Cache::add('autosync.cron.step.'.md5($name), true, $every - 60)) {
                    continue;
                }
                $t = microtime(true);
                try {
                    Artisan::call('integrations:sync', $args);
                    $out = trim(Artisan::output());
                    $lines[] = sprintf('%s: done in %ds%s', $name, microtime(true) - $t, $out !== '' ? ' ('.str_replace("\n", '; ', $out).')' : '');
                } catch (\Throwable $e) {
                    report($e);
                    $lines[] = "$name: FAILED: ".$e->getMessage();
                }
                $this->line(end($lines));
            }
        } finally {
            Cache::forever(self::LAST_RUN, ['started' => $started->toIso8601String(), 'finished' => now()->toIso8601String(), 'steps' => $lines]);
            $lock->release();
        }

        return self::SUCCESS;
    }

    public static function lastRun(): ?array
    {
        return Cache::get(self::LAST_RUN);
    }
}

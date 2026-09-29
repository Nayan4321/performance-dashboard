<?php

namespace App\Support;

use App\Models\SyncRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Automatic syncs without cron. The same jobs as routes/console.php, started by a web address
 * (an outside pinger such as cron-job.org, or hPanel cron with curl) and by people using the site.
 * Each job runs at most once per its interval; one tick at a time.
 */
class AutoSync
{
    public const LAST_TICK = 'autosync.last_tick';

    /** job => [artisan command, arguments, seconds between runs] */
    private const JOBS = [
        'callgear' => ['integrations:sync', ['provider' => 'callgear'], 300],
        'appointments' => ['integrations:sync', ['provider' => 'zenoti', '--entity' => 'appointments'], 300],
        'sales' => ['integrations:sync', ['provider' => 'zenoti', '--entity' => 'sales'], 300],
        'guests' => ['integrations:sync', ['provider' => 'zenoti', '--entity' => 'guests'], 900],
        'leads' => ['integrations:sync', ['provider' => 'zenoti', '--entity' => 'leads'], 900],
        'employees' => ['integrations:sync', ['provider' => 'zenoti', '--entity' => 'employees'], 3600],
        'centers' => ['integrations:sync', ['provider' => 'zenoti', '--entity' => 'centers'], 86400],
    ];

    public static function token(): string
    {
        return (string) (env('CRON_TOKEN') ?: substr(hash_hmac('sha256', 'autosync', (string) config('app.key')), 0, 32));
    }

    public static function lastTick(): ?\Illuminate\Support\Carbon
    {
        $at = Cache::get(self::LAST_TICK);

        return $at ? \Illuminate\Support\Carbon::parse($at) : null;
    }

    /** Is a tick due (nothing ran in the last few minutes)? */
    public static function due(): bool
    {
        $cron = \App\Console\Commands\RunSyncRequests::lastBeat();
        if ($cron && $cron->gt(now()->subMinutes(3))) {
            return false; // cron works, it does the scheduled syncs
        }
        $last = self::lastTick();

        return ! $last || $last->lt(now()->subMinutes(4));
    }

    /** Run whatever is due. Returns the jobs that ran. */
    public static function tick(): array
    {
        @set_time_limit(0);
        ignore_user_abort(true);
        $lock = Cache::lock('autosync.tick', 600);
        if (! $lock->get()) {
            return [];
        }
        $ran = [];
        try {
            Cache::forever(self::LAST_TICK, now()->toIso8601String());
            // The host stops long web requests, so guest profiles go in short rounds here too.
            config(['zenoti.guest_details_seconds' => min((int) config('zenoti.guest_details_seconds'), (int) config('zenoti.web_guest_seconds', 90))]);
            // "Sync now" requests first, in short rounds.
            Artisan::call('integrations:run-requests', ['--web' => true]);
            if (SyncRequest::whereIn('status', ['running', 'queued'])->exists()) {
                return ['requests'];
            }
            foreach (self::JOBS as $name => [$command, $args, $every]) {
                if (! Cache::add("autosync.job.$name", true, $every - 30)) {
                    continue;
                }
                try {
                    Artisan::call($command, $args);
                } catch (\Throwable $e) {
                    report($e);
                }
                $ran[] = $name;
                Cache::forever(self::LAST_TICK, now()->toIso8601String());
            }
        } finally {
            $lock->release();
        }

        return $ran;
    }
}

<?php

namespace App\Services\Integrations;

use App\Models\SyncRun;
use Throwable;

/** Wraps a sync step in a SyncRun row so every run is visible on the Integrations page. */
class SyncRecorder
{
    /** A "running" row not touched for this long belongs to a process the server killed. */
    public const STALE_MINUTES = 15;

    private static ?SyncRun $current = null;

    /** Set by the "Sync now" runner: returns true once Cancel was pressed. */
    public static $stopCheck = null;

    private static float $lastCheck = 0;

    /** Stop between steps if Cancel was pressed (checked at most every few seconds). */
    public static function checkStop(bool $force = false): void
    {
        if (! self::$stopCheck || (! $force && microtime(true) - self::$lastCheck < 5)) {
            return;
        }
        self::$lastCheck = microtime(true);
        if ((self::$stopCheck)()) {
            throw new SyncCancelled('Cancelled on the Integrations page.');
        }
    }

    public static function run(string $provider, string $entity, callable $work): SyncRun
    {
        self::clearStale();
        self::checkStop(true);
        $run = self::$current = SyncRun::create(['provider' => $provider, 'entity' => $entity, 'status' => 'running', 'started_at' => now()]);

        try {
            $counts = $work() ?? [];
            $run->fill([
                'status' => 'success',
                'created_count' => $counts['created'] ?? 0,
                'updated_count' => $counts['updated'] ?? 0,
                'deactivated_count' => $counts['deactivated'] ?? 0,
                'message' => $counts['message'] ?? null,
            ]);
        } catch (SyncCancelled $e) {
            $run->fill(['status' => 'failed', 'message' => 'Cancelled.', 'finished_at' => now()])->save();
            self::$current = null;
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $run->fill(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 2000)]);
        }

        $run->finished_at = now();
        $run->save();
        self::$current = null;

        return $run;
    }

    /** Show where a long sync has got to (also proves it is still alive). */
    public static function progress(string $message): void
    {
        self::checkStop();
        self::$current?->forceFill(['message' => mb_substr($message, 0, 2000)])->save();
    }

    public static function clearStale(): int
    {
        return SyncRun::where('status', 'running')->where('updated_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->update(['status' => 'failed', 'finished_at' => now(), 'message' => 'Stopped without finishing: the server ended the process (usually a browser or time limit). Run it again with Sync now.']);
    }
}

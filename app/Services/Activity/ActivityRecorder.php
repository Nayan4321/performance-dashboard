<?php

namespace App\Services\Activity;

use App\Models\ActivityLog;
use App\Models\User;
use App\Notifications\RecordDeletedInZenoti;
use Illuminate\Support\Facades\Notification;

/** Writes employee activity and alerts admins about deletions. */
class ActivityRecorder
{
    /** Per process, so one big sync can't send hundreds of alerts. */
    public const MAX_ALERTS_PER_RUN = 50;

    private static int $alerts = 0;

    public static function record(array $attributes): ActivityLog
    {
        $log = ActivityLog::create($attributes + ['occurred_at' => now(), 'source' => 'sync']);

        if ($log->isDeletion() && self::$alerts < self::MAX_ALERTS_PER_RUN) {
            self::$alerts++;
            $admins = User::role([User::SUPER_ADMIN, User::MANAGEMENT])->where('is_active', true)->get();
            Notification::send($admins, new RecordDeletedInZenoti($log->load(['actor', 'branch'])));
        }

        return $log;
    }

    public static function resetAlertCount(): void
    {
        self::$alerts = 0;
    }
}

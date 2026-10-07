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

    private static int $callgearAlerts = 0;

    /** What a Callgear admin hears about her team. */
    public const CALLGEAR_ALERT_ACTIONS = ['deleted', 'guest_deleted', 'cancelled', 'no-show'];

    public static function record(array $attributes): ActivityLog
    {
        $log = ActivityLog::create($attributes + ['occurred_at' => now(), 'source' => 'sync']);

        if ($log->isDeletion() && self::$alerts < self::MAX_ALERTS_PER_RUN) {
            self::$alerts++;
            $admins = User::role([User::SUPER_ADMIN, User::MANAGEMENT])->where('is_active', true)->get();
            Notification::send($admins, new RecordDeletedInZenoti($log->load(['actor', 'branch'])));
        }

        if (in_array($log->action, self::CALLGEAR_ALERT_ACTIONS, true) && self::$callgearAlerts < self::MAX_ALERTS_PER_RUN) {
            $team = \App\Models\Employee::callgearIds();
            if (array_intersect(array_filter([$log->actor_employee_id, $log->employee_id]), $team)) {
                $leads = User::role(User::CALLGEAR_ADMIN)->where('is_active', true)->get()->reject(fn ($u) => $u->seesEverything());
                if ($leads->isNotEmpty()) {
                    self::$callgearAlerts++;
                    Notification::send($leads, new RecordDeletedInZenoti($log->load(['actor', 'branch'])));
                }
            }
        }

        return $log;
    }

    public static function resetAlertCount(): void
    {
        self::$alerts = 0;
        self::$callgearAlerts = 0;
    }
}

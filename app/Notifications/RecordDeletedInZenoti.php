<?php

namespace App\Notifications;

use App\Models\ActivityLog;
use Illuminate\Notifications\Notification;

/** Shown under the bell for admins when an appointment or guest disappears from Zenoti. */
class RecordDeletedInZenoti extends Notification
{
    public function __construct(public ActivityLog $log) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $log = $this->log;
        $who = $log->actor?->full_name ?? $log->actor_name;

        return [
            'activity_id' => $log->id,
            'title' => $log->label().($who ? ' by '.$who : ''),
            'body' => trim($log->subject_label.($log->branch ? ' · '.$log->branch->name : '')),
            'employee_id' => $log->actor_employee_id ?? $log->employee_id,
        ];
    }
}

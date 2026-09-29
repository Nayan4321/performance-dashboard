<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Items for the topbar bell, grouped into tabs:
 * Alerts (deletions sent to admins), Activity (recent Zenoti changes the user may see)
 * and System (failed syncs, for people who manage integrations).
 */
class NotificationFeed
{
    public const PER_TAB = 8;

    public static function for(User $user): array
    {
        $tabs = [
            'alerts' => ['label' => 'Alerts', 'items' => static::alerts($user)],
            'activity' => ['label' => 'Activity', 'items' => static::activity($user)],
        ];
        if ($user->can('integrations.manage')) {
            $tabs['system'] = ['label' => 'System', 'items' => static::system()];
        }

        $all = collect($tabs)->flatMap(fn ($t) => $t['items'])->sortByDesc('at')->take(self::PER_TAB)->values();

        return [
            'unread' => $user->unreadNotifications()->count(),
            'tabs' => ['all' => ['label' => 'All', 'items' => $all]] + $tabs,
        ];
    }

    protected static function alerts(User $user): Collection
    {
        return $user->notifications()->latest()->limit(self::PER_TAB)->get()->map(fn ($n) => [
            'icon' => 'iconoir-trash', 'tone' => 'danger',
            'title' => $n->data['title'] ?? 'Notification',
            'body' => $n->data['body'] ?? '',
            'at' => $n->created_at,
            'unread' => $n->read_at === null,
            'url' => ! empty($n->data['employee_id']) ? route('employees.show', $n->data['employee_id']) : route('notifications'),
        ]);
    }

    protected static function activity(User $user): Collection
    {
        if (! $user->can('dashboards.view') || ! $user->canAccessModule('performance') || $user->callgearOnly()) {
            return collect();
        }
        $allowed = $user->visibleBranchIds();
        $own = $user->visibleEmployeeId();
        $icons = ['booked' => ['iconoir-calendar', 'success'], 'cancelled' => ['iconoir-xmark-circle', 'warning'], 'no-show' => ['iconoir-warning-triangle', 'warning'],
            'deleted' => ['iconoir-trash', 'danger'], 'guest_deleted' => ['iconoir-trash', 'danger'], 'restored' => ['iconoir-check-circle', 'success']];

        return ActivityLog::with(['actor', 'branch'])
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed ?: [0]))
            ->when($own !== null, fn ($q) => $q->where(fn ($w) => $w->where('actor_employee_id', $own)->orWhere('employee_id', $own)))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(self::PER_TAB)->get()
            ->map(function (ActivityLog $log) use ($icons) {
                [$icon, $tone] = $icons[$log->action] ?? ['iconoir-edit-pencil', 'primary'];
                $who = $log->actor?->full_name ?? $log->actor_name;

                return [
                    'icon' => $icon, 'tone' => $tone,
                    'title' => $log->label().($who ? ' · '.$who : ''),
                    'body' => trim($log->subject_label.($log->branch ? ' · '.$log->branch->name : '')),
                    'at' => $log->occurred_at ?? $log->created_at,
                    'unread' => false,
                    'url' => route('activity.index', ['action' => $log->action]),
                ];
            });
    }

    protected static function system(): Collection
    {
        return SyncRun::where('status', 'failed')->where('created_at', '>=', now()->subDays(3))
            ->latest()->limit(self::PER_TAB)->get()->map(fn (SyncRun $run) => [
                'icon' => 'iconoir-refresh-double', 'tone' => 'warning',
                'title' => ucfirst($run->provider).' '.str_replace('_', ' ', $run->entity).' sync failed',
                'body' => \Illuminate\Support\Str::limit((string) $run->message, 90),
                'at' => $run->finished_at ?? $run->created_at,
                'unread' => false,
                'url' => route('admin.integrations.index'),
            ]);
    }
}

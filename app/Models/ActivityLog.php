<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public const LABELS = [
        'booked' => 'Booked appointment',
        'changed' => 'Changed appointment',
        'cancelled' => 'Cancelled appointment',
        'no-show' => 'Marked no-show',
        'deleted' => 'Deleted appointment',
        'restored' => 'Restored appointment',
        'guest_changed' => 'Updated guest',
        'guest_deleted' => 'Deleted guest',
    ];

    protected $fillable = [
        'branch_id', 'actor_employee_id', 'actor_name', 'employee_id', 'subject_type', 'subject_id', 'subject_label',
        'action', 'changes', 'source', 'occurred_at',
    ];

    protected $casts = ['changes' => 'array', 'occurred_at' => 'datetime'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'actor_employee_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isDeletion(): bool
    {
        return in_array($this->action, ['deleted', 'guest_deleted'], true);
    }

    public function label(): string
    {
        return self::LABELS[$this->action] ?? ucfirst(str_replace('_', ' ', $this->action));
    }
}

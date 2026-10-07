<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    /** Records removed in Zenoti stay for the activity history but drop out of lists and dashboards. */
    protected static function booted(): void
    {
        static::addGlobalScope('live', fn ($q) => $q->whereNull($q->getModel()->getTable().'.deleted_in_zenoti_at'));
    }

    /** Same groups as Zenoti's "Appointments by status" chart. */
    public const STATUSES = ['Serviced', 'In-progress', 'Open & confirmed', 'No-show', 'Cancelled'];

    protected $fillable = [
        'branch_id', 'employee_id', 'booked_by_employee_id', 'guest_id', 'zenoti_id', 'service_name', 'status', 'raw_status', 'price', 'start_time', 'end_time', 'booked_at', 'raw', 'deleted_in_zenoti_at',
    ];

    protected $casts = ['deleted_in_zenoti_at' => 'datetime', 'raw' => 'array', 'start_time' => 'datetime', 'end_time' => 'datetime', 'booked_at' => 'datetime', 'price' => 'decimal:2'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Who created the appointment in Zenoti (booked by). */
    public function bookedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class, 'booked_by_employee_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}

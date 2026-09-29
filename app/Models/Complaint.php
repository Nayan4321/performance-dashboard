<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Complaint extends Model
{
    public const STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved'];

    /** From the complaint handling protocol (Internal Management Framework). */
    public const CATEGORIES = [
        'A' => 'A: Minor / miscommunication',
        'B' => 'B: Service touch-up',
        'C' => 'C: Validated error',
        'D' => 'D: Major escalation',
    ];

    protected $fillable = [
        'call_id', 'employee_id', 'guest_id', 'branch_id', 'created_by', 'phone', 'category', 'message',
        'status', 'resolution', 'resolved_at', 'resolved_by', 'source', 'external_id', 'called_at',
    ];

    protected $casts = ['called_at' => 'datetime', 'resolved_at' => 'datetime'];

    public function number(): string
    {
        return 'C-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Zenoti guest for this caller: the call's guest, else a guest with the same phone number. */
    public static function guestForPhone(?string $phone): ?Guest
    {
        $key = Guest::phoneKey($phone);

        return $key ? Guest::where('phone_key', $key)->latest('id')->first() : null;
    }

    /** What Zenoti tells us about the client: visits, spend, last and next appointment. */
    public function clientSummary(): ?array
    {
        if (! $this->guest) {
            return null;
        }
        $appts = Appointment::where('guest_id', $this->guest_id);

        return [
            'visits' => (clone $appts)->where('start_time', '<=', now())->whereNotIn('status', ['Cancelled', 'No-show'])->count(),
            'last_visit' => (clone $appts)->where('start_time', '<=', now())->whereNotIn('status', ['Cancelled', 'No-show'])->max('start_time'),
            'next_visit' => (clone $appts)->where('start_time', '>', now())->whereNotIn('status', ['Cancelled'])->orderBy('start_time')->first(['start_time', 'service_name', 'branch_id']),
            'spend' => (float) Sale::where('guest_id', $this->guest_id)->sum('net_amount'),
            'no_shows' => (clone $appts)->where('status', 'No-show')->count(),
            'complaints' => static::where('guest_id', $this->guest_id)->count(),
        ];
    }
}

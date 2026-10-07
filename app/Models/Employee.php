<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'user_id', 'zenoti_id', 'callgear_id', 'first_name', 'last_name',
        'email', 'phone', 'job_title', 'source', 'is_active', 'raw', 'synced_at', 'salary', 'target_multiplier', 'section',
    ];

    protected $casts = ['is_active' => 'boolean', 'raw' => 'array', 'synced_at' => 'datetime'];

    /** Grand Flora rule: monthly target = salary × multiplier (fixed at 7 unless set per employee). */
    public const DEFAULT_MULTIPLIER = 7;

    public const SECTIONS = ['Hair', 'Nail', 'Body Care', 'Spa', 'Makeup'];

    public function monthlyTarget(): ?float
    {
        return $this->salary ? round((float) $this->salary * (float) ($this->target_multiplier ?: self::DEFAULT_MULTIPLIER), 2) : null;
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ids of employees tagged "Call agents" (the call-center team). */
    public static function callgearIds(): array
    {
        return static::whereHas('tags', fn ($t) => $t->whereRaw('LOWER(name) = ?', [mb_strtolower(Tag::AGENTS)]))->pluck('id')->all();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->orderBy('name');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** Appointments this employee booked in Zenoti (for anyone). */
    public function bookedAppointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'booked_by_employee_id');
    }

    /** Sales lines on invoices this employee entered in Zenoti. */
    public function enteredSales(): HasMany
    {
        return $this->hasMany(Sale::class, 'created_by_employee_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }
}

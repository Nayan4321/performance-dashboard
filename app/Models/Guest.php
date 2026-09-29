<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest extends Model
{

    protected $fillable = [
        'branch_id', 'zenoti_id', 'first_name', 'last_name', 'email', 'phone', 'gender', 'source', 'raw', 'registered_at', 'profile_synced_at', 'deleted_in_zenoti_at',
    ];

    protected $casts = ['deleted_in_zenoti_at' => 'datetime', 'raw' => 'array', 'registered_at' => 'datetime', 'profile_synced_at' => 'datetime', 'profile_failed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(fn (Guest $g) => $g->phone_key = static::phoneKey($g->phone));
        // Guests removed in Zenoti stay for the activity history but drop out of lists and dashboards.
        static::addGlobalScope('live', fn ($q) => $q->whereNull('guests.deleted_in_zenoti_at'));
    }

    /** National number without +971 / 00971 / trunk 0, so 971556555675, 0556555675 and 55 655 5675 all match. */
    public static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        $digits = preg_replace('/^(00)?971/', '', $digits);
        $digits = ltrim($digits, '0');

        return strlen($digits) >= 7 ? substr($digits, -12) : null;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** This guest's profile page on the Zenoti website. */
    public function getZenotiUrlAttribute(): ?string
    {
        $base = config('zenoti.web_url');

        return $base && $this->zenoti_id && $this->source === 'zenoti'
            ? $base.'/Guests/GuestProfileV2/GuestProfileV2.aspx?UserId='.urlencode($this->zenoti_id) : null;
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name) ?: '—';
    }
}

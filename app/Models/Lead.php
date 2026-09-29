<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    public const STAGES = ['new', 'contacted', 'booked', 'visited', 'converted', 'lost'];

    protected $fillable = [
        'branch_id', 'employee_id', 'guest_id', 'source', 'external_id', 'name', 'phone', 'email', 'channel',
        'stage', 'value', 'lead_at', 'raw',
    ];

    protected $casts = ['raw' => 'array', 'lead_at' => 'datetime'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

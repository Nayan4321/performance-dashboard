<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Call extends Model
{
    protected $fillable = [
        'branch_id', 'employee_id', 'guest_id', 'external_id', 'direction', 'status', 'caller', 'callee',
        'duration_seconds', 'wait_seconds', 'started_at', 'raw',
    ];

    protected $casts = ['raw' => 'array', 'started_at' => 'datetime'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

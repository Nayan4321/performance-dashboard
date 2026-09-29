<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment received (Zenoti "collections"). */
class Collection extends Model
{
    protected $fillable = [
        'branch_id', 'employee_id', 'guest_id', 'zenoti_id', 'invoice_no', 'payment_type', 'amount', 'collected_at', 'raw',
    ];

    protected $casts = ['raw' => 'array', 'collected_at' => 'datetime'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}

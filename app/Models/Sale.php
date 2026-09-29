<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sale extends Model
{
    public const CATEGORIES = ['Service', 'Product', 'Package', 'Gift card', 'Membership', 'Prepaid card', 'Other'];

    /** Zenoti counts these as "Liabilities sold" (paid now, redeemed later). */
    public const LIABILITIES = ['Package', 'Gift card', 'Membership', 'Prepaid card'];

    protected $fillable = [
        'branch_id', 'employee_id', 'created_by_employee_id', 'guest_id', 'zenoti_id', 'invoice_no', 'item_type', 'category', 'item_name', 'status',
        'quantity', 'gross_amount', 'discount', 'net_amount', 'sold_at', 'raw',
    ];

    protected $casts = ['raw' => 'array', 'sold_at' => 'datetime'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

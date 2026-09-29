<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = ['invoice_number', 'stock_order_id', 'generated_by', 'subtotal', 'tax_total', 'total', 'issued_on'];

    protected $casts = ['issued_on' => 'date'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(StockOrder::class, 'stock_order_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}

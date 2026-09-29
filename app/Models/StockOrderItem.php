<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOrderItem extends Model
{
    protected $fillable = [
        'stock_order_id', 'product_id', 'product_name', 'quantity', 'unit_price', 'tax_rate', 'line_subtotal', 'line_tax', 'line_total',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(StockOrder::class, 'stock_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

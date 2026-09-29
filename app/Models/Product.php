<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['product_category_id', 'sku', 'name', 'unit', 'unit_price', 'tax_rate', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'unit_price' => 'decimal:2', 'tax_rate' => 'decimal:2'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(BranchStock::class);
    }
}

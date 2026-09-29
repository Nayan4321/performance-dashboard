<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected $fillable = [
        'organization_id', 'name', 'code', 'zenoti_center_id', 'callgear_site_id',
        'address', 'phone', 'is_warehouse', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean', 'is_warehouse' => 'boolean'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(BranchStock::class);
    }
}

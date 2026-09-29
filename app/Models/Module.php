<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Module extends Model
{
    public const PERFORMANCE = 'performance';
    public const DASHBOARDS = 'dashboards';
    public const CALLS = 'calls';
    public const INVENTORY = 'inventory';

    protected $fillable = ['key', 'name', 'description', 'is_global'];

    protected $casts = ['is_global' => 'boolean'];

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('allowed');
    }
}

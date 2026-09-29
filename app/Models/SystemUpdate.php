<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemUpdate extends Model
{
    protected $fillable = [
        'user_id', 'filename', 'version', 'status', 'files', 'new_files', 'zip_path', 'backup_path', 'output', 'applied_at',
    ];

    protected $casts = ['files' => 'array', 'new_files' => 'array', 'applied_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

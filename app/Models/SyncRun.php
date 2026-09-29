<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    protected $fillable = [
        'provider', 'entity', 'status', 'created_count', 'updated_count', 'deactivated_count', 'message', 'started_at', 'finished_at',
    ];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];
}

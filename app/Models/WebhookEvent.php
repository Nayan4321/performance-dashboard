<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    protected $fillable = ['provider', 'event_type', 'payload', 'status', 'error', 'processed_at'];

    protected $casts = ['payload' => 'array', 'processed_at' => 'datetime'];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncRequest extends Model
{
    protected $fillable = ['provider', 'entity', 'days', 'branch_id', 'tag', 'status', 'requested_by', 'message', 'progress', 'started_at', 'finished_at'];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** "Juhu, tag Callgear" for the requests table. */
    public function scopeLabel(): string
    {
        return collect([$this->branch?->name, $this->tag ? 'tag '.$this->tag : null])->filter()->implode(', ');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running', 'cancelling'], true);
    }
}

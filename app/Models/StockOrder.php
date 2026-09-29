<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockOrder extends Model
{
    public const SUBMITTED = 'submitted';
    public const APPROVAL_IN_PROCESS = 'approval_in_process';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const PAYMENT_REQUESTED = 'payment_requested';
    public const PAYMENT_COMPLETED = 'payment_completed';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::SUBMITTED => 'Submitted',
        self::APPROVAL_IN_PROCESS => 'Approval in process',
        self::APPROVED => 'Approved',
        self::REJECTED => 'Rejected',
        self::PAYMENT_REQUESTED => 'Payment requested',
        self::PAYMENT_COMPLETED => 'Payment completed',
        self::COMPLETED => 'Completed',
        self::CANCELLED => 'Cancelled',
    ];

    public const STATUS_COLORS = [
        self::SUBMITTED => 'secondary',
        self::APPROVAL_IN_PROCESS => 'info',
        self::APPROVED => 'primary',
        self::REJECTED => 'danger',
        self::PAYMENT_REQUESTED => 'warning',
        self::PAYMENT_COMPLETED => 'success',
        self::COMPLETED => 'dark',
        self::CANCELLED => 'light',
    ];

    /** Allowed transitions for the stock manager (inventory.orders.approve). */
    public const TRANSITIONS = [
        self::SUBMITTED => [self::APPROVAL_IN_PROCESS, self::APPROVED, self::REJECTED],
        self::APPROVAL_IN_PROCESS => [self::APPROVED, self::REJECTED],
        self::APPROVED => [self::PAYMENT_REQUESTED, self::PAYMENT_COMPLETED, self::COMPLETED, self::REJECTED],
        self::PAYMENT_REQUESTED => [self::PAYMENT_COMPLETED, self::REJECTED],
        self::PAYMENT_COMPLETED => [self::COMPLETED],
        self::REJECTED => [],
        self::COMPLETED => [],
        self::CANCELLED => [],
    ];

    /** Statuses from which an invoice may be generated. */
    public const INVOICEABLE = [self::APPROVED, self::PAYMENT_REQUESTED, self::PAYMENT_COMPLETED, self::COMPLETED];

    protected $fillable = [
        'order_number', 'requesting_branch_id', 'supplying_branch_id', 'requested_by', 'approved_by', 'status',
        'subtotal', 'tax_total', 'total', 'notes', 'status_note', 'approved_at', 'completed_at',
    ];

    protected $casts = ['approved_at' => 'datetime', 'completed_at' => 'datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(StockOrderItem::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(StockOrderStatusLog::class)->latest('id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function requestingBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'requesting_branch_id');
    }

    public function supplyingBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'supplying_branch_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function statusColor(): string
    {
        return self::STATUS_COLORS[$this->status] ?? 'secondary';
    }

    public function nextStatuses(): array
    {
        return self::TRANSITIONS[$this->status] ?? [];
    }

    public function isInvoiceable(): bool
    {
        return in_array($this->status, self::INVOICEABLE, true);
    }

    /** Orders the user may see: everything for approvers, own branch otherwise. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->seesEverything() || $user->can('inventory.orders.approve') || $user->can('inventory.orders.view-all')) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where('requesting_branch_id', $user->branch_id)->orWhere('requested_by', $user->id));
    }
}

<?php

namespace App\Services\Inventory;

use App\Models\BranchStock;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\StockOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockOrderService
{
    /**
     * Create an order. Prices always come from the product catalogue, never from the browser.
     *
     * @param  array<int, array{product_id:int, quantity:numeric}>  $lines
     */
    public function create(User $user, int $requestingBranchId, ?int $supplyingBranchId, array $lines, ?string $notes): StockOrder
    {
        $lines = collect($lines)->filter(fn ($l) => ! empty($l['product_id']) && (float) ($l['quantity'] ?? 0) > 0);
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Add at least one product with a quantity.']);
        }

        $products = Product::where('is_active', true)->whereIn('id', $lines->pluck('product_id'))->get()->keyBy('id');

        return DB::transaction(function () use ($user, $requestingBranchId, $supplyingBranchId, $lines, $notes, $products) {
            $order = StockOrder::create([
                'order_number' => $this->nextNumber('SO', StockOrder::class, 'order_number'),
                'requesting_branch_id' => $requestingBranchId,
                'supplying_branch_id' => $supplyingBranchId,
                'requested_by' => $user->id,
                'status' => StockOrder::SUBMITTED,
                'notes' => $notes,
            ]);

            foreach ($lines as $line) {
                $product = $products->get($line['product_id']);
                if (! $product) {
                    throw ValidationException::withMessages(['items' => 'One of the selected products is no longer available.']);
                }
                $qty = round((float) $line['quantity'], 2);
                $sub = round($qty * (float) $product->unit_price, 2);
                $tax = round($sub * (float) $product->tax_rate / 100, 2);
                $order->items()->create([
                    'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $qty,
                    'unit_price' => $product->unit_price, 'tax_rate' => $product->tax_rate,
                    'line_subtotal' => $sub, 'line_tax' => $tax, 'line_total' => $sub + $tax,
                ]);
            }

            $this->recalculate($order);
            $order->logs()->create(['user_id' => $user->id, 'to_status' => StockOrder::SUBMITTED, 'note' => 'Order requested']);

            return $order;
        });
    }

    public function recalculate(StockOrder $order): void
    {
        $order->load('items');
        $order->update([
            'subtotal' => $order->items->sum('line_subtotal'),
            'tax_total' => $order->items->sum('line_tax'),
            'total' => $order->items->sum('line_total'),
        ]);
    }

    public function transition(StockOrder $order, string $to, User $user, ?string $note = null): StockOrder
    {
        $allowed = $to === StockOrder::CANCELLED ? [StockOrder::SUBMITTED, StockOrder::APPROVAL_IN_PROCESS] : null;
        $valid = $allowed ? in_array($order->status, $allowed, true) : in_array($to, $order->nextStatuses(), true);
        if (! $valid) {
            throw ValidationException::withMessages(['status' => "Cannot move an order from \"{$order->statusLabel()}\" to \"".(StockOrder::STATUS_LABELS[$to] ?? $to).'".']);
        }

        return DB::transaction(function () use ($order, $to, $user, $note) {
            $from = $order->status;
            $changes = ['status' => $to, 'status_note' => $note];
            if ($to === StockOrder::APPROVED) {
                $changes += ['approved_by' => $user->id, 'approved_at' => now()];
            }
            if ($to === StockOrder::COMPLETED) {
                $changes['completed_at'] = now();
                $this->moveStock($order);
            }
            $order->update($changes);
            $order->logs()->create(['user_id' => $user->id, 'from_status' => $from, 'to_status' => $to, 'note' => $note]);

            return $order;
        });
    }

    /** On completion the goods leave the supplying branch (if any) and arrive at the requesting branch. */
    protected function moveStock(StockOrder $order): void
    {
        foreach ($order->items as $item) {
            if ($order->supplying_branch_id) {
                $src = BranchStock::firstOrCreate(['branch_id' => $order->supplying_branch_id, 'product_id' => $item->product_id]);
                $src->decrement('quantity', $item->quantity);
            }
            $dst = BranchStock::firstOrCreate(['branch_id' => $order->requesting_branch_id, 'product_id' => $item->product_id]);
            $dst->increment('quantity', $item->quantity);
        }
    }

    public function generateInvoice(StockOrder $order, User $user): Invoice
    {
        if (! $order->isInvoiceable()) {
            throw ValidationException::withMessages(['invoice' => 'An invoice can be generated once the order is approved.']);
        }

        return $order->invoice ?? $order->invoice()->create([
            'invoice_number' => $this->nextNumber('INV', Invoice::class, 'invoice_number'),
            'generated_by' => $user->id,
            'subtotal' => $order->subtotal,
            'tax_total' => $order->tax_total,
            'total' => $order->total,
            'issued_on' => now()->toDateString(),
        ]);
    }

    /** e.g. SO-202609-0001, restarting each month. */
    protected function nextNumber(string $prefix, string $model, string $column): string
    {
        $stem = $prefix.'-'.now()->format('Ym').'-';
        $last = $model::where($column, 'like', $stem.'%')->lockForUpdate()->orderByDesc($column)->value($column);
        $n = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $stem.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}

@extends('layouts.app')
@section('title', $order->order_number)
@section('content')
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Order {{ $order->order_number }}</h1>
    <span class="badge fs-6 text-bg-{{ $order->statusColor() }} border">{{ $order->statusLabel() }}</span>
    <div class="ms-auto d-flex gap-2">
        @if($order->invoice)
            <a href="{{ route('inventory.invoices.show', $order->invoice) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-receipt"></i> Invoice {{ $order->invoice->invoice_number }}</a>
        @elseif($order->isInvoiceable())
            @can('inventory.invoices.generate')
                <form method="post" action="{{ route('inventory.invoices.generate', $order) }}">@csrf<button class="btn btn-sm btn-primary"><i class="bi bi-receipt"></i> Generate invoice</button></form>
            @endcan
        @endif
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-3"><div class="card-body row small">
            <div class="col-sm-3"><div class="text-muted">For branch</div>{{ $order->requestingBranch?->name }}</div>
            <div class="col-sm-3"><div class="text-muted">Supply from</div>{{ $order->supplyingBranch?->name ?? 'Main stock' }}</div>
            <div class="col-sm-3"><div class="text-muted">Requested by</div>{{ $order->requester?->name }}<br>{{ $order->created_at->format('d M Y H:i') }}</div>
            <div class="col-sm-3"><div class="text-muted">Approved by</div>{{ $order->approver?->name ?? '—' }}@if($order->approved_at)<br>{{ $order->approved_at->format('d M Y H:i') }}@endif</div>
            @if($order->notes)<div class="col-12 mt-2"><div class="text-muted">Notes</div>{{ $order->notes }}</div>@endif
        </div></div>
        <div class="card"><div class="table-responsive"><table class="table mb-0">
            <thead><tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Tax</th><th class="text-end">Total</th></tr></thead>
            <tbody>@foreach($order->items as $i)
                <tr><td>{{ $i->product_name }}</td><td class="text-end">{{ $i->quantity + 0 }}</td><td class="text-end">{{ number_format($i->unit_price, 2) }}</td>
                    <td class="text-end">{{ number_format($i->line_tax, 2) }} <span class="text-muted small">({{ $i->tax_rate + 0 }}%)</span></td><td class="text-end">{{ number_format($i->line_total, 2) }}</td></tr>
            @endforeach</tbody>
            <tfoot>
                <tr><td colspan="4" class="text-end">Subtotal</td><td class="text-end">{{ number_format($order->subtotal, 2) }}</td></tr>
                <tr><td colspan="4" class="text-end">Tax</td><td class="text-end">{{ number_format($order->tax_total, 2) }}</td></tr>
                <tr class="fw-bold"><td colspan="4" class="text-end">Total</td><td class="text-end">{{ config('app.currency_symbol') }}{{ number_format($order->total, 2) }}</td></tr>
            </tfoot>
        </table></div></div>
    </div>

    <div class="col-lg-4">
        @can('inventory.orders.approve')
            @if($order->nextStatuses())
            <div class="card mb-3"><div class="card-body">
                <h2 class="h6">Update status</h2>
                <form method="post" action="{{ route('inventory.orders.status', $order) }}">@csrf
                    <select name="status" class="form-select mb-2">@foreach($order->nextStatuses() as $s)<option value="{{ $s }}">{{ \App\Models\StockOrder::STATUS_LABELS[$s] }}</option>@endforeach</select>
                    <textarea name="note" class="form-control mb-2" rows="2" placeholder="Note (required when rejecting)"></textarea>
                    <button class="btn btn-primary w-100">Update</button>
                </form>
            </div></div>
            @endif
        @endcan
        @if(in_array($order->status, ['submitted', 'approval_in_process']) && ($order->requested_by === auth()->id() || auth()->user()->can('inventory.orders.approve')))
            <form method="post" action="{{ route('inventory.orders.cancel', $order) }}" class="mb-3" onsubmit="return confirm('Cancel this order?')">@csrf<button class="btn btn-outline-danger w-100 btn-sm">Cancel order</button></form>
        @endif
        <div class="card"><div class="card-body">
            <h2 class="h6">History</h2>
            <ul class="list-unstyled small mb-0">
            @foreach($order->logs as $log)
                <li class="mb-2 border-start ps-2">
                    <strong>{{ \App\Models\StockOrder::STATUS_LABELS[$log->to_status] ?? $log->to_status }}</strong>
                    <span class="text-muted">· {{ $log->user?->name }} · {{ $log->created_at->format('d M H:i') }}</span>
                    @if($log->note)<div>{{ $log->note }}</div>@endif
                </li>
            @endforeach
            </ul>
        </div></div>
    </div>
</div>
@endsection

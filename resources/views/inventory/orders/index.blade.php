@extends('layouts.app')
@section('title', 'Stock orders')
@section('content')
<div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0">Stock orders</h1>
    @can('inventory.orders.create')<a href="{{ route('inventory.orders.create') }}" class="btn btn-primary btn-sm ms-auto"><i class="bi bi-cart-plus"></i> New order request</a>@endcan
</div>
<ul class="nav nav-pills mb-3 flex-nowrap overflow-auto">
    <li class="nav-item"><a class="nav-link {{ request('status') ? '' : 'active' }}" href="{{ route('inventory.orders.index') }}">All <span class="badge text-bg-light">{{ $counts->sum() }}</span></a></li>
    @foreach(\App\Models\StockOrder::STATUS_LABELS as $k => $label)
        <li class="nav-item"><a class="nav-link text-nowrap {{ request('status') === $k ? 'active' : '' }}" href="{{ route('inventory.orders.index', ['status' => $k]) }}">{{ $label }} <span class="badge text-bg-light">{{ $counts[$k] ?? 0 }}</span></a></li>
    @endforeach
</ul>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th>Order</th><th>Date</th><th>Requested by</th><th>For branch</th><th>From</th><th class="text-end">Total</th><th>Status</th></tr></thead>
    <tbody>
    @forelse($orders as $o)
        <tr onclick="location='{{ route('inventory.orders.show', $o) }}'" style="cursor:pointer">
            <td><a href="{{ route('inventory.orders.show', $o) }}">{{ $o->order_number }}</a></td>
            <td class="small">{{ $o->created_at->format('d M Y') }}</td>
            <td>{{ $o->requester?->name }}</td>
            <td>{{ $o->requestingBranch?->name }}</td>
            <td>{{ $o->supplyingBranch?->name ?? 'Main stock' }}</td>
            <td class="text-end">{{ config('app.currency_symbol') }}{{ number_format($o->total, 2) }}</td>
            <td><span class="badge text-bg-{{ $o->statusColor() }} border">{{ $o->statusLabel() }}</span></td>
        </tr>
    @empty
        <tr><td colspan="7" class="text-center text-muted py-4">No orders.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $orders->links() }}</div>
@endsection

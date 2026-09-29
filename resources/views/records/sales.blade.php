@extends('layouts.app')
@section('title', 'Sales')
@section('content')
@php $cur = config('app.currency_symbol'); @endphp
<div class="d-flex align-items-baseline gap-2 mb-3">
    <h1 class="h4 mb-0">Sales</h1> @include('partials.source-badge', ['source' => 'Zenoti'])
    <span class="text-muted small">{{ $from->format('j M') }} – {{ $to->format('j M Y') }}</span>
</div>
@include('records._filters', ['placeholder' => 'Item or invoice number'])
<div class="row g-2 mb-3">
    <div class="col-6 col-md"><a href="{{ request()->fullUrlWithQuery(['category' => null, 'page' => null]) }}" class="card text-decoration-none h-100 {{ request('category') ? '' : 'border-dark' }}"><div class="card-body py-2"><div class="small text-muted">All sales</div><div class="fs-5">{{ $cur }}{{ number_format($total) }}</div></div></a></div>
    @foreach(\App\Models\Sale::CATEGORIES as $c)
        <div class="col-6 col-md"><a href="{{ request()->fullUrlWithQuery(['category' => $c, 'page' => null]) }}" class="card text-decoration-none h-100 {{ request('category') === $c ? 'border-dark' : '' }}"><div class="card-body py-2">
            <div class="small text-muted">{{ $c }}</div><div class="fs-5">{{ $cur }}{{ number_format($byCategory[$c]->amount ?? 0) }}</div></div></a></div>
    @endforeach
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0">
    <thead><tr><th>Date</th><th>Invoice</th><th>Item</th><th>Category</th><th>Employee</th><th>Branch</th><th class="text-end">Qty</th><th class="text-end">Net</th></tr></thead>
    <tbody>
    @forelse($rows as $s)
        <tr>
            <td class="small text-nowrap">{{ $s->sold_at?->format('j M Y H:i') }}</td>
            <td class="small">{{ $s->invoice_no }}</td>
            <td>{{ $s->item_name }}</td>
            <td><span class="badge text-bg-light border" title="Zenoti: {{ $s->item_type }}">{{ $s->category ?? 'Other' }}</span></td>
            <td>{{ $s->employee?->full_name ?? '—' }}</td>
            <td>{{ $s->branch?->name ?? '—' }}</td>
            <td class="text-end">{{ rtrim(rtrim(number_format($s->quantity, 2), '0'), '.') }}</td>
            <td class="text-end">{{ $cur }}{{ number_format($s->net_amount, 2) }}</td>
        </tr>
    @empty
        <tr><td colspan="8" class="text-center text-muted py-4">No sales in this period. If this stays empty, run a Zenoti sync from Admin › Integrations.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection

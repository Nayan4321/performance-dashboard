@extends('layouts.app')
@section('title', 'Invoices')
@section('content')
@php $cur = config('app.currency_symbol'); @endphp
<div class="d-flex align-items-baseline gap-2 mb-1">
    <h1 class="h4 mb-0">Invoices</h1> @include('partials.source-badge', ['source' => 'Zenoti'])
    <span class="text-muted small">{{ $from->format('j M') }} – {{ $to->format('j M Y') }} · {{ number_format($count) }} invoices</span>
</div>
<p class="text-muted small mb-3">Built from the sales lines synced so far, so new invoices appear here as the sales sync runs. Amount is {{ $column }}.</p>
@include('records._filters', ['placeholder' => 'Invoice number'])
<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0">
    <thead><tr><th>Invoice</th><th>Sale date</th><th>Closed</th><th>Branch</th><th>Status</th><th>Payment</th><th>Invoice created by</th><th>Sold by</th><th>Items</th><th class="text-end">Lines</th><th class="text-end">Net</th><th class="text-end">Amount</th></tr></thead>
    <tbody>
    @forelse($rows as $i)
        <tr>
            <td class="small text-nowrap"><a href="{{ route('sales.index', ['q' => $i->invoice_no, 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">{{ $i->invoice_no }}</a></td>
            <td class="small text-nowrap">{{ \Illuminate\Support\Carbon::parse($i->first_at)->format('j M Y H:i') }}</td>
            <td class="small text-nowrap">{{ $i->closed_at?->format('j M Y') }}</td>
            <td>{{ $i->branch_name ?? '—' }}</td>
            <td class="small">{{ $i->status ?: '—' }}</td>
            <td class="small">{{ $i->payment ?: '—' }}</td>
            <td>{{ $i->created_by ?: '—' }}</td>
            <td class="small">{{ $i->sold_by ?: '—' }}</td>
            <td class="small text-truncate" style="max-width:260px" title="{{ $i->items }}">{{ $i->items }}</td>
            <td class="text-end">{{ $i->line_count }}</td>
            <td class="text-end">{{ $cur }}{{ number_format($i->net, 2) }}</td>
            <td class="text-end">{{ $cur }}{{ number_format($i->amount, 2) }}</td>
        </tr>
    @empty
        <tr><td colspan="12" class="text-center text-muted py-4">No invoices in this period yet. They appear as the Zenoti sales sync brings in lines.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection

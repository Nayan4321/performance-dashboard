@extends('layouts.app')
@section('title', $invoice->invoice_number)
@section('content')
<div class="d-flex gap-2 mb-3 no-print">
    <a href="{{ route('inventory.orders.show', $order) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Order</a>
    <button onclick="print()" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-printer"></i> Print</button>
    <a href="{{ route('inventory.invoices.pdf', $invoice) }}" class="btn btn-sm btn-primary"><i class="bi bi-file-earmark-pdf"></i> Download PDF</a>
</div>
<div class="card"><div class="card-body invoice">
    <style>.invoice .lines th,.invoice .lines td{border-bottom:1px solid #e6e8ec;padding:6px}</style>
    @include('inventory.invoices._body')
</div></div>
@endsection

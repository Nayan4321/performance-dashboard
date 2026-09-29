<table style="width:100%;margin-bottom:20px"><tr>
    <td style="vertical-align:top">
        <h2 style="margin:0">{{ $order->requestingBranch?->organization?->name ?? config('app.name') }}</h2>
        <div>Stock transfer invoice</div>
    </td>
    <td style="text-align:right;vertical-align:top">
        <div><strong>Invoice:</strong> {{ $invoice->invoice_number }}</div>
        <div><strong>Date:</strong> {{ $invoice->issued_on->format('d M Y') }}</div>
        <div><strong>Order:</strong> {{ $order->order_number }}</div>
        <div><strong>Status:</strong> {{ $order->statusLabel() }}</div>
    </td>
</tr></table>
<table style="width:100%;margin-bottom:20px"><tr>
    <td><strong>From</strong><br>{{ $order->supplyingBranch?->name ?? 'Main stock' }}<br>{{ $order->supplyingBranch?->address }}</td>
    <td><strong>To</strong><br>{{ $order->requestingBranch?->name }}<br>{{ $order->requestingBranch?->address }}</td>
    <td><strong>Requested by</strong><br>{{ $order->requester?->name }}<br><strong>Approved by</strong><br>{{ $order->approver?->name ?? '—' }}</td>
</tr></table>
<table class="lines" style="width:100%;border-collapse:collapse">
    <thead><tr><th style="text-align:left">#</th><th style="text-align:left">Product</th><th style="text-align:right">Qty</th><th style="text-align:right">Unit price</th><th style="text-align:right">Tax %</th><th style="text-align:right">Tax</th><th style="text-align:right">Amount</th></tr></thead>
    <tbody>@foreach($order->items as $i)
        <tr><td>{{ $loop->iteration }}</td><td>{{ $i->product_name }}</td><td style="text-align:right">{{ $i->quantity + 0 }}</td><td style="text-align:right">{{ number_format($i->unit_price, 2) }}</td>
            <td style="text-align:right">{{ $i->tax_rate + 0 }}</td><td style="text-align:right">{{ number_format($i->line_tax, 2) }}</td><td style="text-align:right">{{ number_format($i->line_total, 2) }}</td></tr>
    @endforeach</tbody>
    <tfoot>
        <tr><td colspan="6" style="text-align:right">Subtotal</td><td style="text-align:right">{{ number_format($invoice->subtotal, 2) }}</td></tr>
        <tr><td colspan="6" style="text-align:right">Tax</td><td style="text-align:right">{{ number_format($invoice->tax_total, 2) }}</td></tr>
        <tr><td colspan="6" style="text-align:right"><strong>Total</strong></td><td style="text-align:right"><strong>{{ number_format($invoice->total, 2) }}</strong></td></tr>
    </tfoot>
</table>

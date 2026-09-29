<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $invoice->invoice_number }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
    .lines th, .lines td { border-bottom: 1px solid #ddd; padding: 5px; }
    .lines thead th { background: #f2f3f5; }
</style></head>
<body>@include('inventory.invoices._body')</body></html>

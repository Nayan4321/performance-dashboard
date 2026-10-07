@extends('layouts.app')
@section('title', 'Compare revenue with a Zenoti export')
@section('content')
<div class="d-flex align-items-center mb-3"><h1 class="h4 mb-0">Compare Callgear revenue with a Zenoti export</h1>
    <a href="{{ route('admin.integrations.index') }}" class="btn btn-sm btn-link ms-auto">Back to Integrations</a></div>
<p class="small text-muted">Upload the Sales-Accrual export (CSV) the client uses. Each Callgear agent's total in the file ("Invoice created by", services, cash / card / custom) is compared with what the dashboard counts for the same dates, invoice by invoice. The file is not saved.</p>
<form method="post" enctype="multipart/form-data" class="row g-2 mb-3">@csrf
    <div class="col-md-6"><input type="file" name="file" accept=".csv,text/csv" class="form-control form-control-sm" required></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Compare</button></div>
</form>
@if($errors->any())<div class="alert alert-danger small">{{ $errors->first() }}</div>@endif
@if($result)
    <p class="small">Dates in the file: <b>{{ $result['from'] }}</b> to <b>{{ $result['to'] }}</b> (invoice closed date) · our amount column: <b>{{ $result['column'] }}</b></p>
    <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>Agent</th><th class="text-end">Zenoti export</th><th class="text-end">Dashboard</th><th class="text-end">Difference</th><th>Invoices that differ</th></tr></thead>
        <tbody>
        @foreach($result['rows'] as $r)
            <tr><td class="fw-medium">{{ $r['agent'] }}</td><td class="text-end">{{ number_format($r['theirs'], 2) }}</td><td class="text-end">{{ number_format($r['ours'], 2) }}</td>
                <td class="text-end fw-semibold {{ abs($r['theirs'] - $r['ours']) < 1 ? 'text-success' : 'text-danger' }}">{{ number_format($r['ours'] - $r['theirs'], 2) }}</td>
                <td>@if($r['diff_count'])<details><summary class="small">{{ $r['diff_count'] }} invoice(s)</summary>
                    <table class="table table-sm small mb-0"><tr><th>Invoice</th><th class="text-end">Export</th><th class="text-end">Ours</th><th>Why</th></tr>
                    @foreach($r['diffs'] as $d)<tr><td>{{ $d['invoice'] }}</td><td class="text-end">{{ number_format($d['theirs'], 2) }}</td><td class="text-end">{{ number_format($d['ours'], 2) }}</td><td>{{ $d['note'] }}</td></tr>@endforeach
                    </table></details>@else<span class="text-success small">all match</span>@endif</td></tr>
        @endforeach
        </tbody>
    </table></div>
@endif
@endsection

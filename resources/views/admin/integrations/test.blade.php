@extends('layouts.app')
@section('title', 'Test a Zenoti / CallGear call')
@section('content')
<div class="d-flex align-items-center mb-3"><h1 class="h4 mb-0">Test a Zenoti / CallGear call</h1>
    <a href="{{ route('admin.integrations.index') }}" class="btn btn-sm btn-link ms-auto">Back to Integrations</a></div>
<p class="small text-muted">Shows exactly what Zenoti sends back (first 3 rows of each list), so we can match field names. Nothing is saved.</p>
<form class="row g-2 mb-3">
    <div class="col-md-2"><select name="call" class="form-select form-select-sm">
        @foreach(['appointments' => 'Appointments (7 days from date)', 'sales' => 'Sales lines and Callgear revenue check (one day)', 'employees' => 'Employees', 'centers' => 'Centers', 'guest' => 'One guest by ID', 'path' => 'Other path', 'callgear_calls' => 'CallGear: calls (one day)', 'callgear_employees' => 'CallGear: employees', 'callgear_debug' => 'CallGear: request details for support', 'callgear_tags' => 'CallGear: complaint marks and notes (one day)', 'employee_filter' => 'Can Zenoti filter by employee? (Callgear tag)', 'sales_probe' => 'Where are all the sales? (7 days)'] as $k => $label)
            <option value="{{ $k }}" @selected(request('call', 'appointments') === $k)>{{ $label }}</option>
        @endforeach
    </select></div>
    <div class="col-md-3"><select name="center" class="form-select form-select-sm">
        <option value="all" @selected(request('center') === 'all')>All branches</option>
        @foreach($branches as $b)<option value="{{ $b->zenoti_center_id }}" @selected(request('center') === $b->zenoti_center_id)>{{ $b->name }}</option>@endforeach
    </select></div>
    <div class="col-md-2"><input type="date" name="date" value="{{ request('date', now()->subDay()->toDateString()) }}" class="form-control form-control-sm"></div>
    <div class="col-md-2"><input name="guest_id" value="{{ request('guest_id') }}" class="form-control form-control-sm" placeholder="Guest ID (UserId=…)"></div>
    <div class="col-md-2"><input name="path" value="{{ request('path') }}" class="form-control form-control-sm" placeholder="Other path, e.g. guests/search"></div>
    <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Run</button></div>
</form>
@if(str_starts_with((string) request('call'), 'callgear') && $callgear['missing'])
    <div class="alert alert-warning small">CallGear settings missing in <code>.env</code>: @foreach($callgear['missing'] as $m)<code>{{ $m }}</code> @endforeach · API address: <code>{{ $callgear['base_url'] }}</code></div>
@endif
@if($error)<div class="alert alert-danger small text-break">{{ $error }}</div>@endif
@if($json !== null)<pre class="bg-white border rounded p-3 small" style="max-height:70vh;overflow:auto">{{ $json }}</pre>@endif
@endsection

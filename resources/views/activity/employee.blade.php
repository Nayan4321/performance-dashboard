@extends('layouts.app')
@section('title', $employee->full_name)
@section('content')
<div class="d-flex align-items-start gap-3 mb-3">
    <div>
        <h1 class="h4 mb-1">{{ $employee->full_name }} @unless($employee->is_active)<span class="badge text-bg-secondary">inactive</span>@endunless</h1>
        @if($employee->tags->isNotEmpty())<div class="mb-1">@foreach($employee->tags as $t)@include('partials.tag', ['tag' => $t]) @endforeach</div>@endif
        <div class="text-muted small">{{ $employee->job_title }}{{ $employee->branch ? ' · '.$employee->branch->name : '' }}{{ $employee->email ? ' · '.$employee->email : '' }}{{ $employee->phone ? ' · '.$employee->phone : '' }}</div>
        <div class="small mt-1">Last activity: <strong>{{ $lastActivity ? \Illuminate\Support\Carbon::parse($lastActivity)->diffForHumans() : 'none recorded yet' }}</strong></div>
    </div>
    @if($employee->user)@can('users.manage')<a href="{{ route('admin.users.edit', $employee->user) }}" class="btn btn-sm btn-outline-secondary ms-auto">Login settings</a>@endcan @endif
</div>
<div class="row g-2 mb-4">
    @foreach($stats as $label => $value)
        <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="small text-muted">{{ $label }}</div><div class="fs-4">{{ is_numeric($value) ? number_format($value) : $value }}</div></div></div></div>
    @endforeach
</div>
@can('dashboards.manage')
<div class="card mb-4"><div class="card-header"><h4 class="card-title">Tags</h4></div><div class="card-body">
    <form method="post" action="{{ route('employees.tags', $employee) }}" class="row g-2 align-items-end">
        @csrf @method('put')
        <div class="col-md-10"><label class="form-label small">Separate tags with commas. New tags are created as you type them; use the same tags to filter dashboards and the employee list.</label>
            <input name="tags" id="tagsInput" value="{{ $employee->tags->pluck('name')->implode(', ') }}" class="form-control form-control-sm" placeholder="e.g. Senior stylist, Night shift" autocomplete="off"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Save tags</button></div>
        @php($allTags = \App\Models\Tag::orderBy('name')->get())
        @if($allTags->isNotEmpty())<div class="col-12 small">Existing: @foreach($allTags as $t)<a href="#" class="text-decoration-none" data-add-tag="{{ $t->name }}">@include('partials.tag', ['tag' => $t])</a> @endforeach</div>@endif
    </form>
    <script>
    document.querySelectorAll('[data-add-tag]').forEach(a => a.addEventListener('click', e => {
        e.preventDefault();
        const input = document.getElementById('tagsInput'), name = a.dataset.addTag;
        const list = input.value.split(',').map(s => s.trim()).filter(Boolean);
        if (!list.some(s => s.toLowerCase() === name.toLowerCase())) list.push(name);
        input.value = list.join(', ');
    }));
    </script>
</div></div>
@if($employee->source !== 'callgear')
@php($agents = \App\Models\Employee::where('source', 'callgear')->whereNotNull('callgear_id')->orderBy('first_name')->get())
<div class="card mb-4"><div class="card-header"><h4 class="card-title">CallGear agent</h4></div><div class="card-body">
    @if($employee->callgear_id)
        <p class="small mb-2">Linked to CallGear agent <strong>#{{ $employee->callgear_id }}</strong>: their calls count for {{ $employee->first_name }}.</p>
    @else
        <p class="small text-muted mb-2">Not linked. If {{ $employee->first_name }} takes calls in CallGear under a different name, pick that agent here.</p>
    @endif
    <form method="post" action="{{ route('employees.callgear', $employee) }}" class="row g-2 align-items-end">@csrf @method('put')
        <div class="col-md-8"><select name="agent_id" class="form-select form-select-sm">
            <option value="">{{ $employee->callgear_id ? 'Unlink' : 'Choose a CallGear agent' }}</option>
            @foreach($agents as $a)<option value="{{ $a->id }}">{{ trim($a->first_name.' '.$a->last_name) }} (#{{ $a->callgear_id }}, {{ number_format($a->calls()->count()) }} calls)</option>@endforeach
        </select></div>
        <div class="col-md-4"><button class="btn btn-sm btn-primary w-100">{{ $employee->callgear_id ? 'Save' : 'Link' }}</button></div>
    </form>
    @if($agents->isEmpty() && ! $employee->callgear_id)<div class="small text-muted mt-1">Every CallGear agent is already linked to an employee.</div>@endif
</div></div>
@endif
<div class="card mb-4"><div class="card-header"><h4 class="card-title">Monthly target</h4></div><div class="card-body">
    <form method="post" action="{{ route('employees.target', $employee) }}" class="row g-2 align-items-end">
        @csrf @method('put')
        <div class="col-md-3"><label class="form-label small">Salary (AED / month)</label><input type="number" step="any" min="0" name="salary" value="{{ $employee->salary }}" class="form-control form-control-sm"></div>
        <div class="col-md-2"><label class="form-label small">Multiplier</label><input type="number" step="any" min="0" name="target_multiplier" value="{{ $employee->target_multiplier }}" placeholder="{{ \App\Models\Employee::DEFAULT_MULTIPLIER }}" class="form-control form-control-sm"></div>
        <div class="col-md-3"><label class="form-label small">Section</label><select name="section" class="form-select form-select-sm"><option value="">—</option>@foreach(\App\Models\Employee::SECTIONS as $sec)<option @selected($employee->section === $sec)>{{ $sec }}</option>@endforeach</select></div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary">Save</button></div>
        <div class="col-md-2 small text-muted">Target: <strong>{{ $employee->monthlyTarget() ? config('app.currency_symbol').number_format($employee->monthlyTarget()) : 'set a salary' }}</strong></div>
    </form>
</div></div>
@endcan
<h2 class="h6">Activity <span class="text-muted fw-normal small">what {{ $employee->first_name }} did, and changes to their appointments</span></h2>
@include('activity._list', ['hideEmployee' => false])
@endsection

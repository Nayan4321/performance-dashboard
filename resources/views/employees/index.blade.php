@extends('layouts.app')
@section('title', 'Employees')
@section('content')
<h1 class="h4 mb-3">Employees <span class="text-muted small fw-normal">this month</span></h1>
<form class="row g-2 mb-3">
    <div class="col-md-3"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Search"></div>
    <div class="col-md-2"><select name="tag_id" class="form-select form-select-sm"><option value="">All tags</option>@foreach($tags as $t)<option value="{{ $t->id }}" @selected(request('tag_id') == $t->id)>{{ $t->name }}</option>@endforeach</select></div>
    <div class="col-md-3"><select name="branch_id" class="form-select form-select-sm"><option value="">All branches</option>@foreach($branches as $b)<option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
    <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Active only</option><option value="all" @selected(request('status') === 'all')>Include inactive</option></select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Filter</button></div>
</form>
@php($canTag = auth()->user()->can('dashboards.manage'))
@if($canTag)
<form method="post" action="{{ route('employees.bulk-tags') }}" id="bulkTagForm">@csrf
<div class="d-flex flex-wrap gap-2 align-items-center mb-2">
    <span class="small text-muted" id="bulkCount">Tick employees to tag them</span>
    <input name="tag" list="tagNames" class="form-control form-control-sm" style="max-width:220px" placeholder="Tag, e.g. Senior stylist" required>
    <datalist id="tagNames">@foreach($tags as $t)<option value="{{ $t->name }}">@endforeach</datalist>
    <button name="action" value="add" class="btn btn-sm btn-primary" disabled data-bulk>Add tag</button>
    <button name="action" value="remove" class="btn btn-sm btn-outline-secondary" disabled data-bulk>Remove tag</button>
</div>
@endif
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr>@if($canTag)<th style="width:1%"><input type="checkbox" class="form-check-input" id="tickAll" title="Tick all on this page"></th>@endif<th>Name</th><th>Branch</th><th>Job</th><th>Tags</th><th>Source</th><th class="text-end">Appointments</th><th class="text-end">Revenue</th><th class="text-end">Leads</th><th class="text-end">Calls</th><th>Login</th></tr></thead>
    <tbody>
    @forelse($employees as $e)
        <tr class="{{ $e->is_active ? '' : 'text-muted' }}">
            @if($canTag)<td><input type="checkbox" class="form-check-input" name="employee_ids[]" value="{{ $e->id }}" form="bulkTagForm"></td>@endif
            <td>@if(Route::has('employees.show'))<a href="{{ route('employees.show', $e) }}">{{ $e->full_name }}</a>@else{{ $e->full_name }}@endif<div class="small text-muted">{{ $e->email }}</div></td>
            <td>{{ $e->branch?->name ?? '—' }}</td>
            <td>{{ $e->job_title }}</td>
            <td>@foreach($e->tags as $t)<a href="{{ request()->fullUrlWithQuery(['tag_id' => $t->id, 'page' => null]) }}" class="text-decoration-none">@include('partials.tag', ['tag' => $t])</a> @endforeach</td>
            <td><span class="badge text-bg-light border">{{ $e->source }}</span>@if($e->callgear_id) <span class="badge text-bg-light border">callgear</span>@endif</td>
            <td class="text-end">{{ number_format($e->appointments_count) }}@if($e->booked_count)<div class="small text-muted" title="Appointments this person booked this month, for any provider">booked {{ number_format($e->booked_count) }}</div>@endif</td>
            <td class="text-end">{{ config('app.currency_symbol') }}{{ number_format($e->sales_sum_net_amount ?? 0) }}@if($e->entered_sum)<div class="small text-muted" title="Sales on invoices this person entered this month">entered {{ config('app.currency_symbol') }}{{ number_format($e->entered_sum) }}</div>@endif</td>
            <td class="text-end">{{ number_format($e->leads_count) }}</td>
            <td class="text-end">{{ number_format($e->calls_count) }}</td>
            <td>
                @if($e->user)
                    @can('users.manage')<a href="{{ route('admin.users.edit', $e->user) }}" class="small">{{ $e->user->getRoleNames()->implode(', ') ?: 'no role' }}</a>
                    @else<span class="small">{{ $e->user->getRoleNames()->implode(', ') }}</span>@endcan
                @else<span class="small text-muted">none</span>@endif
            </td>
        </tr>
    @empty
        <tr><td colspan="{{ $canTag ? 11 : 10 }}" class="text-center text-muted py-4">No employees yet. They appear here after the first Zenoti sync.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
@if($canTag)</form>
<script>
(() => {
    const boxes = () => [...document.querySelectorAll('input[name="employee_ids[]"]')];
    const sync = () => {
        const n = boxes().filter(b => b.checked).length;
        document.getElementById('bulkCount').textContent = n ? n + ' ticked' : 'Tick employees to tag them';
        document.querySelectorAll('[data-bulk]').forEach(b => b.disabled = !n);
    };
    document.getElementById('tickAll').addEventListener('change', e => { boxes().forEach(b => b.checked = e.target.checked); sync(); });
    boxes().forEach(b => b.addEventListener('change', sync));
})();
</script>
@endif
<div class="mt-3">{{ $employees->links() }}</div>
@endsection

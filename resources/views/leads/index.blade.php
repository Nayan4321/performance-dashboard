@extends('layouts.app')
@section('title', 'Leads')
@section('content')
<div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0">Leads</h1>
    <a href="{{ route('leads.create') }}" class="btn btn-primary btn-sm ms-auto"><i class="bi bi-plus"></i> Add lead</a>
</div>
<form class="row g-2 mb-3">
    <div class="col-md-3"><select name="branch_id" class="form-select form-select-sm"><option value="">All branches</option>@foreach($branches as $b)<option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
    <div class="col-md-3"><select name="stage" class="form-select form-select-sm"><option value="">All stages</option>@foreach($stages as $s)<option @selected(request('stage') === $s)>{{ $s }}</option>@endforeach</select></div>
    <div class="col-md-3"><select name="source" class="form-select form-select-sm"><option value="">All sources</option>@foreach(['zenoti','callgear','manual'] as $s)<option @selected(request('source') === $s)>{{ $s }}</option>@endforeach</select></div>
    <div class="col-md-3"><button class="btn btn-sm btn-outline-secondary w-100">Filter</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th>Date</th><th>Name</th><th>Phone</th><th>Branch</th><th>Owner</th><th>Channel</th><th>Stage</th><th>Source</th><th class="text-end">Value</th><th></th></tr></thead>
    <tbody>
    @forelse($leads as $l)
        <tr>
            <td class="small">{{ $l->lead_at?->format('d M Y H:i') }}</td>
            <td>{{ $l->name }}</td><td>{{ $l->phone }}</td>
            <td>{{ $l->branch?->name }}</td><td>{{ $l->employee?->full_name }}</td><td>{{ $l->channel }}</td>
            <td><span class="badge text-bg-light border">{{ $l->stage }}</span></td>
            <td class="small">{{ $l->source }}</td>
            <td class="text-end">{{ number_format($l->value) }}</td>
            <td class="text-end"><a href="{{ route('leads.edit', $l) }}" class="btn btn-sm btn-outline-secondary">Edit</a></td>
        </tr>
    @empty
        <tr><td colspan="10" class="text-center text-muted py-4">No leads.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $leads->links() }}</div>
@endsection

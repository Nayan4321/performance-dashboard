@extends('layouts.app')
@section('title', 'Complaints')
@section('content')
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Complaints</h1>
    @foreach(\App\Models\Complaint::STATUSES as $k => $label)
        <a href="{{ route('complaints.index', ['status' => $k]) }}" class="badge text-decoration-none {{ request('status') === $k ? 'text-bg-primary' : 'text-bg-light border' }}">{{ $label }} {{ $counts[$k] ?? 0 }}</a>
    @endforeach
    <a href="{{ route('complaints.create') }}" class="btn btn-sm btn-primary ms-auto"><i class="iconoir-plus-circle"></i> Log a complaint</a>
</div>
<form class="row g-2 mb-3" method="get">
    <input type="hidden" name="status" value="{{ request('status') }}">
    <div class="col-md-3"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Number, client or text"></div>
    <div class="col-md-3"><select name="employee_id" class="form-select form-select-sm"><option value="">All agents</option>@foreach($agents as $a)<option value="{{ $a->id }}" @selected(request('employee_id') == $a->id)>{{ $a->first_name }} {{ $a->last_name }}</option>@endforeach</select></div>
    <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm" title="From"></div>
    <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm" title="To"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Filter</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
    <thead><tr><th>No.</th><th>Time</th><th>Agent</th><th>Caller</th><th>Client (Zenoti)</th><th>Complaint</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse($rows as $c)
        @php($tone = ['open' => 'danger', 'in_progress' => 'warning', 'resolved' => 'success'][$c->status] ?? 'secondary')
        <tr>
            <td class="fw-semibold text-nowrap">{{ $c->number() }}</td>
            <td class="small text-nowrap">{{ $c->called_at?->format('j M Y H:i') }}</td>
            <td class="small">{{ $c->employee ? trim($c->employee->first_name.' '.$c->employee->last_name) : '—' }}</td>
            <td class="small text-nowrap">{{ $c->phone ?? '—' }}</td>
            <td class="small">@if($c->guest)@if($c->guest->zenoti_url)<a href="{{ $c->guest->zenoti_url }}" target="_blank" rel="noopener">{{ $c->guest->full_name }}</a>@else{{ $c->guest->full_name }}@endif @else<span class="text-muted">not found</span>@endif</td>
            <td class="small" style="max-width:340px">@if($c->category)<span class="badge text-bg-light border me-1">Cat. {{ $c->category }}</span>@endif{{ \Illuminate\Support\Str::limit($c->message, 140) }}</td>
            <td><span class="badge text-bg-{{ $tone }}">{{ \App\Models\Complaint::STATUSES[$c->status] ?? $c->status }}</span></td>
            <td class="text-end"><button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#cp{{ $c->id }}">Details</button></td>
        </tr>
        <tr class="collapse" id="cp{{ $c->id }}"><td colspan="8" class="bg-light">
            <div class="row g-3 py-2">
                <div class="col-lg-5">
                    <div class="small text-muted mb-1">Complaint</div>
                    <div class="small" style="white-space:pre-wrap">{{ $c->message }}</div>
                    <div class="small text-muted mt-2">Logged by {{ $c->creator?->name ?? 'CallGear' }} · {{ $c->created_at->format('j M Y H:i') }}@if($c->branch) · {{ $c->branch->name }}@endif</div>
                    @if($c->call)<div class="small text-muted">Call: {{ $c->call->direction === 'in' ? 'incoming' : ($c->call->direction ?? 'call') }}, {{ $c->call->duration_seconds ? gmdate('i:s', $c->call->duration_seconds) : '—' }} min, to {{ $c->call->callee ?? '—' }}</div>@endif
                    @if($c->resolution)<div class="small mt-2"><strong>Resolution:</strong> <span style="white-space:pre-wrap">{{ $c->resolution }}</span></div>@endif
                    @if($c->resolved_at)<div class="small text-muted">Resolved {{ $c->resolved_at->format('j M Y H:i') }} by {{ $c->resolver?->name }} ({{ $c->called_at ? $c->called_at->diffForHumans($c->resolved_at, true) : '' }} after the call)</div>@endif
                </div>
                <div class="col-lg-3">
                    <div class="small text-muted mb-1">Client in Zenoti</div>
                    @if($s = $c->clientSummary())
                        <div class="small"><strong>{{ $c->guest->full_name }}</strong>{{ $c->guest->phone ? ' · '.$c->guest->phone : '' }}</div>
                        <div class="small">Visits: {{ $s['visits'] }} · last {{ $s['last_visit'] ? \Illuminate\Support\Carbon::parse($s['last_visit'])->format('j M Y') : '—' }}</div>
                        <div class="small">Spend: {{ config('app.currency_symbol') }}{{ number_format($s['spend']) }} · no-shows {{ $s['no_shows'] }}</div>
                        <div class="small">Next: {{ $s['next_visit'] ? $s['next_visit']->start_time->format('j M H:i').' '.$s['next_visit']->service_name : 'none booked' }}</div>
                        <div class="small">Complaints from this client: {{ $s['complaints'] }}</div>
                        @if($c->guest->zenoti_url)<a class="small" href="{{ $c->guest->zenoti_url }}" target="_blank" rel="noopener">Open profile in Zenoti</a>@endif
                    @else
                        <div class="small text-muted">No Zenoti guest with this number.</div>
                    @endif
                </div>
                <div class="col-lg-4">
                    @if($canManage)
                    <form method="post" action="{{ route('complaints.update', $c) }}" class="small">@csrf @method('put')
                        <div class="row g-2">
                            <div class="col-6"><label class="form-label small mb-0">Status</label><select name="status" class="form-select form-select-sm">@foreach(\App\Models\Complaint::STATUSES as $k => $label)<option value="{{ $k }}" @selected($c->status === $k)>{{ $label }}</option>@endforeach</select></div>
                            <div class="col-6"><label class="form-label small mb-0">Category</label><select name="category" class="form-select form-select-sm"><option value="">—</option>@foreach(\App\Models\Complaint::CATEGORIES as $k => $label)<option value="{{ $k }}" @selected($c->category === $k)>{{ $label }}</option>@endforeach</select></div>
                            <div class="col-12"><textarea name="resolution" rows="2" class="form-control form-control-sm" placeholder="What was done for the client">{{ $c->resolution }}</textarea></div>
                            <div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary">Save</button></div>
                        </div>
                    </form>
                    <form method="post" action="{{ route('complaints.destroy', $c) }}" class="mt-1" onsubmit="return confirm('Delete this complaint?')">@csrf @method('delete')<button class="btn btn-sm btn-link text-danger p-0">Delete</button></form>
                    @endif
                </div>
            </div>
        </td></tr>
    @empty
        <tr><td colspan="8" class="text-center text-muted py-4">No complaints yet. Agents log one with "Log a complaint", or from the Calls page.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection

@extends('layouts.app')
@section('title', 'Guests')
@section('content')
<div class="d-flex align-items-baseline gap-2 mb-3">
    <h1 class="h4 mb-0">Guests</h1> @include('partials.source-badge', ['source' => 'Zenoti'])
    <span class="text-muted small">{{ number_format($total) }} {{ request('period') === 'all' ? 'in total' : 'registered '.$from->format('j M').' – '.$to->format('j M Y') }}</span>
</div>
@include('records._filters', ['placeholder' => 'Name, phone or email', 'periods' => ['all' => 'All time']])
<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0">
    <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Gender</th><th>Branch</th><th>Registered</th><th class="text-end">Appointments</th></tr></thead>
    <tbody>
    @forelse($rows as $g)
        <tr>
            <td>@if($g->zenoti_url)<a href="{{ $g->zenoti_url }}" target="_blank" rel="noopener">{{ $g->full_name }} <i class="bi bi-box-arrow-up-right small"></i></a>@else{{ $g->full_name }}@endif</td>
            <td>{{ $g->phone }}</td>
            <td class="small">{{ $g->email }}</td>
            <td>{{ $g->gender }}</td>
            <td>{{ $g->branch?->name ?? '—' }}</td>
            <td class="small">{{ $g->registered_at?->format('j M Y') }}</td>
            <td class="text-end"><a href="{{ route('appointments.index', ['q' => $g->phone ?: $g->last_name, 'period' => 'last_90_days']) }}">{{ number_format($g->appointments_count) }}</a></td>
        </tr>
    @empty
        <tr><td colspan="7" class="text-center text-muted py-4">No guests in this period. Try "All time", or run a Zenoti sync from Admin › Integrations.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection

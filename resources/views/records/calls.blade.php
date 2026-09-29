@extends('layouts.app')
@section('title', 'Calls')
@section('content')
<div class="d-flex align-items-baseline gap-2 mb-3">
    <h1 class="h4 mb-0">Calls</h1> @include('partials.source-badge', ['source' => 'CallGear'])
    <span class="text-muted small">{{ number_format($total) }} calls, {{ number_format($known) }} from known guests · {{ $from->format('j M') }} – {{ $to->format('j M Y') }}</span>
</div>
@include('records._filters', ['placeholder' => 'Number or guest name'])
<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0">
    <thead><tr><th>Time</th><th>Caller</th><th>Guest</th><th>Dialed</th><th>Branch</th><th>Status</th><th class="text-end">Duration</th></tr></thead>
    <tbody>
    @forelse($rows as $c)
        <tr>
            <td class="small text-nowrap">{{ $c->started_at?->format('j M Y H:i') }}</td>
            <td>{{ $c->caller }}</td>
            <td>@if($c->guest)@if($c->guest->zenoti_url)<a href="{{ $c->guest->zenoti_url }}" target="_blank" rel="noopener">{{ $c->guest->full_name }}</a>@else{{ $c->guest->full_name }}@endif @else<span class="text-muted small">new caller</span>@endif</td>
            <td>{{ $c->callee }}</td>
            <td>{{ $c->branch?->name ?? '—' }}</td>
            <td><span class="badge text-bg-light border">{{ $c->status }}</span></td>
            <td class="text-end">{{ $c->duration_seconds ? gmdate($c->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', $c->duration_seconds) : '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="7" class="text-center text-muted py-4">No calls in this period. Calls appear once CallGear's interactive call processing points at this site (see Admin › Integrations).</td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection

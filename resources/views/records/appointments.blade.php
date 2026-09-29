@extends('layouts.app')
@section('title', 'Appointments')
@section('content')
<div class="d-flex align-items-baseline gap-2 mb-3">
    <h1 class="h4 mb-0">Appointments</h1> @include('partials.source-badge', ['source' => 'Zenoti'])
    <span class="text-muted small">{{ $from->format('j M') }} – {{ $to->format('j M Y') }}</span>
</div>
@include('records._filters', ['placeholder' => 'Service or guest'])
<div class="row g-2 mb-3">
    @php $colors = ['Serviced' => 'success', 'In-progress' => 'secondary', 'Open & confirmed' => 'primary', 'No-show' => 'warning', 'Cancelled' => 'danger']; @endphp
    <div class="col-6 col-md-2"><a href="{{ request()->fullUrlWithQuery(['status' => null, 'page' => null]) }}" class="card text-decoration-none h-100 {{ request('status') ? '' : 'border-dark' }}"><div class="card-body py-2"><div class="small text-muted">All</div><div class="fs-4">{{ number_format($total) }}</div></div></a></div>
    @foreach(\App\Models\Appointment::STATUSES as $s)
        <div class="col-6 col-md-2"><a href="{{ request()->fullUrlWithQuery(['status' => $s, 'page' => null]) }}" class="card text-decoration-none h-100 {{ request('status') === $s ? 'border-dark' : '' }}"><div class="card-body py-2">
            <div class="small text-{{ $colors[$s] }}">{{ $s }}</div><div class="fs-4">{{ number_format($byStatus[$s] ?? 0) }}</div></div></a></div>
    @endforeach
</div>
<div class="card"><div class="table-responsive"><table class="table table-hover table-sm mb-0">
    <thead><tr><th>Date</th><th>Guest</th><th>Service</th><th>Provider</th><th>Branch</th><th>Status</th><th class="text-end">Price</th></tr></thead>
    <tbody>
    @forelse($rows as $a)
        <tr>
            <td class="small text-nowrap">{{ $a->start_time?->format('j M Y H:i') }}</td>
            <td>@if($a->guest?->zenoti_url)<a href="{{ $a->guest->zenoti_url }}" target="_blank" rel="noopener">{{ $a->guest->full_name }}</a>@else{{ $a->guest?->full_name ?? '—' }}@endif<div class="small text-muted">{{ $a->guest?->phone }}</div></td>
            <td>{{ $a->service_name }}</td>
            <td>{{ $a->employee?->full_name ?? '—' }}</td>
            <td>{{ $a->branch?->name ?? '—' }}</td>
            <td><span class="badge text-bg-{{ $colors[$a->status] ?? 'light' }}" title="Zenoti: {{ $a->raw_status }}">{{ $a->status ?? '—' }}</span></td>
            <td class="text-end">{{ config('app.currency_symbol') }}{{ number_format($a->price, 2) }}</td>
        </tr>
    @empty
        <tr><td colspan="7" class="text-center text-muted py-4">No appointments in this period. If this stays empty, run a Zenoti sync from Admin › Integrations.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $rows->links() }}</div>
@endsection

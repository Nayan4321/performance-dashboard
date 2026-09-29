@php
    $tone = ['booked' => 'primary', 'changed' => 'secondary', 'cancelled' => 'warning', 'no-show' => 'warning', 'deleted' => 'danger', 'guest_deleted' => 'danger', 'restored' => 'success'];
@endphp
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
    <thead><tr><th>When</th><th>What</th><th>Details</th><th>Done by</th>@unless($hideEmployee ?? false)<th>Provider</th>@endunless<th>Branch</th></tr></thead>
    <tbody>
    @forelse($logs as $log)
        <tr>
            <td class="small text-nowrap" title="{{ $log->occurred_at }}">{{ $log->occurred_at->diffForHumans() }}<div class="text-muted">{{ $log->occurred_at->format('j M H:i') }}</div></td>
            <td><span class="badge text-bg-{{ $tone[$log->action] ?? 'light' }}">{{ $log->label() }}</span></td>
            <td class="small">{{ $log->subject_label }}
                @if($log->changes)
                    <div class="text-muted">@foreach($log->changes as $field => [$old, $new]){{ str_replace('_', ' ', $field) }}: {{ $old ?: '—' }} → {{ $new ?: '—' }}@if(! $loop->last); @endif @endforeach</div>
                @endif
            </td>
            <td class="small">@if($log->actor)<a href="{{ route('employees.show', $log->actor) }}">{{ $log->actor->full_name }}</a>@elseif($log->actor_name){{ $log->actor_name }}@else<span class="text-muted" title="Zenoti didn't say who made this change">not recorded</span>@endif</td>
            @unless($hideEmployee ?? false)<td class="small">@if($log->employee)<a href="{{ route('employees.show', $log->employee) }}">{{ $log->employee->full_name }}</a>@else—@endif</td>@endunless
            <td class="small">{{ $log->branch?->name ?? '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="text-center text-muted py-4">No activity yet. Changes are recorded from now on, each time Zenoti syncs.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $logs->links() }}</div>

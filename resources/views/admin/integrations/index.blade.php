@extends('layouts.app')
@section('title', 'Integrations')
@section('content')
<div class="d-flex align-items-center mb-3"><h1 class="h4 mb-0">Integrations</h1>
    @if(Route::has('admin.integrations.test'))<a href="{{ route('admin.integrations.test') }}" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-bug"></i> Test a Zenoti / CallGear call</a>@endif</div>
<div class="row g-3 mb-4">
@foreach($sources as $key => $source)
    <div class="col-md-6"><div class="card h-100"><div class="card-body">
        <div class="d-flex align-items-center mb-2">
            <h2 class="h5 mb-0">{{ $source->label() }}</h2>
            @if($source->isConfigured())
                <span class="badge text-bg-success ms-2"><i class="bi bi-check-circle"></i> Connected</span>
            @else
                <span class="badge text-bg-secondary ms-2"><i class="bi bi-pause-circle"></i> Not configured</span>
            @endif
        </div>
        <p class="small text-muted mb-2">Webhook URL to register in {{ $source->label() }}:<br>
            <code>{{ route('webhooks', $key) }}?token=YOUR_{{ strtoupper($key) }}_WEBHOOK_SECRET</code>
            @if($key === 'callgear' && Route::has('webhooks.callgear.incoming'))
                <br>Interactive call processing URL (every incoming call, works without the Data API):<br>
                <code>{{ route('webhooks.callgear.incoming') }}?token=YOUR_CALLGEAR_WEBHOOK_SECRET</code>
            @endif</p>
        @if($key === 'callgear')
            <p class="small mb-2">Calls saved: <strong>{{ number_format($callStats['total']) }}</strong>{{ $callStats['last'] ? ' · latest '.\Illuminate\Support\Carbon::parse($callStats['last'])->diffForHumans() : '' }} · CallGear messages received: {{ number_format($callStats['incoming_events']) }}@if($callStats['last_event']) (latest {{ \Illuminate\Support\Carbon::parse($callStats['last_event'])->diffForHumans() }})@endif</p>
            @if($callgear['missing'])
                <div class="alert alert-warning small py-2 mb-2">CallGear sync is off because <code>.env</code> is missing:<br>
                    @foreach($callgear['missing'] as $m)<code>{{ $m }}</code><br>@endforeach
                    Add these lines to <code>.env</code> yourself (hPanel File Manager), then click <strong>Test</strong>.</div>
            @elseif(! empty($callgear['optional']))
                <p class="small text-muted mb-2">Call import works. Only the interactive call processing URL needs <code>CALLGEAR_WEBHOOK_SECRET</code> in <code>.env</code>; skip it if you don't use that URL.</p>
            @endif
        @endif
        @if($source->isConfigured())
        <form method="post" action="{{ route('admin.integrations.sync', $key) }}" class="d-flex flex-wrap gap-2">@csrf
            <select name="entity" class="form-select form-select-sm" style="max-width:200px"><option value="">Everything</option>@foreach($source->entities() as $e)<option>{{ $e }}</option>@endforeach</select>
            @if($key === 'zenoti')
            <select name="days" class="form-select form-select-sm" style="max-width:160px" title="How far back to pull appointments, sales and collections">
                <option value="">Last few days</option><option value="30">Last 30 days</option><option value="90">Last 90 days</option><option value="365">Last 12 months</option><option value="730">Last 2 years (all guests)</option>
            </select>
            <select name="branch_id" class="form-select form-select-sm" style="max-width:180px" title="Only this branch">
                <option value="">All branches</option>@foreach($syncBranches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
            </select>
            <select name="tag" class="form-select form-select-sm" style="max-width:180px" title="Guest profiles only for guests these employees served, booked or billed (all branches are still pulled)">
                <option value="">All employees</option>@foreach($syncTags as $t)<option>{{ $t }}</option>@endforeach
            </select>
            @endif
            @if($key === 'callgear')
            <select name="days" class="form-select form-select-sm" style="max-width:160px" title="How far back to pull calls">
                <option value="">Last 2 days</option><option value="7">Last 7 days</option><option value="30">Last 30 days</option><option value="90">Last 90 days</option>
            </select>
            @endif
            <button class="btn btn-sm btn-primary">Sync now</button>
        </form>
        @else
            <p class="small mb-0">Add the API keys to <code>.env</code> (see README) and set <code>{{ strtoupper($key) }}_ENABLED=true</code>.</p>
        @endif
    </div></div></div>
@endforeach
</div>

@php $activeRequest = $requests->first(fn ($r) => $r->isActive()); @endphp
<div class="card mb-3"><div class="card-body py-2 small">
    <strong>Automatic sync:</strong>
    @if($heartbeat && $heartbeat->gt(now()->subMinutes(5)))
        <span class="text-success">on (cron)</span>, last run {{ $heartbeat->diffForHumans() }}.
    @elseif($autoSync['last'] && $autoSync['last']->gt(now()->subMinutes(15)))
        <span class="text-success">on</span>, last run {{ $autoSync['last']->diffForHumans() }}. CallGear and Zenoti appointments / sales every 5 minutes.
    @else
        <span class="text-danger">not running</span>{{ $autoSync['last'] ? ', last run '.$autoSync['last']->diffForHumans() : '' }}.
    @endif
    <details class="mt-1"><summary>Keep it running when nobody has the site open</summary>
        Without cron, the syncs run while someone uses the site. To keep them going around the clock, have this address opened every 5 minutes
        (free at <strong>cron-job.org</strong>: create a cronjob with this URL, every 5 minutes; or an hPanel cron job <code>curl -s "URL"</code>). Keep it private:
        <div class="mt-1"><code class="user-select-all text-break">{{ $autoSync['url'] }}</code></div>
    </details>
</div></div>
@if(! $heartbeat || $heartbeat->lt(now()->subMinutes(5)))
    <div class="alert alert-warning small">
        <strong>The background job (cron) isn't running{{ $heartbeat ? ' — last seen '.$heartbeat->diffForHumans() : '' }}.</strong>
        "Sync now" and the automatic syncs need it. In hPanel › Advanced › Cron Jobs add a job that runs <strong>every minute</strong> with this command:
        <div class="mt-1"><code class="user-select-all">{{ $cronCommand }}</code></div>
        <div class="mt-1 text-muted">Until then you can run a sync over SSH: <code>php artisan integrations:sync zenoti --days=90</code></div>
        <div class="mt-2">If the job is already set up, it is failing. What it last wrote (<code>storage/logs/cron.log</code>):</div>
        <pre class="bg-white border rounded p-2 mb-1 small text-break" style="white-space:pre-wrap;max-height:220px;overflow:auto">{{ $cronLog ?? 'Nothing: the log file does not exist, so the cron job has never run this command. Check the command and that it is set to every minute.' }}</pre>
        @if($lastError)<div class="mt-1">Latest app error: <code class="text-break">{{ $lastError }}</code></div>@endif
        <div class="mt-1">Storage folder:
            @if($storage['writable'])<strong class="text-success">writable</strong>@else<strong class="text-danger">NOT writable</strong> <code>{{ $storage['error'] }}</code>@endif
            @if($storage['free_mb'] !== null) · free space {{ number_format($storage['free_mb']) }} MB @endif
            @if(! $storage['writable'] || ($storage['free_mb'] !== null && $storage['free_mb'] < 50))
                <div class="text-danger">The server can't save files, so cron can't write its log either. Free up space in hPanel (File Manager / Disk usage) or fix the folder permissions.</div>
            @endif
        </div>
        @if($queued)
            <form method="post" action="{{ route('admin.integrations.run-now') }}" class="mt-2">@csrf
                <button class="btn btn-sm btn-warning">Run queued syncs now</button> <span class="text-muted">Runs the {{ $queued }} queued sync(s) without cron. You can leave the page while it works.</span>
            </form>
        @endif
    </div>
@endif
@if($requests->isNotEmpty())
<h2 class="h6">Sync now requests</h2>
<div class="card mb-4"><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Requested</th><th>Source</th><th>What</th><th>Status</th><th>Result</th><th></th></tr></thead>
    <tbody>
    @foreach($requests as $r)
        <tr><td class="small text-nowrap">{{ $r->created_at->diffForHumans() }}<div class="text-muted">{{ $r->requester?->name }}</div></td>
            <td>{{ $r->provider }}</td>
            <td class="small">{{ $r->entity ?: 'Everything' }}{{ $r->days ? ', last '.$r->days.' days' : '' }}@if($r->scopeLabel())<div class="text-muted">{{ $r->scopeLabel() }}</div>@endif</td>
            <td><span class="badge text-bg-{{ ['queued' => 'secondary', 'running' => 'info', 'cancelling' => 'warning', 'done' => 'success', 'failed' => 'danger', 'cancelled' => 'dark'][$r->status] ?? 'light' }}">
                @if($r->status === 'running')<span class="spinner-border spinner-border-sm" style="width:.7rem;height:.7rem"></span> @endif{{ $r->status }}</span>
                @if($r->status === 'running' && $r->started_at)<div class="small text-muted">for {{ $r->started_at->diffForHumans(null, true) }}</div>@endif</td>
            <td class="small">{{ $r->message }}</td>
            <td class="text-end">@if(in_array($r->status, ['queued', 'running'], true))
                <form method="post" action="{{ route('admin.integrations.cancel', $r) }}" onsubmit="return confirm('Cancel this sync?')">@csrf
                    <button class="btn btn-sm btn-outline-danger">Cancel</button></form>@endif</td></tr>
    @endforeach
    </tbody>
</table></div></div>
@endif
@if($activeRequest || $runs->contains('status', 'running'))
    <script>setTimeout(() => location.reload(), 15000);</script>
@endif
@if($queued && (! $heartbeat || $heartbeat->lt(now()->subMinutes(5))))
    {{-- No cron: while this page is open it starts the next queued round itself. --}}
    <script>setTimeout(() => fetch(@json(route('admin.integrations.run-now')), {method: 'POST', headers: {'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json'}}), 3000);</script>
@endif

@if($zenotiFields->isNotEmpty())
<details class="card mb-4"><summary class="card-body py-2 fw-semibold">Fields Zenoti sends <span class="text-muted fw-normal small">(names only, personal text hidden; screenshot this if prices or links look wrong)</span></summary>
    <div class="card-body pt-0 row g-3">
        @foreach($zenotiFields as $f)
            <div class="col-md-6"><div class="small fw-semibold mb-1">{{ $f['label'] }} <span class="text-muted fw-normal">{{ isset($f['at']) ? \Illuminate\Support\Carbon::parse($f['at'])->diffForHumans() : '' }}</span></div>
                <pre class="bg-light border rounded p-2 small mb-0" style="max-height:360px;overflow:auto;white-space:pre-wrap">@foreach($f['fields'] as $k => $v){{ $k }} = {{ $v }}
@endforeach</pre></div>
        @endforeach
    </div>
</details>
@endif

<h2 class="h6">Data in this system</h2>
<div class="row g-2 mb-4">
    @foreach($counts as $label => $n)
        <div class="col-6 col-md-2"><div class="card"><div class="card-body py-2"><div class="small text-muted">{{ $label }}</div><div class="fs-5">{{ number_format($n) }}</div></div></div></div>
    @endforeach
    @if($range[0])<div class="col-12 small text-muted">Appointments from {{ \Illuminate\Support\Carbon::parse($range[0])->format('j M Y') }} to {{ \Illuminate\Support\Carbon::parse($range[1])->format('j M Y') }}</div>@endif
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <h2 class="h6">Recent sync runs</h2>
        <div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>When</th><th>Source</th><th>Entity</th><th>Status</th><th>New</th><th>Updated</th><th>Removed</th></tr></thead>
            <tbody>
            @forelse($runs as $r)
                <tr title="{{ $r->message }}"><td class="small">{{ $r->started_at?->diffForHumans() }}</td><td>{{ $r->provider }}</td><td>{{ $r->entity }}</td>
                    <td><span class="badge text-bg-{{ $r->status === 'success' ? 'success' : ($r->status === 'failed' ? 'danger' : 'secondary') }}">{{ $r->status }}</span>
                        @if($r->message)
                            @if(mb_strlen($r->message) > 60)
                                <details class="small text-muted" style="max-width:420px"><summary class="text-truncate">{{ \Illuminate\Support\Str::limit($r->message, 60) }}</summary><div class="text-break user-select-all">{{ $r->message }}</div></details>
                            @else<div class="small text-muted">{{ $r->message }}</div>@endif
                        @endif</td>
                    <td>{{ $r->created_count }}</td><td>{{ $r->updated_count }}</td><td>{{ $r->deactivated_count }}</td></tr>
            @empty
                <tr><td colspan="7" class="text-muted text-center py-3">No syncs yet.</td></tr>
            @endforelse
            </tbody>
        </table></div></div>
    </div>
    <div class="col-lg-5">
        <h2 class="h6">Recent webhooks</h2>
        <div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>When</th><th>Source</th><th>Event</th><th>Status</th></tr></thead>
            <tbody>
            @forelse($events as $e)
                <tr title="{{ $e->error }}"><td class="small">{{ $e->created_at->diffForHumans() }}</td><td>{{ $e->provider }}</td><td class="small">{{ $e->event_type }}@if(is_array($e->payload) && $e->payload)<div class="text-muted text-break" style="max-width:260px">{{ \Illuminate\Support\Str::limit(implode(', ', array_keys($e->payload)), 120) }}</div>@endif</td>
                    <td><span class="badge text-bg-{{ $e->status === 'processed' ? 'success' : ($e->status === 'failed' ? 'danger' : 'secondary') }}">{{ $e->status }}</span></td></tr>
            @empty
                <tr><td colspan="4" class="text-muted text-center py-3">No webhooks received yet.</td></tr>
            @endforelse
            </tbody>
        </table></div></div>
    </div>
</div>
@endsection

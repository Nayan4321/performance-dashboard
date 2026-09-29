@extends('layouts.app')
@section('title', 'Upload update')
@section('content')
<h1 class="h4 mb-1">Upload update</h1>
<p class="text-muted small">Install a new version by uploading its update zip. You'll see every file it changes before anything is written. Files it replaces are backed up so it can be rolled back. <code>.env</code>, <code>storage</code> and caches are never touched.</p>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card"><div class="card-body">
            <h2 class="h6">1. Choose the update zip</h2>
            <form method="post" action="{{ route('admin.system-update.upload') }}" enctype="multipart/form-data">@csrf
                <input type="file" name="zip" accept=".zip" class="form-control mb-2" required>
                <div class="form-text mb-2">Server upload limit: {{ $maxUpload }}. The full install zip with <code>vendor/</code> is larger; use the small update zips.</div>
                <button class="btn btn-primary" @disabled(! $ready)>Upload and review</button>
                @unless($ready)<div class="text-danger small mt-2">Click "Finish install" first to create the update log table.</div>@endunless
            </form>
        </div></div>
    </div>
    <div class="col-lg-6">
        <div class="card"><div class="card-body">
            <h2 class="h6">Finish install</h2>
            <p class="small text-muted mb-2">Copied files by hand with the File Manager? This runs database migrations, any seeders you list, and clears caches (the same as the SSH commands).</p>
            <form method="post" action="{{ route('admin.system-update.finish') }}" onsubmit="this.querySelector('button').disabled=true">@csrf
                <input name="seeders" class="form-control form-control-sm mb-2" placeholder="Seeders to run, comma separated (optional), e.g. AdminDashboardSeeder">
                <button class="btn btn-outline-primary">Finish install</button>
            </form>
            @if(session('finish_output'))<pre class="small bg-light p-2 mt-2 mb-0" style="white-space:pre-wrap">{{ session('finish_output') }}</pre>@endif
        </div></div>
    </div>
</div>

<h2 class="h6 mt-4">History</h2>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>When</th><th>File</th><th>Version</th><th>Files</th><th>By</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse($updates as $u)
        <tr>
            <td class="small">{{ $u->created_at->format('d M Y H:i') }}</td>
            <td>{{ $u->filename }}</td><td>{{ $u->version ?? '—' }}</td><td>{{ count($u->files ?? []) }}</td><td>{{ $u->user?->name }}</td>
            <td><span class="badge text-bg-{{ ['applied' => 'success', 'failed' => 'danger', 'rolled_back' => 'secondary'][$u->status] ?? 'warning' }}">{{ str_replace('_', ' ', $u->status) }}</span></td>
            <td class="text-end"><a href="{{ route('admin.system-update.show', $u) }}" class="btn btn-sm btn-outline-secondary">Open</a></td>
        </tr>
    @empty
        <tr><td colspan="7" class="text-center text-muted py-3">No updates uploaded yet.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
@endsection

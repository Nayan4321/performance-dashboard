@extends('layouts.app')
@section('title', 'Update '.$update->filename)
@section('content')
<a href="{{ route('admin.system-update.index') }}" class="small">&larr; All updates</a>
<h1 class="h4 mt-2 mb-3">{{ $update->filename }} @if($update->version)<span class="text-muted fs-6">v{{ $update->version }}</span>@endif
    <span class="badge fs-6 text-bg-{{ ['applied' => 'success', 'failed' => 'danger', 'rolled_back' => 'secondary'][$update->status] ?? 'warning' }}">{{ str_replace('_', ' ', $update->status) }}</span></h1>

@if($update->status === 'uploaded')
    <div class="card mb-3 border-primary"><div class="card-body">
        <h2 class="h6">2. Review and install</h2>
        <p class="small mb-2">This writes the {{ count($update->files) }} file(s) listed below, then runs database migrations and clears caches. It takes a few seconds; don't close the page.</p>
        <form method="post" action="{{ route('admin.system-update.apply', $update) }}" onsubmit="this.querySelector('button').disabled=true">@csrf
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="confirm" required>
                <label class="form-check-label" for="confirm">I've checked the file list and want to install this update</label></div>
            <button class="btn btn-primary">Install update</button>
        </form>
    </div></div>
@endif

@if(in_array($update->status, ['applied', 'failed']))
    <form method="post" action="{{ route('admin.system-update.rollback', $update) }}" class="mb-3" onsubmit="return confirm('Put back the files as they were before this update?')">@csrf
        <button class="btn btn-sm btn-outline-danger">Roll back files</button>
        <span class="small text-muted ms-2">Restores the replaced files and removes files this update added. Database changes stay.</span>
    </form>
@endif

@if($update->output)<h2 class="h6">Log</h2><pre class="small bg-light border p-2" style="white-space:pre-wrap">{{ $update->output }}</pre>@endif

<h2 class="h6">Files in this update</h2>
<div class="card"><ul class="list-group list-group-flush small" style="max-height:420px;overflow:auto">
    @foreach($update->files ?? [] as $f)
        <li class="list-group-item py-1"><code>{{ $f }}</code>@if(in_array($f, $update->new_files ?? []))<span class="badge text-bg-light border ms-1">new</span>@endif</li>
    @endforeach
</ul></div>
@endsection

@extends('layouts.app')
@section('title', 'Modules')
@section('content')
<h1 class="h4 mb-1">Module visibility</h1>
<p class="text-muted small">Which module belongs to which organization. Super admin and management always see every module. Per-user exceptions are set on the user's page.</p>
<form method="post" action="{{ route('admin.modules.update') }}">@csrf @method('put')
<div class="card"><div class="table-responsive"><table class="table mb-0 align-middle">
    <thead><tr><th>Module</th><th class="text-center">All organizations</th>@foreach($organizations as $o)<th class="text-center">{{ $o->name }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach($modules as $m)
        <tr>
            <td><strong>{{ $m->name }}</strong><div class="small text-muted">{{ $m->description }}</div></td>
            <td class="text-center"><input type="checkbox" class="form-check-input" name="global[{{ $m->id }}]" value="1" @checked($m->is_global)></td>
            @foreach($organizations as $o)
                <td class="text-center"><input type="checkbox" class="form-check-input" name="matrix[{{ $m->id }}][]" value="{{ $o->id }}" @checked($m->organizations->contains($o))></td>
            @endforeach
        </tr>
    @endforeach
    </tbody>
</table></div></div>
<button class="btn btn-primary mt-3">Save</button>
</form>
@endsection

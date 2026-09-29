@extends('layouts.app')
@section('title', 'Organizations')
@section('content')
<h1 class="h4 mb-3">Organizations &amp; branches</h1>
<div class="card mb-4"><div class="card-body">
    <form method="post" action="{{ route('admin.organizations.store') }}" class="row g-2">@csrf
        <div class="col-md-5"><input name="name" class="form-control form-control-sm" placeholder="Organization name" required></div>
        <div class="col-md-3"><input name="code" class="form-control form-control-sm" placeholder="Code (optional)"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Add organization</button></div>
    </form>
</div></div>

@forelse($organizations as $org)
<div class="card mb-4"><div class="card-body">
    <form method="post" action="{{ route('admin.organizations.update', $org) }}" class="row g-2 align-items-center mb-3">@csrf @method('put')
        <div class="col-md-4"><input name="name" value="{{ $org->name }}" class="form-control fw-semibold"></div>
        <div class="col-md-2"><input name="code" value="{{ $org->code }}" class="form-control" placeholder="Code"></div>
        <div class="col-md-2"><input type="hidden" name="is_active" value="0"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($org->is_active)><label class="form-check-label">Active</label></div></div>
        <div class="col-md-2"><button class="btn btn-sm btn-outline-primary">Save</button></div>
        <div class="col-12 small text-muted">{{ $org->users_count }} users · Modules: {{ $org->modules->pluck('name')->implode(', ') ?: 'global modules only' }}</div>
    </form>
    {{-- One form per row, linked with the form="" attribute (forms can't wrap table rows). --}}
    @foreach($org->branches as $b)
        <form id="branch-{{ $b->id }}" method="post" action="{{ route('admin.branches.update', $b) }}">@csrf @method('put')<input type="hidden" name="organization_id" value="{{ $org->id }}"></form>
    @endforeach
    <form id="new-branch-{{ $org->id }}" method="post" action="{{ route('admin.branches.store') }}">@csrf<input type="hidden" name="organization_id" value="{{ $org->id }}"><input type="hidden" name="is_active" value="1"></form>
    <div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>Branch</th><th>Code</th><th>Zenoti center id</th><th>CallGear site id</th><th>Warehouse</th><th>Active</th><th></th></tr></thead>
        <tbody>
        @foreach($org->branches as $b)
            @php($f = 'branch-'.$b->id)
            <tr>
                <td><input form="{{ $f }}" name="name" value="{{ $b->name }}" class="form-control form-control-sm" required></td>
                <td><input form="{{ $f }}" name="code" value="{{ $b->code }}" class="form-control form-control-sm"></td>
                <td><input form="{{ $f }}" name="zenoti_center_id" value="{{ $b->zenoti_center_id }}" class="form-control form-control-sm"></td>
                <td><input form="{{ $f }}" name="callgear_site_id" value="{{ $b->callgear_site_id }}" class="form-control form-control-sm"></td>
                <td><input form="{{ $f }}" type="checkbox" name="is_warehouse" value="1" class="form-check-input" @checked($b->is_warehouse)></td>
                <td><input form="{{ $f }}" type="hidden" name="is_active" value="0"><input form="{{ $f }}" type="checkbox" name="is_active" value="1" class="form-check-input" @checked($b->is_active)></td>
                <td><button form="{{ $f }}" class="btn btn-sm btn-outline-secondary">Save</button></td>
            </tr>
        @endforeach
            @php($f = 'new-branch-'.$org->id)
            <tr>
                <td><input form="{{ $f }}" name="name" class="form-control form-control-sm" placeholder="New branch" required></td>
                <td><input form="{{ $f }}" name="code" class="form-control form-control-sm"></td>
                <td><input form="{{ $f }}" name="zenoti_center_id" class="form-control form-control-sm"></td>
                <td><input form="{{ $f }}" name="callgear_site_id" class="form-control form-control-sm"></td>
                <td><input form="{{ $f }}" type="checkbox" name="is_warehouse" value="1" class="form-check-input"></td>
                <td>—</td>
                <td><button form="{{ $f }}" class="btn btn-sm btn-primary">Add</button></td>
            </tr>
        </tbody>
    </table></div>
</div></div>
@empty
<p class="text-muted">No organizations yet. Add one above, or run a Zenoti sync to import centers as branches.</p>
@endforelse
@endsection

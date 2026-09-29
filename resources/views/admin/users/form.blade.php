@extends('layouts.app')
@section('title', $user->exists ? 'Edit user' : 'Add user')
@section('content')
<h1 class="h4 mb-3">{{ $user->exists ? 'Edit '.$user->name : 'Add user' }}
    @if($user->exists && $user->source !== 'manual')<span class="badge text-bg-info fs-6 align-middle">synced from {{ $user->source }}</span>@endif
</h1>
<form method="post" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}">
    @csrf @if($user->exists) @method('put') @endif
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card"><div class="card-body">
                <h2 class="h6">Account</h2>
                <div class="mb-2"><label class="form-label">Name</label><input name="name" value="{{ old('name', $user->name) }}" class="form-control" required></div>
                <div class="mb-2"><label class="form-label">Email (login)</label><input type="email" name="email" value="{{ old('email', $user->email) }}" class="form-control" required></div>
                <div class="mb-2"><label class="form-label">Phone</label><input name="phone" value="{{ old('phone', $user->phone) }}" class="form-control"></div>
                <div class="mb-2"><label class="form-label">Password {{ $user->exists ? '(leave blank to keep)' : '' }}</label><input type="password" name="password" class="form-control" autocomplete="new-password" {{ $user->exists ? '' : 'required' }}></div>
                <input type="hidden" name="is_active" value="0">
                <div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="active" @checked(old('is_active', $user->is_active))><label class="form-check-label" for="active">Can log in</label></div>
            </div></div>

            <div class="card mt-4"><div class="card-body">
                <h2 class="h6">Scope</h2>
                <div class="mb-2"><label class="form-label">Organization</label>
                    <select name="organization_id" class="form-select"><option value="">—</option>@foreach($organizations as $o)<option value="{{ $o->id }}" @selected(old('organization_id', $user->organization_id) == $o->id)>{{ $o->name }}</option>@endforeach</select></div>
                <div class="mb-2"><label class="form-label">Branch</label>
                    <select name="branch_id" class="form-select"><option value="">—</option>@foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $user->branch_id) == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
                <div class="mb-2"><label class="form-label">Linked employee record <span class="text-muted small">(their Zenoti / CallGear numbers)</span></label>
                    <select name="employee_id" class="form-select"><option value="">— none —</option>@foreach($employees as $e)<option value="{{ $e->id }}" @selected(old('employee_id', $user->employee?->id) == $e->id)>{{ $e->full_name }}{{ $e->email ? ' · '.$e->email : '' }}</option>@endforeach</select></div>
            </div></div>
        </div>

        <div class="col-lg-6">
            <div class="card"><div class="card-body">
                <h2 class="h6">Roles</h2>
                @foreach($roles as $r)
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="roles[]" value="{{ $r }}" id="r-{{ $r }}" @checked(in_array($r, old('roles', $user->exists ? $user->getRoleNames()->all() : [])))><label class="form-check-label" for="r-{{ $r }}">{{ $r }}</label></div>
                @endforeach
            </div></div>

            <div class="card mt-4"><div class="card-body">
                <h2 class="h6">Module visibility <span class="text-muted small fw-normal">(default follows the organization)</span></h2>
                @foreach($modules as $m)
                    @php($ov = $user->exists ? $user->moduleOverrides->firstWhere('id', $m->id) : null)
                    @php($cur = $ov ? ($ov->pivot->allowed ? 'allow' : 'deny') : '')
                    <div class="d-flex align-items-center mb-2">
                        <span class="flex-grow-1">{{ $m->name }}</span>
                        <select name="modules[{{ $m->id }}]" class="form-select form-select-sm" style="max-width:190px">
                            <option value="" @selected($cur === '')>Organization default</option>
                            <option value="allow" @selected($cur === 'allow')>Always show</option>
                            <option value="deny" @selected($cur === 'deny')>Always hide</option>
                        </select>
                    </div>
                @endforeach
            </div></div>

            @can('roles.manage')
            <div class="card mt-4"><div class="card-body">
                <h2 class="h6">Extra permissions <span class="text-muted small fw-normal">(on top of the roles)</span></h2>
                @foreach($permissions as $p)
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="permissions[]" value="{{ $p }}" id="p-{{ $p }}" @checked(in_array($p, old('permissions', $user->exists ? $user->getDirectPermissions()->pluck('name')->all() : [])))>
                        <label class="form-check-label small" for="p-{{ $p }}"><code>{{ $p }}</code> <span class="text-muted">{{ $permissionLabels[$p] ?? '' }}</span></label></div>
                @endforeach
            </div></div>
            @endcan
        </div>
    </div>
    <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary">Save user</button>
        <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@if($user->exists && $user->id !== auth()->id())
<form method="post" action="{{ route('admin.users.destroy', $user) }}" class="mt-3" onsubmit="return confirm('Delete this user?')">@csrf @method('delete')<button class="btn btn-sm btn-link text-danger p-0">Delete user</button></form>
@endif
@endsection

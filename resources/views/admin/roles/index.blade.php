@extends('layouts.app')
@section('title', 'Roles & permissions')
@section('content')
<div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0">Roles &amp; permissions</h1>
    <form method="post" action="{{ route('admin.roles.store') }}" class="ms-auto d-flex gap-2">@csrf
        <input name="name" class="form-control form-control-sm" placeholder="new-role-name" required><button class="btn btn-sm btn-primary text-nowrap">Add role</button></form>
</div>
<div class="accordion" id="roles">
@foreach($roles as $role)
    <div class="accordion-item">
        <h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#role{{ $role->id }}">
            <strong class="me-2">{{ $role->name }}</strong><span class="text-muted small">{{ $role->users_count }} users · {{ $role->name === 'super-admin' ? 'all' : $role->permissions->count() }} permissions</span></button></h2>
        <div id="role{{ $role->id }}" class="accordion-collapse collapse" data-bs-parent="#roles"><div class="accordion-body">
            @if($role->name === 'super-admin')
                <p class="text-muted mb-0">Super admin always has every permission and sees every organization and module.</p>
            @else
            <form method="post" action="{{ route('admin.roles.update', $role) }}">@csrf @method('put')
                <div class="row">
                @foreach($permissions as $p)
                    <div class="col-md-6"><div class="form-check">
                        <input class="form-check-input" type="checkbox" name="permissions[]" value="{{ $p }}" id="rp{{ $role->id }}-{{ $loop->index }}" @checked($role->permissions->contains('name', $p))>
                        <label class="form-check-label small" for="rp{{ $role->id }}-{{ $loop->index }}"><code>{{ $p }}</code><br><span class="text-muted">{{ $labels[$p] ?? '' }}</span></label>
                    </div></div>
                @endforeach
                </div>
                <button class="btn btn-sm btn-primary mt-2">Save permissions</button>
            </form>
            @unless(array_key_exists($role->name, \Database\Seeders\RolesAndPermissionsSeeder::ROLES))
                <form method="post" action="{{ route('admin.roles.destroy', $role) }}" class="mt-2" onsubmit="return confirm('Delete role?')">@csrf @method('delete')<button class="btn btn-sm btn-link text-danger p-0">Delete role</button></form>
            @endunless
            @endif
        </div></div>
    </div>
@endforeach
</div>
@endsection

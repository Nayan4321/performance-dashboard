@extends('layouts.app')
@section('title', 'Users')
@section('content')
<div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0">Users</h1>
    <a href="{{ route('admin.users.create') }}" class="btn btn-primary btn-sm ms-auto"><i class="bi bi-person-plus"></i> Add user</a>
</div>
<form class="row g-2 mb-3">
    <div class="col-md-4"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Search name or email"></div>
    <div class="col-md-3"><select name="source" class="form-select form-select-sm"><option value="">All sources</option>@foreach(['manual','zenoti','callgear'] as $s)<option @selected(request('source') === $s)>{{ $s }}</option>@endforeach</select></div>
    <div class="col-md-3"><select name="role" class="form-select form-select-sm"><option value="">All roles</option>@foreach($roles as $r)<option @selected(request('role') === $r)>{{ $r }}</option>@endforeach</select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Filter</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th>Name</th><th>Email</th><th>Roles</th><th>Organization / branch</th><th>Source</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse($users as $user)
        <tr>
            <td>{{ $user->name }}</td>
            <td>{{ $user->email }}</td>
            <td>@foreach($user->roles as $r)<span class="badge text-bg-light border">{{ $r->name }}</span> @endforeach</td>
            <td>{{ $user->organization?->name ?? '—' }}{{ $user->branch ? ' / '.$user->branch->name : '' }}</td>
            <td><span class="badge text-bg-{{ $user->source === 'manual' ? 'secondary' : 'info' }}">{{ $user->source }}</span></td>
            <td>{!! $user->is_active ? '<span class="text-success">Active</span>' : '<span class="text-muted">Disabled</span>' !!}</td>
            <td class="text-end"><a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-outline-secondary">Edit</a></td>
        </tr>
    @empty
        <tr><td colspan="7" class="text-center text-muted py-4">No users found.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
<div class="mt-3">{{ $users->links() }}</div>
@endsection

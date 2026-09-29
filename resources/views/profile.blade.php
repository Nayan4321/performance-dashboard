@extends('layouts.app')
@section('title', 'My profile')
@section('content')
<div class="page-title-box"><h4 class="page-title">My profile</h4></div>
<form method="post" action="{{ route('profile') }}" enctype="multipart/form-data">
    @csrf @method('put')
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card"><div class="card-body text-center">
                @if($user->avatarUrl())
                    <img src="{{ $user->avatarUrl() }}" alt="" class="rounded-circle object-fit-cover mb-2" width="96" height="96">
                @else
                    <span class="thumb-xxl rounded-circle bg-primary-subtle text-primary mx-auto mb-2 d-flex align-items-center justify-content-center fs-2 fw-bold" style="width:96px;height:96px">{{ $user->initials() }}</span>
                @endif
                <h5 class="mb-0">{{ $user->name }}</h5>
                <p class="text-muted mb-3">{{ $user->getRoleNames()->map(fn ($r) => ucwords(str_replace('-', ' ', $r)))->implode(', ') ?: 'No role' }}</p>
                <label class="form-label small">Profile photo</label>
                <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" class="form-control form-control-sm">
                @if($user->avatar_path)
                    <div class="form-check text-start mt-2"><input class="form-check-input" type="checkbox" name="remove_avatar" value="1" id="rm-avatar"><label class="form-check-label small" for="rm-avatar">Remove photo</label></div>
                @endif
            </div></div>
        </div>
        <div class="col-lg-8">
            <div class="card" id="account">
                <div class="card-header"><h4 class="card-title">Account settings</h4></div>
                <div class="card-body">
                    <div class="mb-3"><label class="form-label">Name</label><input name="name" value="{{ old('name', $user->name) }}" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">Email</label><input value="{{ $user->email }}" class="form-control" disabled></div>
                    <div class="mb-0"><label class="form-label">Phone</label><input name="phone" value="{{ old('phone', $user->phone) }}" class="form-control"></div>
                </div>
            </div>
            <div class="card" id="security">
                <div class="card-header"><h4 class="card-title">Security</h4></div>
                <div class="card-body">
                    <p class="text-muted small">Leave blank to keep your current password.</p>
                    <div class="mb-3"><label class="form-label">Current password</label><input type="password" name="current_password" class="form-control" autocomplete="current-password"></div>
                    <div class="row g-2">
                        <div class="col-md"><label class="form-label">New password</label><input type="password" name="password" class="form-control" autocomplete="new-password"></div>
                        <div class="col-md"><label class="form-label">Confirm</label><input type="password" name="password_confirmation" class="form-control" autocomplete="new-password"></div>
                    </div>
                </div>
            </div>
            <button class="btn btn-primary">Save changes</button>
        </div>
    </div>
</form>
@endsection

@extends('layouts.app')
@section('title', 'Welcome')
@section('content')
<div class="card"><div class="card-body text-center py-5">
    <h1 class="h4">Welcome, {{ auth()->user()->name }}</h1>
    <p class="text-muted mb-0">There is nothing assigned to your account yet. Ask an administrator to give you access to a dashboard or module.</p>
    @can('dashboards.manage')<a href="{{ route('dashboards.create') }}" class="btn btn-primary mt-3">Create the first dashboard</a>@endcan
</div></div>
@endsection

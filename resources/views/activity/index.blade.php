@extends('layouts.app')
@section('title', 'Activity')
@section('content')
<h1 class="h4 mb-3">Activity <span class="text-muted small fw-normal">changes seen in Zenoti</span></h1>
<form class="row g-2 mb-3">
    <div class="col-md-4"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Guest or service"></div>
    <div class="col-md-3"><select name="branch_id" class="form-select form-select-sm"><option value="">All branches</option>@foreach($branches as $b)<option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
    <div class="col-md-3"><select name="action" class="form-select form-select-sm"><option value="">Everything</option>@foreach(\App\Models\ActivityLog::LABELS as $k => $label)<option value="{{ $k }}" @selected(request('action') === $k)>{{ $label }}</option>@endforeach</select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">Filter</button></div>
</form>
@include('activity._list')
@endsection

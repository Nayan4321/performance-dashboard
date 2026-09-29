@extends('layouts.app')
@section('title', $lead->exists ? 'Edit lead' : 'Add lead')
@section('content')
<h1 class="h4 mb-3">{{ $lead->exists ? 'Edit lead' : 'Add lead' }}</h1>
@if($lead->exists && $lead->source !== 'manual')<div class="alert alert-info small">This lead is synced from {{ $lead->source }}. Only stage and owner changes are kept here.</div>@endif
<div class="card" style="max-width:720px"><div class="card-body">
<form method="post" action="{{ $lead->exists ? route('leads.update', $lead) : route('leads.store') }}">
    @csrf @if($lead->exists) @method('put') @endif
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Name</label><input name="name" value="{{ old('name', $lead->name) }}" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label">Phone</label><input name="phone" value="{{ old('phone', $lead->phone) }}" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Email</label><input name="email" value="{{ old('email', $lead->email) }}" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Channel</label><input name="channel" value="{{ old('channel', $lead->channel) }}" class="form-control" placeholder="Walk-in, Instagram, Referral…"></div>
        <div class="col-md-6"><label class="form-label">Branch</label><select name="branch_id" class="form-select" required>@foreach($branches as $b)<option value="{{ $b->id }}" @selected(old('branch_id', $lead->branch_id) == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
        <div class="col-md-6"><label class="form-label">Owner</label><select name="employee_id" class="form-select"><option value="">—</option>@foreach($employees as $e)<option value="{{ $e->id }}" @selected(old('employee_id', $lead->employee_id) == $e->id)>{{ $e->full_name }}</option>@endforeach</select></div>
        <div class="col-md-4"><label class="form-label">Stage</label><select name="stage" class="form-select">@foreach($stages as $s)<option @selected(old('stage', $lead->stage) === $s)>{{ $s }}</option>@endforeach</select></div>
        <div class="col-md-4"><label class="form-label">Value</label><input type="number" step="0.01" name="value" value="{{ old('value', $lead->value) }}" class="form-control"></div>
        <div class="col-md-4"><label class="form-label">Date</label><input type="datetime-local" name="lead_at" value="{{ old('lead_at', $lead->lead_at?->format('Y-m-d\TH:i')) }}" class="form-control" required></div>
    </div>
    <button class="btn btn-primary mt-3">Save</button>
</form>
@if($lead->exists && $lead->source === 'manual')
<form method="post" action="{{ route('leads.destroy', $lead) }}" class="mt-2" onsubmit="return confirm('Delete lead?')">@csrf @method('delete')<button class="btn btn-sm btn-link text-danger p-0">Delete lead</button></form>
@endif
</div></div>
@endsection

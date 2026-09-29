@extends('layouts.app')
@section('title', 'Log a complaint')
@section('content')
<h1 class="h4 mb-3">Log a complaint</h1>
<div class="card" style="max-width:720px"><div class="card-body">
<form method="post" action="{{ route('complaints.store') }}">@csrf
    <div class="mb-3"><label class="form-label">Call</label>
        <select name="call_id" class="form-select">
            <option value="">Not in the list (type the number below)</option>
            @if($call && ! $recent->contains('id', $call->id))<option value="{{ $call->id }}" selected>{{ $call->started_at?->format('j M H:i') }} · {{ $call->caller }}{{ $call->guest ? ' · '.$call->guest->full_name : '' }}</option>@endif
            @foreach($recent as $r)<option value="{{ $r->id }}" @selected($call?->id === $r->id)>{{ $r->started_at?->format('j M H:i') }} · {{ $r->caller }}{{ $r->guest ? ' · '.$r->guest->full_name : '' }}{{ $r->duration_seconds ? ' · '.gmdate('i:s', $r->duration_seconds) : '' }}</option>@endforeach
        </select>
        <div class="form-text">Your calls from the last 3 days. The client's Zenoti profile is found from the number.</div></div>
    <div class="mb-3"><label class="form-label">Caller number <span class="text-muted small">(if the call isn't listed)</span></label><input name="phone" value="{{ old('phone') }}" class="form-control" placeholder="e.g. 0501234567"></div>
    @if($canPickAgent)
    <div class="mb-3"><label class="form-label">Agent</label><select name="employee_id" class="form-select"><option value="">The call's agent</option>@foreach($agents as $a)<option value="{{ $a->id }}" @selected(old('employee_id') == $a->id)>{{ $a->first_name }} {{ $a->last_name }}</option>@endforeach</select></div>
    @endif
    <div class="mb-3"><label class="form-label">Category <span class="text-muted small">(optional)</span></label><select name="category" class="form-select"><option value="">—</option>@foreach(\App\Models\Complaint::CATEGORIES as $k => $label)<option value="{{ $k }}" @selected(old('category') === $k)>{{ $label }}</option>@endforeach</select></div>
    <div class="mb-3"><label class="form-label">What the client said</label><textarea name="message" rows="5" class="form-control" required maxlength="5000">{{ old('message') }}</textarea></div>
    <button class="btn btn-primary">Submit complaint</button> <a href="{{ route('complaints.index') }}" class="btn btn-link">Cancel</a>
</form>
</div></div>
@endsection

@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
<h1 class="h4 mb-3">Notifications</h1>
<div class="list-group">
@forelse($notifications as $n)
    <a href="{{ ! empty($n->data['employee_id']) ? route('employees.show', $n->data['employee_id']) : route('activity.index', ['action' => 'deleted']) }}" class="list-group-item list-group-item-action {{ $n->read_at ? '' : 'list-group-item-warning' }}">
        <div class="d-flex"><strong>{{ $n->data['title'] ?? 'Notification' }}</strong><span class="ms-auto small text-muted">{{ $n->created_at->diffForHumans() }}</span></div>
        <div class="small">{{ $n->data['body'] ?? '' }}</div>
    </a>
@empty
    <div class="text-muted">No notifications. You'll be told here when an appointment or guest is deleted in Zenoti.</div>
@endforelse
</div>
<div class="mt-3">{{ $notifications->links() }}</div>
@endsection

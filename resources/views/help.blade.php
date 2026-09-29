@extends('layouts.app')
@section('title', 'Help Center')
@section('content')
<div class="page-title-box"><h4 class="page-title">Help Center</h4></div>
<div class="row g-3">
    @foreach([
        ['iconoir-home-simple', 'Dashboards', 'Pick a branch tab and an employee at the top of a dashboard. Numbers refresh on their own every minute. People who can manage dashboards can add cards and charts with "Add widget".'],
        ['iconoir-refresh-double', 'Where the data comes from', 'Guests, appointments, sales and employees are copied from Zenoti on a schedule; calls come from CallGear. Admins can run a sync from Integrations.'],
        ['iconoir-bell', 'Notifications', 'The bell shows alerts (records deleted in Zenoti), recent activity and, for admins, failed syncs. Opening it marks alerts as read.'],
        ['iconoir-lock', 'Password and profile', 'Change your name, photo and password from Profile in the menu at the top right. Forgot your password? Ask an administrator to reset it.'],
        ['iconoir-truck', 'Inventory', 'Branches request stock with a stock order; a stock manager approves it and an invoice is generated.'],
        ['iconoir-media-image', 'Logo', 'Super admins can upload the company logo under Administration, Branding & logo.'],
    ] as [$icon, $title, $text])
        <div class="col-md-6 col-xl-4">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <span class="thumb-md rounded-circle bg-primary-subtle text-primary me-2"><i class="{{ $icon }} fs-4"></i></span>
                    <h5 class="mb-0 fs-14">{{ $title }}</h5>
                </div>
                <p class="text-muted mb-0">{{ $text }}</p>
            </div></div>
        </div>
    @endforeach
</div>
@endsection

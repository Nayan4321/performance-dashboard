@php
    $brandName = \App\Support\Branding::name();
    $logo = \App\Support\Branding::logo();
    $logoDark = \App\Support\Branding::logoDark();
    $logoSmall = \App\Support\Branding::logoSmall();
@endphp
<!doctype html>
<html lang="en" dir="ltr" data-startbar="light" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ $brandName }}</title>
    <link rel="shortcut icon" href="{{ \App\Support\Branding::url('logo_small') ?? asset('vendor/rizz/images/favicon.ico') }}">
    <script>try { var t = localStorage.getItem('theme'); if (t) document.documentElement.setAttribute('data-bs-theme', t); } catch (e) {}</script>
    <link href="{{ asset('vendor/rizz/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/rizz/css/icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/rizz/css/simplebar.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/rizz/css/app.min.css') }}" rel="stylesheet">
    <link href="{{ asset('css/app.css') }}?v=14" rel="stylesheet">
    @stack('head')
</head>
<body>
@auth
@php($u = auth()->user())
    @include('layouts.partials.topbar')
    @include('layouts.partials.startbar')
    <div class="page-wrapper">
        <div class="page-content">
            <div class="container-xxl">
                @include('partials.flash')
                @yield('content')
            </div>
            <footer class="footer text-center text-sm-start d-print-none">
                <div class="container-xxl">
                    <div class="card mb-0 rounded-bottom-0">
                        <div class="card-body">
                            <p class="text-muted mb-0">© {{ date('Y') }} {{ $brandName }}</p>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    </div>
@else
    @yield('content')
@endauth
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('vendor/rizz/js/simplebar.min.js') }}"></script>
<script src="{{ asset('vendor/rizz/js/app.js') }}"></script>
<script src="{{ asset('js/layout.js') }}?v=14"></script>
@stack('scripts')
@auth
@if(\App\Support\AutoSync::due())
{{-- No cron: people using the site keep the automatic syncs going. --}}
<script>fetch(@json(route('autosync.nudge')), {method: 'POST', headers: {'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json'}}).catch(() => {});</script>
@endif
@endauth
</body>
</html>

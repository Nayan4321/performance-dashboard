@extends('layouts.app')
@section('title', $dashboard->name)
@section('content')
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <div class="dropdown">
        <button class="btn btn-light dropdown-toggle fw-semibold" data-bs-toggle="dropdown">{{ $dashboard->name }}</button>
        <ul class="dropdown-menu">
            @foreach($dashboards as $d)
                <li><a class="dropdown-item {{ $d->id === $dashboard->id ? 'active' : '' }}" href="{{ route('dashboards.show', $d) }}">{{ $d->name }}</a></li>
            @endforeach
            @can('dashboards.manage')
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="{{ route('dashboards.create') }}"><i class="bi bi-plus"></i> New dashboard</a></li>
            @endcan
        </ul>
    </div>
    @if($dashboard->description)<span class="text-muted small">{{ $dashboard->description }}</span>@endif
    @if($dashboard->employee_tag)<span class="badge bg-primary-subtle text-primary fw-medium" title="Every widget only counts employees with this tag"><i class="bi bi-tag"></i> {{ $dashboard->employee_tag }}</span>@endif
    <div class="ms-auto d-flex gap-2 align-items-center">
        <span class="updated-at" id="updatedAt"></span>
        <button class="btn btn-sm btn-outline-secondary" id="refreshBtn" title="Refresh now"><i class="bi bi-arrow-clockwise"></i></button>
        <div class="dropdown no-print">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-download"></i> Export</button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="#" id="exportExcel"><i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel (all widgets)</a></li>
                <li><a class="dropdown-item" href="#" id="exportPdf"><i class="bi bi-file-earmark-pdf me-1"></i> PDF / print</a></li>
            </ul>
        </div>
        @can('dashboards.manage')<a href="{{ route('dashboards.edit', $dashboard) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Edit</a>@endcan
    </div>
</div>

<div class="filter-bar mb-3">
    @if($branches->count() > 1)
    <ul class="nav nav-pills branch-tabs flex-nowrap overflow-auto mb-2" id="branchTabs">
        <li class="nav-item"><a class="nav-link active" href="#" data-branch="">All branches (summary)</a></li>
        @foreach($branches as $b)
            <li class="nav-item"><a class="nav-link" href="#" data-branch="{{ $b->id }}">{{ $b->name }}</a></li>
        @endforeach
    </ul>
    @endif
    <div class="d-flex flex-wrap gap-2 align-items-center">
        @if($canFilterEmployees && $tags->isNotEmpty())
        <select id="tagFilter" class="form-select form-select-sm" style="max-width:200px" title="Only employees with this tag">
            <option value="">All tags</option>@foreach($tags as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
        </select>
        @endif
        @if($canFilterEmployees)
        <select id="employeeFilter" class="form-select form-select-sm" style="max-width:220px"><option value="">All employees</option></select>
        @endif
        <select id="rangeFilter" class="form-select form-select-sm" style="max-width:210px">
            <option value="">Widget default dates</option>
            <option value="today">Today</option>
            <option value="7">Last 7 days</option>
            <option value="30">Last 30 days</option>
            <option value="month">This month</option>
            <option value="custom">Custom range…</option>
        </select>
        <span id="customRange" class="d-none d-flex gap-1">
            <input type="date" id="fromDate" class="form-control form-control-sm">
            <input type="date" id="toDate" class="form-control form-control-sm">
        </span>
    </div>
</div>

<div class="row g-3">
    @forelse($dashboard->widgets as $w)
        <div class="col-12 col-md-{{ min(12, max(6, $w->width)) }} col-xl-{{ $w->width }}">
            @php($bare = in_array($w->type, ['stat', 'sparkline'], true))
            <div class="card widget-card" data-widget="{{ $w->id }}" data-type="{{ $w->type }}"
                 data-url="{{ route('dashboards.widget-data', [$dashboard, $w]) }}"
                 data-records="{{ route('dashboards.widget-records', [$dashboard, $w]) }}" data-group="{{ $w->group_by }}"
                 data-title="{{ $w->title }}" data-icon="{{ $w->option('icon', 'graph-up') }}" data-color="{{ $w->option('color', 'primary') }}"
                 data-source="{{ \App\Support\Datasets::get($w->dataset)['source'] ?? '' }}"
                 data-range="{{ \App\Models\DashboardWidget::DATE_RANGES[$w->date_range] ?? '' }}"
                 data-money="{{ ['money' => 1, 'pct' => 'pct'][$w->format()] ?? 0 }}">
                @unless($bare)
                    <div class="card-header">
                        <div class="row align-items-center">
                            <div class="col"><h4 class="card-title">{{ $w->title }}</h4></div>
                            <div class="col-auto">@include('partials.source-badge', ['source' => \App\Support\Datasets::get($w->dataset)['source'] ?? ''])
                                <span class="badge bg-light text-muted fw-normal">{{ \App\Models\DashboardWidget::DATE_RANGES[$w->date_range] ?? '' }}</span></div>
                        </div>
                    </div>
                @endunless
                <div class="card-body {{ $bare ? '' : 'pt-0' }} widget-body"><div class="text-muted small">Loading…</div></div>
            </div>
        </div>
    @empty
        <div class="col-12"><div class="card"><div class="card-body text-center text-muted py-5">
            This dashboard has no widgets yet.
            @can('dashboards.manage')<a href="{{ route('dashboards.edit', $dashboard) }}">Add one</a>.@endcan
        </div></div></div>
    @endforelse
</div>
<div class="offcanvas offcanvas-end" tabindex="-1" id="recordsPanel" style="width:min(920px,100vw)">
    <div class="offcanvas-header border-bottom">
        <div>
            <h5 class="offcanvas-title mb-0" id="recordsTitle">Records</h5>
            <small class="text-muted" id="recordsSub"></small>
        </div>
        <div class="ms-auto d-flex gap-2 align-items-center">
            <a class="btn btn-sm btn-light" id="recordsCsv" href="#"><i class="bi bi-file-earmark-spreadsheet"></i> Excel</a>
            <button class="btn btn-sm btn-light" id="recordsPdf"><i class="bi bi-file-earmark-pdf"></i> PDF</button>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
    </div>
    <div class="offcanvas-body p-0" id="recordsBody"></div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
<script src="{{ asset('vendor/rizz/js/apexcharts.min.js') }}"></script>
<script>
window.DASHBOARD = {
    employeesUrl: @json(route('dashboards.employees', $dashboard)),
    refreshSeconds: {{ $refreshSeconds }},
    name: @json($dashboard->name),
    currency: @json(config('app.currency_symbol', '₹')),
};
</script>
<script src="{{ asset('js/dashboard.js') }}?v=23"></script>
@endpush

@php
    $can = fn ($perm) => $u->can($perm);
    $perf = $u->canAccessModule('performance') && $can('dashboards.view');
    $full = true; // CallGear-only roles see these pages limited to Callgear entries (User::callgearEmployeeIds)
    $menu = [
        'Performance' => [
            [$u->can('dashboards.view') && $u->canAccessModule('dashboards'), 'home', 'iconoir-home-simple', 'Dashboards', 'dashboards.show'],
            [$can('dashboards.manage') && $u->canAccessModule('dashboards'), 'dashboards.create', 'iconoir-plus-circle', 'New dashboard', 'dashboards.create,dashboards.edit'],
            [$perf && $full, 'employees.index', 'iconoir-group', 'Employees', 'employees.*'],
            [$perf && $full && Route::has('guests.index'), 'guests.index', 'iconoir-community', 'Guests', 'guests.*'],
            [$perf && $full && Route::has('appointments.index'), 'appointments.index', 'iconoir-calendar', 'Appointments', 'appointments.*'],
            [$perf && $full && Route::has('invoices.index'), 'invoices.index', 'iconoir-wallet', 'Invoices', 'invoices.*'],
            [$perf && $full && Route::has('sales.index'), 'sales.index', 'iconoir-dollar-circle', 'Sales', 'sales.*'],
            [$perf && $full && Route::has('activity.index'), 'activity.index', 'iconoir-activity', 'Activity', 'activity.*'],
            [$perf && Route::has('calls.index'), 'calls.index', 'iconoir-phone', 'Calls', 'calls.*'],
            [$full && $u->canAccessModule('performance') && $can('leads.manage'), 'leads.index', 'iconoir-filter-list', 'Leads', 'leads.*'],
        ],
        'Call center' => collect(\App\Http\Controllers\GuideController::GUIDES)->map(fn ($g, $slug) => [
            \App\Http\Controllers\GuideController::visibleTo($u), ['guides.show', $slug], $g[1], $g[0], request()->routeIs('guides.show') && request()->route('guide') === $slug,
        ])->values()->push([
            \App\Http\Controllers\ComplaintController::canView($u), 'complaints.index', 'iconoir-warning-triangle', 'Complaints', 'complaints.*',
        ])->all(),
        'Inventory' => [
            [$can('inventory.access') && $u->canAccessModule('inventory'), 'inventory.orders.index', 'iconoir-truck', 'Stock orders', 'inventory.orders.*,inventory.home,inventory.invoices.*'],
            [$can('inventory.access') && $u->canAccessModule('inventory'), 'inventory.stock', 'iconoir-box-iso', 'Stock levels', 'inventory.stock'],
            [$can('inventory.products.manage') && $u->canAccessModule('inventory'), 'inventory.products.index', 'iconoir-package', 'Products', 'inventory.products.*'],
        ],
        'Administration' => [
            [$can('users.manage'), 'admin.users.index', 'iconoir-user-circle', 'Users', 'admin.users.*'],
            [$can('roles.manage'), 'admin.roles.index', 'iconoir-shield', 'Roles & permissions', 'admin.roles.*'],
            [$can('organizations.manage'), 'admin.organizations.index', 'iconoir-building', 'Organizations', 'admin.organizations.*'],
            [$can('organizations.manage'), 'admin.modules.index', 'iconoir-view-grid', 'Modules', 'admin.modules.*'],
            [$u->isSuperAdmin() && Route::has('admin.branding.index'), 'admin.branding.index', 'iconoir-media-image', 'Branding & logo', 'admin.branding.*'],
            [$u->isSuperAdmin() && Route::has('admin.system-update.index'), 'admin.system-update.index', 'iconoir-cloud-upload', 'Upload update', 'admin.system-update.*'],
            [$can('integrations.manage'), 'admin.integrations.index', 'iconoir-refresh-double', 'Integrations', 'admin.integrations.*'],
        ],
    ];
@endphp
<div class="startbar d-print-none">
    <div class="brand">
        <a href="{{ route('home') }}" class="logo">
            <span>
                @if($logoSmall)
                    <img src="{{ $logoSmall }}" alt="" class="logo-sm brand-logo-sm">
                @else
                    <span class="logo-sm brand-initial">{{ mb_substr($brandName, 0, 1) }}</span>
                @endif
            </span>
            <span>
                @if($logo)
                    <img src="{{ $logoDark }}" alt="{{ $brandName }}" class="logo-lg logo-light brand-logo-lg">
                    <img src="{{ $logo }}" alt="{{ $brandName }}" class="logo-lg logo-dark brand-logo-lg">
                @else
                    <span class="logo-lg brand-name">{{ $brandName }}</span>
                @endif
            </span>
        </a>
    </div>
    <div class="startbar-menu">
        <div class="startbar-collapse" id="startbarCollapse" data-simplebar>
            <div class="d-flex align-items-start flex-column w-100">
                <ul class="navbar-nav mb-auto w-100">
                    @foreach($menu as $section => $items)
                        @php($items = array_filter($items, fn ($i) => $i[0]))
                        @continue(! $items)
                        <li class="menu-label {{ $loop->first ? 'pt-0 mt-0' : 'mt-2' }}"><span>{{ $section }}</span></li>
                        @foreach($items as [$show, $route, $icon, $label, $active])
                            @php($isActive = is_bool($active) ? $active : request()->routeIs(...explode(',', $active)))
                            <li class="nav-item {{ $isActive ? 'active' : '' }}">
                                <a class="nav-link {{ $isActive ? 'active' : '' }}" href="{{ is_array($route) ? route(...$route) : route($route) }}">
                                    <i class="{{ $icon }} menu-icon"></i><span>{{ $label }}</span>
                                </a>
                            </li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
<div class="startbar-overlay d-print-none"></div>

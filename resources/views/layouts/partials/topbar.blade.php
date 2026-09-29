@php
    $feed = \App\Support\NotificationFeed::for($u);
    $hour = (int) now()->format('G');
    $greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
    $role = $u->getRoleNames()->map(fn ($r) => ucwords(str_replace('-', ' ', $r)))->implode(', ') ?: 'No role';
@endphp
<div class="topbar d-print-none">
    <div class="container-xxl">
        <nav class="topbar-custom d-flex justify-content-between" id="topbar-custom">
            <ul class="topbar-item list-unstyled d-inline-flex align-items-center mb-0">
                <li>
                    <button class="nav-link mobile-menu-btn nav-icon" id="togglemenu" type="button" aria-label="Toggle menu">
                        <i class="iconoir-menu-scale"></i>
                    </button>
                </li>
                <li class="mx-3 welcome-text">
                    <h3 class="mb-0 fw-bold text-truncate">{{ $greeting }}, {{ strtok($u->name, ' ') }}!</h3>
                </li>
            </ul>
            <ul class="topbar-item list-unstyled d-inline-flex align-items-center mb-0">
                @if($u->can('dashboards.view') && $u->canAccessModule('performance') && Route::has('guests.index') && ! $u->callgearOnly())
                    <li class="hide-phone app-search">
                        <form role="search" action="{{ route('guests.index') }}" method="get">
                            <input type="search" name="q" value="{{ request()->routeIs('guests.index') ? request('q') : '' }}" class="form-control top-search mb-0" placeholder="Search guests...">
                            <button type="submit" aria-label="Search"><i class="iconoir-search"></i></button>
                        </form>
                    </li>
                @endif
                <li class="topbar-item">
                    <a class="nav-link nav-icon" href="javascript:void(0);" id="light-dark-mode" title="Light / dark mode">
                        <i class="iconoir-sun-light dark-mode"></i>
                        <i class="iconoir-half-moon light-mode"></i>
                    </a>
                </li>
                <li class="dropdown topbar-item">
                    <a class="nav-link dropdown-toggle arrow-none nav-icon" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false" id="notif-toggle"
                       data-read-url="{{ route('notifications.read') }}" title="Notifications">
                        <i class="iconoir-bell"></i>
                        @if($feed['unread'])<span class="alert-badge"></span>@endif
                    </a>
                    <div class="dropdown-menu stop dropdown-menu-end dropdown-lg py-0">
                        <h5 class="dropdown-item-text m-0 py-3 d-flex justify-content-between align-items-center">
                            Notifications
                            @if($feed['unread'])<span class="badge bg-danger-subtle text-danger badge-pill">{{ $feed['unread'] }} new</span>@endif
                        </h5>
                        <ul class="nav nav-tabs nav-tabs-custom nav-success nav-justified mb-1" role="tablist">
                            @foreach($feed['tabs'] as $key => $tab)
                                <li class="nav-item" role="presentation">
                                    <a class="nav-link mx-0 {{ $loop->first ? 'active' : '' }}" data-bs-toggle="tab" href="#notif-{{ $key }}" role="tab" aria-selected="{{ $loop->first ? 'true' : 'false' }}">
                                        {{ $tab['label'] }}
                                        @if($key === 'all' && $tab['items']->count())<span class="badge bg-primary-subtle text-primary badge-pill ms-1">{{ $tab['items']->count() }}</span>@endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        <div class="ms-0" style="max-height:300px;" data-simplebar>
                            <div class="tab-content">
                                @foreach($feed['tabs'] as $key => $tab)
                                    <div class="tab-pane fade {{ $loop->first ? 'show active' : '' }}" id="notif-{{ $key }}" role="tabpanel" tabindex="0">
                                        @forelse($tab['items'] as $item)
                                            <a href="{{ $item['url'] }}" class="dropdown-item py-3 {{ $item['unread'] ? 'bg-warning-subtle' : '' }}">
                                                <small class="float-end text-muted ps-2">{{ $item['at']?->diffForHumans(short: true) }}</small>
                                                <div class="d-flex align-items-center">
                                                    <div class="flex-shrink-0 bg-{{ $item['tone'] }}-subtle text-{{ $item['tone'] }} thumb-md rounded-circle">
                                                        <i class="{{ $item['icon'] }} fs-4"></i>
                                                    </div>
                                                    <div class="flex-grow-1 ms-2 text-truncate">
                                                        <h6 class="my-0 fw-normal text-dark fs-13 text-truncate">{{ $item['title'] }}</h6>
                                                        <small class="text-muted mb-0">{{ $item['body'] }}</small>
                                                    </div>
                                                </div>
                                            </a>
                                        @empty
                                            <div class="text-center text-muted small py-4">Nothing here yet.</div>
                                        @endforelse
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <a href="{{ route('notifications') }}" class="dropdown-item text-center text-dark fs-13 py-2">
                            View All <i class="iconoir-arrow-right align-middle"></i>
                        </a>
                    </div>
                </li>
                <li class="dropdown topbar-item">
                    <a class="nav-link dropdown-toggle arrow-none nav-icon" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false" title="{{ $u->name }}">
                        @if($u->avatarUrl())
                            <img src="{{ $u->avatarUrl() }}" alt="" class="thumb-lg rounded-circle object-fit-cover">
                        @else
                            <span class="thumb-lg rounded-circle bg-primary-subtle text-primary">{{ $u->initials() }}</span>
                        @endif
                    </a>
                    <div class="dropdown-menu dropdown-menu-end py-0">
                        <div class="d-flex align-items-center dropdown-item py-2 bg-secondary-subtle">
                            <div class="flex-shrink-0">
                                @if($u->avatarUrl())
                                    <img src="{{ $u->avatarUrl() }}" alt="" class="thumb-md rounded-circle object-fit-cover">
                                @else
                                    <span class="thumb-md rounded-circle bg-primary text-white">{{ $u->initials() }}</span>
                                @endif
                            </div>
                            <div class="flex-grow-1 ms-2 text-truncate align-self-center">
                                <h6 class="my-0 fw-medium text-dark fs-13">{{ $u->name }}</h6>
                                <small class="text-muted mb-0">{{ $role }}</small>
                            </div>
                        </div>
                        <div class="dropdown-divider mt-0"></div>
                        <small class="text-muted px-2 pb-1 d-block">Account</small>
                        <a class="dropdown-item" href="{{ route('profile') }}"><i class="iconoir-user fs-18 me-1 align-text-bottom"></i> Profile</a>
                        @if($u->employee && $u->can('dashboards.view') && $u->canAccessModule('performance'))
                            <a class="dropdown-item" href="{{ route('employees.show', $u->employee) }}"><i class="iconoir-graph-up fs-18 me-1 align-text-bottom"></i> My performance</a>
                        @endif
                        <a class="dropdown-item" href="{{ route('notifications') }}"><i class="iconoir-bell fs-18 me-1 align-text-bottom"></i> Notifications</a>
                        <small class="text-muted px-2 py-1 d-block">Settings</small>
                        <a class="dropdown-item" href="{{ route('profile') }}#account"><i class="iconoir-settings fs-18 me-1 align-text-bottom"></i> Account Settings</a>
                        <a class="dropdown-item" href="{{ route('profile') }}#security"><i class="iconoir-lock fs-18 me-1 align-text-bottom"></i> Security</a>
                        @if($u->isSuperAdmin())
                            <a class="dropdown-item" href="{{ route('admin.branding.index') }}"><i class="iconoir-media-image fs-18 me-1 align-text-bottom"></i> Branding &amp; logo</a>
                        @endif
                        <a class="dropdown-item" href="{{ route('help') }}"><i class="iconoir-help-circle fs-18 me-1 align-text-bottom"></i> Help Center</a>
                        <div class="dropdown-divider mb-0"></div>
                        <form method="post" action="{{ route('logout') }}">
                            @csrf
                            <button class="dropdown-item text-danger"><i class="iconoir-log-out fs-18 me-1 align-text-bottom"></i> Logout</button>
                        </form>
                    </div>
                </li>
            </ul>
        </nav>
    </div>
</div>

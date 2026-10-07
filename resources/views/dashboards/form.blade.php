@extends('layouts.app')
@section('title', $dashboard->exists ? 'Edit '.$dashboard->name : 'New dashboard')
@section('content')
<div class="d-flex align-items-center mb-3">
    <h1 class="h4 mb-0">{{ $dashboard->exists ? 'Edit dashboard' : 'New dashboard' }}</h1>
    @if($dashboard->exists)
        <a href="{{ route('dashboards.show', $dashboard) }}" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-eye"></i> View</a>
    @endif
</div>

<div class="card mb-4"><div class="card-body">
<form method="post" action="{{ $dashboard->exists ? route('dashboards.update', $dashboard) : route('dashboards.store') }}">
    @csrf @if($dashboard->exists) @method('put') @endif
    <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Name</label><input name="name" class="form-control" value="{{ old('name', $dashboard->name) }}" required></div>
        <div class="col-md-5"><label class="form-label">Description</label><input name="description" class="form-control" value="{{ old('description', $dashboard->description) }}"></div>
        <div class="col-md-3"><label class="form-label">Only employees tagged</label>
            <input name="employee_tag" list="dashTagNames" class="form-control" value="{{ old('employee_tag', $dashboard->employee_tag) }}" placeholder="Everyone">
            <datalist id="dashTagNames">@foreach(\App\Models\Tag::orderBy('name')->pluck('name') as $t)<option value="{{ $t }}">@endforeach</datalist>
            <div class="form-text">Every widget is limited to employees with this tag.</div></div>
        <div class="col-md-3"><label class="form-label">Order in list</label><input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', $dashboard->sort_order ?? 0) }}"></div>
        <div class="col-md-4">
            <label class="form-label">Organization</label>
            <select name="organization_id" class="form-select">
                <option value="">All organizations</option>
                @foreach($organizations as $o)<option value="{{ $o->id }}" @selected(old('organization_id', $dashboard->organization_id) == $o->id)>{{ $o->name }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-8">
            <label class="form-label">Visible to roles <span class="text-muted small">(none ticked = everyone who can view dashboards)</span></label>
            <div>
                @foreach($roles as $r)
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="visible_to_roles[]" value="{{ $r }}" id="role-{{ $r }}" @checked(in_array($r, old('visible_to_roles', $dashboard->visible_to_roles ?? [])))>
                        <label class="form-check-label" for="role-{{ $r }}">{{ $r }}</label>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary">{{ $dashboard->exists ? 'Save' : 'Create and add widgets' }}</button>
    </div>
</form>
@if($dashboard->exists)
    <form method="post" action="{{ route('dashboards.destroy', $dashboard) }}" class="mt-2" onsubmit="return confirm('Delete this dashboard and all its widgets?')">
        @csrf @method('delete')<button class="btn btn-sm btn-link text-danger p-0">Delete dashboard</button>
    </form>
@endif
</div></div>

@if($dashboard->exists)
    <h2 class="h5">Widgets</h2>
    <div class="list-group mb-4">
        @forelse($dashboard->widgets as $w)
            <div class="list-group-item">
                <div class="d-flex align-items-center gap-2">
                    <span class="badge text-bg-light border">{{ $types[$w->type] ?? $w->type }}</span>
                    <strong>{{ $w->title }}</strong>
                    <span class="text-muted small">{{ $datasets[$w->dataset]['label'] ?? $w->dataset }} · {{ $aggregates[$w->aggregate] ?? '' }}{{ $w->metric_field ? ' of '.$w->metric_field : '' }}{{ $w->group_by ? ' by '.$w->group_by : '' }}</span>
                    <button class="btn btn-sm btn-outline-secondary ms-auto" data-bs-toggle="collapse" data-bs-target="#w{{ $w->id }}">Edit</button>
                    <form method="post" action="{{ route('widgets.destroy', [$dashboard, $w]) }}" onsubmit="return confirm('Remove widget?')">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                </div>
                <div class="collapse mt-3" id="w{{ $w->id }}">
                    @include('dashboards._widget-form', ['widget' => $w, 'action' => route('widgets.update', [$dashboard, $w]), 'method' => 'put'])
                </div>
            </div>
        @empty
            <div class="list-group-item text-muted">No widgets yet.</div>
        @endforelse
    </div>

    <div class="card"><div class="card-body">
        <h2 class="h6">Add a widget</h2>
        @include('dashboards._widget-form', ['widget' => new \App\Models\DashboardWidget(['type' => 'kpi', 'dataset' => 'sales', 'aggregate' => 'count', 'width' => 3, 'date_range' => 'this_month']), 'action' => route('widgets.store', $dashboard), 'method' => 'post'])
    </div></div>
@endif
<datalist id="icon-suggestions">
    @foreach(['cash-stack','currency-dollar','receipt','cart','bag','people','person-check','person-plus','calendar-check','calendar-x','clock','telephone','telephone-inbound','star','heart','graph-up','graph-up-arrow','bar-chart','pie-chart','trophy','gift','scissors','box-seam','truck','funnel','percent','wallet2','credit-card'] as $i)<option value="{{ $i }}">@endforeach
</datalist>
@endsection

@push('scripts')
<script>
/* Keep the metric / group-by / filter dropdowns in sync with the chosen dataset. */
const DATASETS = @json($datasets);
function syncWidgetForm(form) {
    const ds = DATASETS[form.querySelector('[name=dataset]').value];
    const fill = (sel, opts, blank) => {
        const cur = sel.dataset.current ?? sel.value;
        sel.innerHTML = (blank ? '<option value="">' + blank + '</option>' : '') +
            Object.entries(opts).map(([k, v]) => '<option value="' + k + '"' + (k === cur ? ' selected' : '') + '>' + v + '</option>').join('');
        delete sel.dataset.current;
    };
    fill(form.querySelector('[name=metric_field]'), ds.numeric, '— (count rows)');
    fill(form.querySelector('[name=group_by]'), ds.groups, '— none (single number)');
    form.querySelectorAll('.filter-field').forEach(s => fill(s, ds.filters, '— no filter'));
}
const SINGLE = @json(\App\Models\DashboardWidget::SINGLE_VALUE);
function syncTypeOptions(form) {
    const type = form.querySelector('[name=type]').value;
    const show = (sel, on) => form.querySelectorAll(sel).forEach(el => el.classList.toggle('d-none', !on));
    show('.opt-icon', ['stat', 'sparkline'].includes(type));
    show('.opt-color', ['stat', 'sparkline', 'radial', 'area', 'column', 'hbar', 'progress'].includes(type));
    show('.opt-target', type === 'radial' || type === 'branch_table');
    show('.opt-agent', type === 'agent_table' || type === 'agent_bars');
    show('.opt-metric', type === 'agent_bars');
    show('.opt-revenue', type === 'agent_revenue' || type === 'agent_commission');
    show('.opt-commission', type === 'agent_commission' || type === 'agent_group_target');
    show('.opt-tags', type === 'agent_tags');
    const group = form.querySelector('[name=group_by]');
    group.disabled = SINGLE.includes(type) && type !== 'kpi';
}
document.querySelectorAll('form.widget-form').forEach(f => {
    syncWidgetForm(f);
    syncTypeOptions(f);
    f.querySelector('[name=dataset]').addEventListener('change', () => syncWidgetForm(f));
    f.querySelector('[name=type]').addEventListener('change', () => syncTypeOptions(f));
    const icon = f.querySelector('[name="options[icon]"]');
    icon.addEventListener('input', () => { icon.previousElementSibling.firstElementChild.className = 'bi bi-' + (icon.value || 'graph-up'); });
});
</script>
@endpush

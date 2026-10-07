@php($filters = array_pad((array) $widget->filters, 2, []))
<form method="post" action="{{ $action }}" class="widget-form">
    @csrf @if($method === 'put') @method('put') @endif
    <div class="row g-2">
        <div class="col-md-4"><label class="form-label small">Title</label><input name="title" class="form-control form-control-sm" value="{{ $widget->title }}" required></div>
        <div class="col-md-2"><label class="form-label small">Type</label>
            <select name="type" class="form-select form-select-sm widget-type">
                @foreach(['Classic' => array_diff_key($types, array_flip(\App\Models\DashboardWidget::RIZZ_TYPES)), 'Rizz style' => array_intersect_key($types, array_flip(\App\Models\DashboardWidget::RIZZ_TYPES))] as $groupLabel => $group)
                    <optgroup label="{{ $groupLabel }}">@foreach($group as $k => $v)<option value="{{ $k }}" @selected($widget->type === $k)>{{ $v }}</option>@endforeach</optgroup>
                @endforeach
            </select></div>
        <div class="col-md-3"><label class="form-label small">Dataset</label>
            <select name="dataset" class="form-select form-select-sm">@foreach($datasets as $k => $d)<option value="{{ $k }}" @selected($widget->dataset === $k)>{{ $d['label'] }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label small">Date range</label>
            <select name="date_range" class="form-select form-select-sm">@foreach($dateRanges as $k => $v)<option value="{{ $k }}" @selected($widget->date_range === $k)>{{ $v }}</option>@endforeach</select></div>

        <div class="col-md-2"><label class="form-label small">Measure</label>
            <select name="aggregate" class="form-select form-select-sm">@foreach($aggregates as $k => $v)<option value="{{ $k }}" @selected($widget->aggregate === $k)>{{ $v }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label small">Of field</label>
            <select name="metric_field" class="form-select form-select-sm" data-current="{{ $widget->metric_field }}"></select></div>
        <div class="col-md-3"><label class="form-label small">Group by</label>
            <select name="group_by" class="form-select form-select-sm" data-current="{{ $widget->group_by }}"></select></div>
        <div class="col-md-2"><label class="form-label small">Width</label>
            <select name="width" class="form-select form-select-sm">@foreach([3 => '1/4', 4 => '1/3', 6 => '1/2', 8 => '2/3', 12 => 'Full'] as $k => $v)<option value="{{ $k }}" @selected($widget->width == $k)>{{ $v }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label small">Position</label><input type="number" name="position" class="form-control form-control-sm" value="{{ $widget->position }}"></div>

        <div class="col-12 widget-options row g-2 m-0 p-0">
            <div class="col-md-3 opt-icon"><label class="form-label small">Icon <a href="https://icons.getbootstrap.com/" target="_blank" rel="noopener" class="text-muted">(Bootstrap icon name)</a></label>
                <div class="input-group input-group-sm"><span class="input-group-text"><i class="bi bi-{{ $widget->option('icon', 'graph-up') }}"></i></span>
                <input name="options[icon]" class="form-control" value="{{ $widget->option('icon') }}" placeholder="e.g. cash-stack, people, calendar-check" list="icon-suggestions"></div></div>
            <div class="col-md-3 opt-color"><label class="form-label small">Colour</label>
                <select name="options[color]" class="form-select form-select-sm">@foreach(\App\Models\DashboardWidget::COLORS as $k => $v)<option value="{{ $k }}" @selected($widget->option('color', 'primary') === $k)>{{ $v }}</option>@endforeach</select></div>
            <div class="col-md-3 opt-target"><label class="form-label small">Target (for the gauge)</label>
                <input type="number" step="any" min="0" name="options[target]" class="form-control form-control-sm" value="{{ $widget->option('target') }}" placeholder="e.g. 100000"></div>
            <div class="col-md-3 opt-agent"><label class="form-label small">Calls per day target</label>
                <input type="number" step="any" min="1" name="options[target_calls]" class="form-control form-control-sm" value="{{ $widget->option('target_calls') }}" placeholder="{{ \App\Support\WidgetQuery::CALL_TARGET }}"></div>
            <div class="col-md-3 opt-agent"><label class="form-label small">Talk minutes per day target</label>
                <input type="number" step="any" min="1" name="options[target_minutes]" class="form-control form-control-sm" value="{{ $widget->option('target_minutes') }}" placeholder="{{ \App\Support\WidgetQuery::TALK_TARGET }}"></div>
            @if($dashboard->employee_tag ?? null)
            <div class="col-md-3 d-flex align-items-end"><div class="form-check small mb-1">
                <input type="hidden" name="options[all_employees]" value="0">
                <input class="form-check-input" type="checkbox" name="options[all_employees]" value="1" id="allEmp{{ $widget->id ?? 'new' }}" @checked($widget->option('all_employees'))>
                <label class="form-check-label" for="allEmp{{ $widget->id ?? 'new' }}">Count everyone, not only "{{ $dashboard->employee_tag }}"</label></div></div>
            @endif
            <div class="col-md-3 opt-revenue"><label class="form-label small">Team revenue target (AED)</label>
                <input type="number" step="any" min="0" name="options[team_target]" class="form-control form-control-sm" value="{{ $widget->option('team_target') }}" placeholder="800000"></div>
            <div class="col-md-3 opt-revenue"><label class="form-label small">Tag of the team lead (not in the split)</label>
                <input name="options[lead_tag]" class="form-control form-control-sm" value="{{ $widget->option('lead_tag') }}" placeholder="Team lead"></div>
            <div class="col-md-3 opt-amount"><label class="form-label small">Amount column (all Callgear revenue widgets)</label>
                <select name="options[amount_column]" class="form-select form-select-sm">@foreach(\App\Support\WidgetQuery::REVENUE_COLUMNS as $k => $c)<option value="{{ $k }}" @selected(\App\Support\WidgetQuery::revenueColumn() === $k)>{{ $c[0] }}</option>@endforeach</select></div>
            <div class="col-md-6 opt-commission"><label class="form-label small">Commission tiers: team revenue = % (one per line)</label>
                <textarea name="options[tiers_text]" rows="4" class="form-control form-control-sm" placeholder="700000 = 0.4">{{ collect($widget->option('tiers', [[700000, 0.4], [800000, 0.5], [900000, 0.6], [1000000, 0.7]]))->map(fn ($t) => $t[0].' = '.$t[1])->implode("\n") }}</textarea></div>
            <div class="col-md-6 opt-tags"><label class="form-label small">Tags to show, comma separated (empty = the 8 most used)</label>
                <input name="options[tags_text]" class="form-control form-control-sm" value="{{ implode(', ', (array) $widget->option('tags', [])) }}" placeholder="Outgoing refreshment call, Outgoing new sale, Incoming booking call"></div>
            <div class="col-md-3 opt-metric"><label class="form-label small">Chart shows</label>
                <select name="options[metric]" class="form-select form-select-sm"><option value="calls">Calls per day</option><option value="talk" @selected($widget->option('metric') === 'talk')>Talk minutes per day</option></select></div>
            @if(isset($roles))
            <div class="col-12"><label class="form-label small mb-1">Visible to roles <span class="text-muted">(none ticked = everyone who sees this dashboard)</span></label>
                <div class="d-flex flex-wrap gap-3">@foreach($roles as $r)
                    <div class="form-check form-check-inline small mb-0"><input class="form-check-input" type="checkbox" name="options[roles][]" value="{{ $r }}" id="wr-{{ $widget->id ?? 'new' }}-{{ $r }}" @checked(in_array($r, (array) $widget->option('roles', [])))>
                        <label class="form-check-label" for="wr-{{ $widget->id ?? 'new' }}-{{ $r }}">{{ $r }}</label></div>
                @endforeach</div></div>
            @endif
        </div>

        @foreach($filters as $i => $f)
            <div class="col-md-4"><label class="form-label small">Filter {{ $i + 1 }}</label>
                <select name="filters[{{ $i }}][field]" class="form-select form-select-sm filter-field" data-current="{{ $f['field'] ?? '' }}"></select></div>
            <div class="col-md-2"><label class="form-label small">&nbsp;</label>
                <select name="filters[{{ $i }}][operator]" class="form-select form-select-sm">
                    @foreach(['=' => 'is', '!=' => 'is not', 'in' => 'is one of', 'not_in' => 'is none of'] as $k => $v)<option value="{{ $k }}" @selected(($f['operator'] ?? '=') === $k)>{{ $v }}</option>@endforeach
                </select></div>
        <div class="col-md-6"><label class="form-label small">&nbsp;</label><input name="filters[{{ $i }}][value]" class="form-control form-control-sm" value="{{ $f['value'] ?? '' }}" placeholder="Value (comma separated for 'one of')"></div>
        @endforeach
    </div>
    <button class="btn btn-sm btn-primary mt-2">{{ $method === 'put' ? 'Save widget' : 'Add widget' }}</button>
</form>

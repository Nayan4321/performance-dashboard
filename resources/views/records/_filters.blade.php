<form class="row g-2 mb-3">
    <div class="col-md-{{ isset($tags) ? 2 : 3 }}"><input name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="{{ $placeholder ?? 'Search' }}"></div>
    @isset($tags)
    <div class="col-md-1 px-md-1"><select name="tag_id" class="form-select form-select-sm" title="Only entries of employees with this tag (who served, booked or created them)"><option value="">All tags</option>@foreach($tags as $t)<option value="{{ $t->id }}" @selected(request('tag_id') == $t->id)>{{ $t->name }}</option>@endforeach</select></div>
    @endisset
    <div class="col-md-2"><select name="branch_id" class="form-select form-select-sm"><option value="">All branches</option>@foreach($branches as $b)<option value="{{ $b->id }}" @selected(request('branch_id') == $b->id)>{{ $b->name }}</option>@endforeach</select></div>
    <div class="col-md-2"><select name="period" class="form-select form-select-sm">
        @foreach(($periods ?? []) + ['today' => 'Today', 'this_week' => 'This week', 'this_month' => 'This month', 'last_month' => 'Last month', 'last_90_days' => 'Last 90 days'] as $k => $label)
            <option value="{{ $k }}" @selected(request('period', 'this_month') === $k && ! request('from'))>{{ $label }}</option>
        @endforeach
    </select></div>
    <div class="col-md-2"><input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm" title="From (overrides period)"></div>
    <div class="col-md-2"><input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm" title="To"></div>
    @foreach(["status", "category"] as $keep)@if(request($keep))<input type="hidden" name="{{ $keep }}" value="{{ request($keep) }}">@endif @endforeach
    <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary w-100">Filter</button></div>
</form>

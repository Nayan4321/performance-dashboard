<?php

namespace App\Http\Controllers;

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Support\Datasets;
use App\Support\WidgetQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardWidgetController extends Controller
{
    public function store(Request $request, Dashboard $dashboard)
    {
        $dashboard->widgets()->create($this->validated($request) + ['position' => $dashboard->widgets()->max('position') + 1]);

        return back()->with('status', 'Widget added.');
    }

    public function update(Request $request, Dashboard $dashboard, DashboardWidget $widget)
    {
        abort_unless($widget->dashboard_id === $dashboard->id, 404);
        $widget->update($this->validated($request));

        return back()->with('status', 'Widget saved.');
    }

    public function destroy(Dashboard $dashboard, DashboardWidget $widget)
    {
        abort_unless($widget->dashboard_id === $dashboard->id, 404);
        $widget->delete();

        return back()->with('status', 'Widget removed.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'type' => ['required', Rule::in(array_keys(DashboardWidget::TYPES))],
            'dataset' => ['required', Rule::in(array_keys(Datasets::all()))],
            'aggregate' => ['required', Rule::in(array_keys(WidgetQuery::AGGREGATES))],
            'metric_field' => 'nullable|string',
            'group_by' => 'nullable|string',
            'date_range' => ['required', Rule::in(array_keys(DashboardWidget::DATE_RANGES))],
            'width' => 'required|integer|in:3,4,6,8,12',
            'position' => 'nullable|integer',
            'filters' => 'nullable|array',
            'filters.*.field' => 'nullable|string',
            'filters.*.operator' => 'nullable|in:=,!=,in,not_in',
            'filters.*.value' => 'nullable|string|max:255',
            'options' => 'nullable|array',
            'options.icon' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/'],
            'options.color' => ['nullable', Rule::in(array_keys(DashboardWidget::COLORS))],
            'options.target' => 'nullable|numeric|min:0',
            'options.target_calls' => 'nullable|numeric|min:1',
            'options.target_minutes' => 'nullable|numeric|min:1',
            'options.metric' => 'nullable|in:calls,talk',
            'options.all_employees' => 'nullable|boolean',
            'options.team_target' => 'nullable|numeric|min:0',
            'options.lead_tag' => 'nullable|string|max:60',
            'options.tiers_text' => 'nullable|string|max:1000',
            'options.tags_text' => 'nullable|string|max:1000',
            'options.amount_column' => ['nullable', Rule::in(array_keys(WidgetQuery::REVENUE_COLUMNS))],
        ]);
        // One amount column for all Callgear revenue widgets, so they always agree.
        if (in_array($data['type'], WidgetQuery::AGENT_REVENUE_TYPES, true) && filled($data['options']['amount_column'] ?? null)) {
            \App\Models\Setting::put('callgear.revenue_column', $data['options']['amount_column']);
        }
        // "700000 = 0.4" lines become [[700000, 0.4], ...]; tag names become a list.
        $opts = $data['options'] ?? [];
        if (filled($opts['tiers_text'] ?? null)) {
            $opts['tiers'] = collect(preg_split('/\r?\n/', $opts['tiers_text']))
                ->map(fn ($l) => preg_match('/^\s*([\d,.]+)\s*[=:]\s*([\d.]+)\s*%?\s*$/', $l, $m) ? [(float) str_replace(',', '', $m[1]), (float) $m[2]] : null)
                ->filter()->sortBy(0)->values()->all() ?: null;
        }
        if (array_key_exists('tags_text', $opts)) {
            $opts['tags'] = array_values(array_filter(array_map('trim', explode(',', (string) $opts['tags_text'])))) ?: null;
        }
        $data['options'] = $opts;
        $data['options'] = array_filter(
            array_intersect_key($data['options'] ?? [], array_flip(['icon', 'color', 'target', 'target_calls', 'target_minutes', 'metric', 'all_employees', 'team_target', 'lead_tag', 'tiers', 'tags'])),
            fn ($v) => $v !== null && $v !== ''
        ) ?: null;

        $data['metric_field'] ??= null;
        $data['group_by'] ??= null;
        $ds = Datasets::get($data['dataset']);
        if ($data['metric_field'] && ! array_key_exists($data['metric_field'], $ds['numeric'])) {
            $data['metric_field'] = null;
        }
        if ($data['group_by'] && ! array_key_exists($data['group_by'], $ds['groups'])) {
            $data['group_by'] = null;
        }
        if (in_array($data['type'], DashboardWidget::SINGLE_VALUE, true)) {
            $data['group_by'] = $data['type'] === 'kpi' ? $data['group_by'] : null;
        } elseif (! $data['group_by']) {
            throw ValidationException::withMessages(['group_by' => 'Charts, funnels and tables need a "Group by" field.']);
        }
        $data['filters'] = collect($data['filters'] ?? [])
            ->filter(fn ($f) => ! empty($f['field']) && array_key_exists($f['field'], $ds['filters']))
            ->values()->all();
        if (! isset($data['position'])) {
            unset($data['position']);
        }

        return $data;
    }
}

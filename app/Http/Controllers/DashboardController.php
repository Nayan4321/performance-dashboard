<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Organization;
use App\Support\Datasets;
use App\Support\WidgetQuery;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class DashboardController extends Controller
{
    /** Landing page: first dashboard the user can see, otherwise the first module they have. */
    public function home(Request $request)
    {
        $user = $request->user();
        if ($user->can('dashboards.view') && $user->canAccessModule(Module::DASHBOARDS)) {
            $first = Dashboard::visibleTo($user)->first();
            if ($first) {
                return redirect()->route('dashboards.show', $first);
            }
        }
        if ($user->can('inventory.access') && $user->canAccessModule(Module::INVENTORY)) {
            return redirect()->route('inventory.home');
        }

        return view('welcome-empty');
    }

    public function show(Request $request, Dashboard $dashboard)
    {
        $user = $request->user();
        abort_unless($dashboard->isVisibleTo($user), 403);

        $allowed = $user->visibleBranchIds();
        $branches = Branch::where('is_active', true)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?: [0]))
            ->when($dashboard->organization_id, fn ($q) => $q->where('organization_id', $dashboard->organization_id))
            ->orderBy('name')->get();

        return view('dashboards.show', [
            'dashboard' => $dashboard->load('widgets'),
            'dashboards' => Dashboard::visibleTo($user),
            'branches' => $branches,
            'canFilterEmployees' => $user->visibleEmployeeId() === null,
            'tags' => \App\Models\Tag::orderBy('name')->get(),
            'refreshSeconds' => (int) config('app.dashboard_refresh_seconds', 60),
        ]);
    }

    public function widgetData(Request $request, Dashboard $dashboard, DashboardWidget $widget)
    {
        abort_unless($widget->dashboard_id === $dashboard->id && $dashboard->isVisibleTo($request->user()), 404);
        $filters = $request->validate([
            'branch_id' => 'nullable|integer',
            'employee_id' => 'nullable|integer',
            'tag_id' => 'nullable|integer',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $query = new WidgetQuery(
            $request->user(),
            $filters['branch_id'] ?? null,
            $filters['employee_id'] ?? null,
            $filters['from'] ?? null,
            $filters['to'] ?? null,
            $filters['tag_id'] ?? null,
        );

        return response()->json($query->run($widget) + ['updated_at' => now()->toIso8601String()]);
    }

    /** Rows behind a widget or one bar/slice of it; ?format=csv downloads them. */
    public function widgetRecords(Request $request, Dashboard $dashboard, DashboardWidget $widget)
    {
        abort_unless($widget->dashboard_id === $dashboard->id && $dashboard->isVisibleTo($request->user()), 404);
        $f = $request->validate([
            'branch_id' => 'nullable|integer', 'employee_id' => 'nullable|integer', 'tag_id' => 'nullable|integer', 'from' => 'nullable|date', 'to' => 'nullable|date',
            'key' => 'nullable|string|max:255', 'field' => 'nullable|string|max:40',
        ]);
        $field = $f['field'] ?? null;
        $ds = \App\Support\Datasets::get($widget->dataset);
        if ($field && ! (in_array($field, ['branch', 'employee', 'day', 'week', 'weekday', 'month'], true) || array_key_exists($field, $ds['groups'] ?? []))) {
            $field = null;
        }
        $q = new WidgetQuery($request->user(), $f['branch_id'] ?? null, $f['employee_id'] ?? null, $f['from'] ?? null, $f['to'] ?? null, $f['tag_id'] ?? null);
        $data = $q->records($widget, $request->has('key') ? (string) ($f['key'] ?? '') : null, $field);

        if ($request->query('format') === 'csv' && ! isset($data['error'])) {
            $name = \Illuminate\Support\Str::slug($widget->title).'.csv';

            return response()->streamDownload(function () use ($data) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, array_column($data['columns'], 0));
                foreach ($data['rows'] as $row) {
                    fputcsv($out, $row);
                }
                fclose($out);
            }, $name, ['Content-Type' => 'text/csv']);
        }

        return response()->json($data);
    }

    /** Employees for the filter dropdown, limited to the selected branch and the user's scope. */
    public function employees(Request $request, Dashboard $dashboard)
    {
        $allowed = $request->user()->visibleBranchIds();

        return Employee::where('is_active', true)
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed ?: [0]))
            ->when($request->integer('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))
            ->when($request->integer('tag_id'), fn ($q, $t) => $q->whereHas('tags', fn ($w) => $w->whereKey($t)))
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            ->map(fn ($e) => ['id' => $e->id, 'name' => $e->full_name]);
    }

    // ---------------------------------------------------------------- builder

    public function create()
    {
        return view('dashboards.form', ['dashboard' => new Dashboard, ...$this->formOptions()]);
    }

    public function store(Request $request)
    {
        $dashboard = Dashboard::create($this->validated($request) + ['created_by' => $request->user()->id]);

        return redirect()->route('dashboards.edit', $dashboard)->with('status', 'Dashboard created. Now add widgets.');
    }

    public function edit(Dashboard $dashboard)
    {
        return view('dashboards.form', ['dashboard' => $dashboard->load('widgets'), ...$this->formOptions()]);
    }

    public function update(Request $request, Dashboard $dashboard)
    {
        $dashboard->update($this->validated($request));

        return back()->with('status', 'Dashboard saved.');
    }

    public function destroy(Dashboard $dashboard)
    {
        $dashboard->delete();

        return redirect()->route('home')->with('status', 'Dashboard deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:255',
            'organization_id' => 'nullable|exists:organizations,id',
            'visible_to_roles' => 'nullable|array',
            'visible_to_roles.*' => 'string|exists:roles,name',
            'sort_order' => 'nullable|integer|min:0',
            'employee_tag' => 'nullable|string|max:60',
        ]);
        $data['employee_tag'] = trim((string) ($data['employee_tag'] ?? '')) ?: null;
        $data['visible_to_roles'] = $data['visible_to_roles'] ?? null ?: null;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }

    private function formOptions(): array
    {
        return [
            'organizations' => Organization::orderBy('name')->get(),
            'roles' => Role::orderBy('name')->pluck('name'),
            'datasets' => Datasets::all(),
            'types' => DashboardWidget::TYPES,
            'aggregates' => WidgetQuery::AGGREGATES,
            'dateRanges' => DashboardWidget::DATE_RANGES,
        ];
    }
}

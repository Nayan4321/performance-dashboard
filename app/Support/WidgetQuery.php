<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\DashboardWidget;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use App\Models\Tag;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Turns a widget definition + the dashboard filter bar (branch / employee /
 * date range) into chart-ready data.
 */
class WidgetQuery
{
    public const AGGREGATES = ['count' => 'Count', 'sum' => 'Sum', 'avg' => 'Average', 'min' => 'Minimum', 'max' => 'Maximum',
        // Grand Flora KPI definitions (sales dataset only; "Of field" is ignored)
        'invoices' => 'Footfall: distinct invoices',
        'atv' => 'ATV: sales ÷ footfall',
        'per_service' => 'Average per line: sales ÷ line count',
        'discount_pct' => 'Discount %: discount ÷ (sales + discount)',
        'new_guests' => 'New guests: no earlier invoice',
        'returning_guests' => 'Returning guests: earlier invoice exists',
        // CallGear (calls dataset only)
        'talk_minutes' => 'Talk time in minutes (calls)',
    ];

    /** Aggregates that only make sense on the sales table. */
    public const SALES_ONLY = ['invoices', 'atv', 'per_service', 'discount_pct', 'new_guests', 'returning_guests'];

    public function __construct(
        private User $user,
        private ?int $branchId = null,
        private ?int $employeeId = null,
        private ?string $from = null,
        private ?string $to = null,
        private ?int $tagId = null,
    ) {}

    /** The dataset, with sales credited to who entered the invoice (option credit=created_by) or appointments to who booked them (credit=booked_by). */
    private function dataset(DashboardWidget $widget): ?array
    {
        $ds = Datasets::get($widget->dataset);
        // Call agents' revenue widgets always credit the invoice creator (how the client's team counts it).
        if ($ds && $ds['table'] === 'sales' && ($widget->option('credit') === 'created_by' || in_array($widget->type, self::AGENT_REVENUE_TYPES, true))) {
            $ds['employee'] = 'created_by_employee_id';
        }
        // Appointments credited to whoever booked them, counted by booking date.
        if ($ds && $ds['table'] === 'appointments' && $widget->option('credit') === 'booked_by') {
            $ds['employee'] = 'booked_by_employee_id';
            $ds['date'] = 'booked_at';
        }

        return $ds;
    }

    public function run(DashboardWidget $widget): array
    {
        $ds = $this->dataset($widget);
        if (! $ds) {
            return ['error' => 'Unknown dataset'];
        }
        if ($this->user->callgearOnly() && ! $widget->allowedForCallgearOnly()) {
            return ['error' => 'Your role can only see CallGear data.'];
        }

        $query = $this->baseQuery($widget, $ds);
        $valueExpr = $this->valueExpression($widget, $ds);

        if ($widget->type === 'branch_table') {
            return $this->branchTable($widget, $ds);
        }
        if ($widget->type === 'employee_table') {
            return $this->employeeTable($widget, $ds);
        }
        if ($widget->type === 'agent_table' || $widget->type === 'agent_bars') {
            return $this->agentScores($widget, $ds);
        }
        if (in_array($widget->type, self::AGENT_REVENUE_TYPES, true)) {
            return $this->agentRevenue($widget);
        }
        if ($widget->type === 'agent_tags') {
            return $this->agentTags($widget);
        }

        if (in_array($widget->type, ['stat', 'sparkline', 'radial'], true)) {
            return $this->single($widget, $ds, $valueExpr);
        }

        if ($widget->type === 'kpi' || ! $widget->group_by) {
            return ['type' => $widget->type, 'value' => round((float) ($query->selectRaw("$valueExpr as v")->value('v') ?? 0), 2)];
        }

        [$groupExpr, $isRelation] = $this->groupExpression($widget->group_by, $ds);
        $rows = $query->selectRaw("$groupExpr as g, $valueExpr as v")
            ->groupByRaw($groupExpr)
            ->get()
            ->map(fn ($r) => ['g' => $r->g, 'v' => round((float) $r->v, 2)]);

        $labels = $this->labelsFor($widget->group_by, $rows->pluck('g')->all());
        $rows = $rows->map(fn ($r) => ['label' => $labels[$r['g']] ?? ($r['g'] ?? '(none)'), 'value' => $r['v'], 'key' => $r['g']]);

        if (in_array($widget->group_by, ['day', 'week', 'month', 'weekday'], true)) {
            $rows = $rows->sortBy('key');
        } elseif ($widget->group_by === 'status' && in_array($ds['table'], ['appointments'], true)) {
            $order = array_flip(\App\Models\Appointment::STATUSES);
            $rows = $rows->sortBy(fn ($r) => $order[$r['key']] ?? 99);
        } elseif ($widget->type === 'funnel' && $widget->group_by === 'stage') {
            $order = array_flip(Lead::STAGES);
            $rows = $rows->sortBy(fn ($r) => $order[$r['key']] ?? 99);
        } else {
            $rows = $rows->sortByDesc('value');
        }

        if ($widget->type === 'table') {
            $rows = $rows->take(25);
        } elseif (! in_array($widget->group_by, ['day', 'week', 'month', 'weekday'], true)) {
            $rows = $rows->take(20);
        }

        $rows = $rows->values();

        return [
            'type' => $widget->type,
            'labels' => $rows->pluck('label')->all(),
            'keys' => $rows->pluck('key')->all(),
            'values' => $rows->pluck('value')->all(),
            'total' => round($rows->sum('value'), 2),
        ];
    }

    /** One number plus what the rizz cards need: change vs the previous period, a daily trend, or % of target. */
    private function single(DashboardWidget $widget, array $ds, string $valueExpr): array
    {
        $value = round((float) ($this->baseQuery($widget, $ds)->selectRaw("$valueExpr as v")->value('v') ?? 0), 2);
        $out = ['type' => $widget->type, 'value' => $value];

        if ($widget->type === 'radial') {
            $target = (float) $widget->option('target', 0);
            $out['target'] = $target ?: null;
            $out['percent'] = $target > 0 ? round($value / $target * 100, 1) : null;

            return $out;
        }

        [$from, $to] = $ds['date'] ? $this->dateRange($widget->date_range) : [null, null];
        if ($from && $to) {
            [$pFrom, $pTo] = $this->previousRange($from, $to, $widget->date_range);
            $prev = round((float) ($this->baseQuery($widget, $ds, [$pFrom, $pTo])->selectRaw("$valueExpr as v")->value('v') ?? 0), 2);
            $out['previous'] = $prev;
            $out['change'] = $prev != 0.0 ? round(($value - $prev) / abs($prev) * 100, 1) : null;
            $out['compare_label'] = $this->compareLabel($widget->date_range);
        }

        if ($widget->type === 'sparkline' && $ds['date']) {
            [$groupExpr] = $this->groupExpression('day', $ds);
            $rows = $this->baseQuery($widget, $ds)->selectRaw("$groupExpr as g, $valueExpr as v")
                ->groupByRaw($groupExpr)->orderByRaw($groupExpr)->get();
            $out['labels'] = $rows->pluck('g')->all();
            $out['values'] = $rows->map(fn ($r) => round((float) $r->v, 2))->all();
        }

        return $out;
    }

    /** Columns shown when a chart is clicked: [sql, label, format]. */
    private const RECORD_COLUMNS = [
        'appointments' => [['t.start_time', 'Date', 'd'], ['g.first_name', 'Guest', 'guest'], ['t.service_name', 'Service', 's'], ['t.status', 'Status', 's'], ['e.first_name', 'Employee', 'emp'], ['b.name', 'Branch', 's'], ['t.price', 'Price', 'm']],
        'sales' => [['t.sold_at', 'Date', 'd'], ['t.invoice_no', 'Invoice', 's'], ['g.first_name', 'Guest', 'guest'], ['t.item_name', 'Item', 's'], ['t.category', 'Type', 's'], ['e.first_name', 'Employee', 'emp'], ['b.name', 'Branch', 's'], ['t.discount', 'Discount', 'm'], ['t.net_amount', 'Sales ex VAT', 'm']],
        'guests' => [['t.registered_at', 'Registered', 'd'], ['t.first_name', 'Guest', 'self'], ['t.phone', 'Phone', 's'], ['t.gender', 'Gender', 's'], ['b.name', 'Branch', 's']],
        'calls' => [['t.started_at', 'Date', 'd'], ['t.direction', 'Direction', 's'], ['t.caller', 'From', 's'], ['t.callee', 'To', 's'], ['t.status', 'Status', 's'], ['g.first_name', 'Guest', 'guest'], ['e.first_name', 'Employee', 'emp'], ['b.name', 'Branch', 's'], ['t.duration_seconds', 'Seconds', 'n']],
        'leads' => [['t.lead_at', 'Date', 'd'], ['t.name', 'Name', 's'], ['t.phone', 'Phone', 's'], ['t.source', 'Source', 's'], ['t.channel', 'Channel', 's'], ['t.stage', 'Stage', 's'], ['e.first_name', 'Employee', 'emp'], ['b.name', 'Branch', 's'], ['t.value', 'Value', 'm']],
        'collections' => [['t.collected_at', 'Date', 'd'], ['t.invoice_no', 'Invoice', 's'], ['g.first_name', 'Guest', 'guest'], ['t.payment_type', 'Payment', 's'], ['e.first_name', 'Employee', 'emp'], ['b.name', 'Branch', 's'], ['t.amount', 'Amount', 'm']],
    ];

    public const RECORD_LIMIT = 500;

    /**
     * The rows behind a widget (or behind one bar / slice when $key is given), for the click-through panel.
     * Same scoping and filters as the number itself.
     */
    public function records(DashboardWidget $widget, ?string $key = null, ?string $field = null): array
    {
        $ds = $this->dataset($widget);
        $table = $ds['table'] ?? null;
        if (! $ds || ! isset(self::RECORD_COLUMNS[$table])) {
            return ['error' => 'This dataset has no record list'];
        }
        if ($this->user->callgearOnly() && ! $widget->allowedForCallgearOnly()) {
            return ['error' => 'Your role can only see CallGear data.'];
        }
        if (in_array($widget->type, self::AGENT_REVENUE_TYPES, true)) {
            return $this->agentRevenueRecords($widget, $key);
        }
        $query = $this->baseQuery($widget, $ds);
        $group = $field ?? $widget->group_by;
        if ($key !== null && $group) {
            [$expr] = $this->groupExpression($group, $ds);
            $key === '' ? $query->whereRaw("$expr IS NULL") : $query->whereRaw("$expr = ?", [$group === 'weekday' || ctype_digit($key) && in_array($group, ['branch', 'employee'], true) ? (int) $key : $key]);
        }
        $total = (clone $query)->count();

        $cols = self::RECORD_COLUMNS[$table];
        $hasGuest = collect($cols)->contains(fn ($c) => $c[2] === 'guest');
        $hasEmp = collect($cols)->contains(fn ($c) => $c[2] === 'emp');
        $query->leftJoin('branches as b', 'b.id', '=', "$table.branch_id");
        if ($hasGuest) {
            $query->leftJoin('guests as g', 'g.id', '=', "$table.guest_id");
        }
        if ($hasEmp) {
            $query->leftJoin('employees as e', 'e.id', '=', "$table.".($ds['employee'] ?: 'employee_id'));
        }
        $select = [];
        foreach ($cols as $i => [$expr, , $fmt]) {
            $prefix = ['guest' => 'g', 'emp' => 'e', 'self' => $table][$fmt] ?? null;
            if ($prefix) {
                $select[] = "$prefix.first_name as c{$i}_f";
                $select[] = "$prefix.last_name as c{$i}_l";
            } else {
                $select[] = str_replace('t.', "$table.", $expr)." as c$i";
            }
        }
        if ($hasGuest) {
            $select[] = 'g.zenoti_id as _guest';
        } elseif ($table === 'guests') {
            $select[] = 'guests.zenoti_id as _guest';
        }
        $rows = $query->select($select)->orderByDesc(str_replace('t.', "$table.", $cols[0][0]))->limit(self::RECORD_LIMIT)->get()
            ->map(function ($r) use ($cols) {
                foreach ($cols as $i => $c) {
                    if (in_array($c[2], ['guest', 'emp', 'self'], true)) {
                        $r->{"c$i"} = trim(($r->{"c{$i}_f"} ?? '').' '.($r->{"c{$i}_l"} ?? '')) ?: null;
                    }
                }

                return $r;
            });

        return [
            'total' => $total,
            'shown' => $rows->count(),
            'columns' => array_map(fn ($c) => [$c[1], in_array($c[2], ['guest', 'emp', 'self'], true) ? 's' : $c[2]], $cols),
            'rows' => $rows->map(fn ($r) => array_merge(
                array_map(fn ($i) => $r->{"c$i"} === '' ? null : $r->{"c$i"}, array_keys($cols)),
            ))->values()->all(),
            'guest_links' => $rows->map(fn ($r) => isset($r->_guest) && $r->_guest ? rtrim(config('zenoti.web_url'), '/').'/Guests/GuestProfileV2/GuestProfileV2.aspx?UserId='.$r->_guest : null)->all(),
        ];
    }

    /** Module A detail table: one row per branch with footfall, sales, ATV, discount % (sales dataset). */
    private function branchTable(DashboardWidget $widget, array $ds): array
    {
        if ($ds['table'] !== 'sales') {
            return ['error' => 'The branch table needs the Sales dataset'];
        }
        $visit = 'COUNT(DISTINCT COALESCE(sales.invoice_no, CAST(sales.id AS CHAR)))';
        $rows = $this->baseQuery($widget, $ds)
            ->selectRaw("sales.branch_id as b, SUM(sales.net_amount) as s, $visit as f, SUM(sales.discount) as d")
            ->groupBy('sales.branch_id')->get();
        $names = Branch::whereIn('id', $rows->pluck('b')->filter())->pluck('name', 'id');
        $target = (float) $widget->option('target', 0);

        return [
            'type' => 'branch_table',
            'columns' => [['branch', 'Branch', 's'], ['footfall', 'Footfall', 'n'], ['sales', 'Sales', 'm'], ['atv', 'ATV', 'm'], ['discount_pct', 'Discount %', 'p'],
                ['target', 'Target', 'm'], ['achievement', 'Achievement %', 'p'], ['variance', 'Variance', 'm']],
            'sort' => 'sales',
            'rows' => $rows->map(fn ($r) => [
                'key' => $r->b,
                'branch' => $names[$r->b] ?? '(no branch)',
                'footfall' => (int) $r->f,
                'sales' => round((float) $r->s, 2),
                'atv' => $r->f ? round($r->s / $r->f, 2) : 0,
                'discount_pct' => ($r->s + $r->d) > 0 ? round($r->d * 100 / ($r->s + $r->d), 1) : 0,
                'target' => $target ?: null,
                'achievement' => $target > 0 ? round($r->s / $target * 100, 1) : null,
                'variance' => $target > 0 ? round($r->s - $target, 2) : null,
            ])->sortByDesc('sales')->values()->all(),
        ];
    }

    /** Module B detail table: one row per employee; target = salary × multiplier (default 7). */
    private function employeeTable(DashboardWidget $widget, array $ds): array
    {
        if ($ds['table'] !== 'sales') {
            return ['error' => 'The employee table needs the Sales dataset'];
        }
        $visit = 'COUNT(DISTINCT COALESCE(sales.invoice_no, CAST(sales.id AS CHAR)))';
        $rows = $this->baseQuery($widget, $ds)->whereNotNull('sales.employee_id')
            ->selectRaw("sales.employee_id as e, SUM(sales.net_amount) as s, $visit as f, SUM(CASE WHEN sales.category = 'Service' THEN 1 ELSE 0 END) as sv")
            ->groupBy('sales.employee_id')->get();
        $emps = Employee::with('branch')->whereIn('id', $rows->pluck('e'))->get()->keyBy('id');

        return [
            'type' => 'employee_table',
            'columns' => [['employee', 'Employee', 's'], ['branch', 'Branch', 's'], ['section', 'Section', 's'], ['salary', 'Salary', 'm'], ['multiplier', 'Multiplier', 'x'],
                ['target', 'Target', 'm'], ['sales', 'Sales', 'm'], ['achievement', 'Achievement %', 'p'], ['gap', 'Gap to target', 'm'], ['services', 'Services', 'n'], ['atv', 'ATV', 'm']],
            'sort' => 'achievement',
            'rows' => $rows->map(function ($r) use ($emps) {
                $e = $emps[$r->e] ?? null;
                $target = $e?->monthlyTarget();

                return [
                    'key' => $r->e,
                    'employee' => $e?->full_name ?? '(unknown)',
                    'branch' => $e?->branch?->name,
                    'section' => $e?->section,
                    'salary' => $e?->salary !== null ? (float) $e->salary : null,
                    'multiplier' => $e?->salary ? (float) ($e->target_multiplier ?: Employee::DEFAULT_MULTIPLIER) : null,
                    'target' => $target,
                    'sales' => round((float) $r->s, 2),
                    'achievement' => $target ? round($r->s / $target * 100, 1) : null,
                    'gap' => $target ? round($r->s - $target, 2) : null,
                    'services' => (int) $r->sv,
                    'atv' => $r->f ? round($r->s / $r->f, 2) : 0,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Tag ids the widget is limited to: the dashboard's "only employees tagged" setting plus the filter bar.
     * A dashboard tag nobody carries yet gives id 0, which matches no one. Widgets with option all_employees skip the dashboard tag.
     */
    private function tagIds(DashboardWidget $widget): array
    {
        $ids = $this->tagId ? [$this->tagId] : [];
        $name = $widget->option('all_employees') ? null : $widget->dashboard?->employee_tag;
        if ($name) {
            $ids[] = (int) (Tag::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id') ?? 0);
        }

        return array_values(array_unique($ids));
    }

    /**
     * CallGear agent scores: calls and talk minutes per day against the widget's daily targets
     * (options target_calls, default 100; target_minutes, default 120). Everyone in the tag is listed,
     * including agents with no calls, so low performers show up.
     */
    private function agentScores(DashboardWidget $widget, array $ds): array
    {
        if ($ds['table'] !== 'calls') {
            return ['error' => 'This widget needs the Calls (CallGear) dataset'];
        }
        $targetCalls = (float) $widget->option('target_calls', self::CALL_TARGET) ?: self::CALL_TARGET;
        $targetMinutes = (float) $widget->option('target_minutes', self::TALK_TARGET) ?: self::TALK_TARGET;

        [$from, $to] = $this->dateRange($widget->date_range);
        $now = CarbonImmutable::now();
        $start = $from ?? CarbonImmutable::parse(DB::table('calls')->min('started_at') ?? $now);
        $end = $to && $to->lt($now) ? $to : $now;
        $days = $end->lt($start) ? 1 : max(1, (int) $start->startOfDay()->diffInDays($end->startOfDay()) + 1);

        $stats = $this->baseQuery($widget, $ds)->whereNotNull('calls.employee_id')
            ->selectRaw('calls.employee_id as e, COUNT(*) as c, SUM(calls.duration_seconds) as t')
            ->groupBy('calls.employee_id')->get()->keyBy('e');

        $tags = $this->tagIds($widget);
        $allowed = $this->user->visibleBranchIds();
        $employees = Employee::with('branch')
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed ?: [0]))
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->when($this->user->visibleEmployeeId() !== null || $this->employeeId, fn ($q) => $q->whereKey($this->user->visibleEmployeeId() ?? $this->employeeId));
        if ($tags) {
            foreach ($tags as $tag) {
                $employees->whereHas('tags', fn ($w) => $w->whereKey($tag));
            }
            $employees->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $stats->keys()));
        } else {
            $employees->whereIn('id', $stats->keys());
        }

        $rows = $employees->get()->map(function (Employee $e) use ($stats, $days, $targetCalls, $targetMinutes) {
            $s = $stats[$e->id] ?? null;
            $calls = (int) ($s->c ?? 0);
            $minutes = round((float) ($s->t ?? 0) / 60, 1);
            $callsDay = round($calls / $days, 1);
            $talkDay = round($minutes / $days, 1);
            $callsPct = round($callsDay / $targetCalls * 100, 1);
            $talkPct = round($talkDay / $targetMinutes * 100, 1);
            $score = round(($callsPct + $talkPct) / 2, 1);

            return [
                'key' => $e->id,
                'employee' => $e->full_name,
                'branch' => $e->branch?->name,
                'calls' => $calls,
                'calls_day' => $callsDay,
                'calls_pct' => $callsPct,
                'talk_min' => $minutes,
                'talk_day' => $talkDay,
                'talk_pct' => $talkPct,
                'achievement' => $score,
                'status' => $score >= 100 ? 'High' : ($score >= 80 ? 'On track' : 'Low'),
            ];
        })->sortByDesc('achievement')->values();

        $meta = ['days' => $days, 'target_calls' => $targetCalls, 'target_minutes' => $targetMinutes,
            'counts' => ['High' => $rows->where('status', 'High')->count(), 'On track' => $rows->where('status', 'On track')->count(), 'Low' => $rows->where('status', 'Low')->count()]];

        if ($widget->type === 'agent_table') {
            return $meta + [
                'type' => 'agent_table',
                'columns' => [['employee', 'Agent', 's'], ['branch', 'Branch', 's'], ['calls', 'Calls', 'n'], ['calls_day', 'Calls / day', 'n'], ['calls_pct', '% of '.(float) $targetCalls.' calls', 'p'],
                    ['talk_min', 'Talk min', 'n'], ['talk_day', 'Talk min / day', 'n'], ['talk_pct', '% of '.(float) $targetMinutes.' min', 'p'], ['achievement', 'Score %', 'p'], ['status', 'Status', 's']],
                'sort' => 'achievement',
                'rows' => $rows->all(),
            ];
        }

        $talk = $widget->option('metric') === 'talk';
        $sorted = $rows->sortByDesc($talk ? 'talk_day' : 'calls_day')->values();

        return $meta + [
            'type' => 'agent_bars',
            'field' => 'employee',
            'unit' => $talk ? 'min / day' : 'calls / day',
            'target' => $talk ? $targetMinutes : $targetCalls,
            'labels' => $sorted->pluck('employee')->all(),
            'keys' => $sorted->pluck('key')->all(),
            'values' => $sorted->pluck($talk ? 'talk_day' : 'calls_day')->all(),
            'percents' => $sorted->pluck($talk ? 'talk_pct' : 'calls_pct')->all(),
        ];
    }

    /** Tagged agents the user may see (active, or with data), for the agent widgets. */
    private function agentEmployees(DashboardWidget $widget, array $withData = [], bool $wholeGroup = false)
    {
        $allowed = $this->user->visibleBranchIds();
        $q = Employee::with('tags')
            ->when($allowed !== null, fn ($q) => $q->whereIn('branch_id', $allowed ?: [0]))
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))
            ->when(! $wholeGroup && ($this->user->visibleEmployeeId() !== null || $this->employeeId), fn ($q) => $q->whereKey($this->user->visibleEmployeeId() ?? $this->employeeId));
        $tags = $this->tagIds($widget);
        foreach ($tags as $tag) {
            $q->whereHas('tags', fn ($w) => $w->whereKey($tag));
        }

        return $q->where(fn ($w) => $w->where('is_active', true)->orWhereIn('id', $withData ?: [0]))->get();
    }

    /**
     * Agent revenue the way the client's Zenoti Sales-Accrual report counts it: service lines of closed
     * invoices paid by cash, card or custom-financial, by sale date. A line counts for the agent who
     * created the invoice (not who sold it), as the client's team counts it. The team target (option team_target, 800,000) is split
     * equally between the agents; anyone tagged "Team lead" (option lead_tag) is shown but not in the split.
     * agent_commission adds the tier the team total reached (option tiers: [[from, percent], ...]).
     */
    private function agentRevenue(DashboardWidget $widget): array
    {
        [$from, $to] = $this->dateRange($widget->date_range);
        // Targets and tiers are for the whole group, even when the viewer only sees her own row.
        $agents = $this->agentEmployees($widget, [], true);
        $shown = $this->agentEmployees($widget)->pluck('id')->all();
        $ids = $agents->pluck('id')->all();
        $revenue = array_fill_keys($ids, 0.0);
        $daily = [];
        \App\Models\Sale::query()
            ->whereIn('created_by_employee_id', $ids ?: [0])
            ->when($from, fn ($q) => $q->where('sold_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('sold_at', '<=', $to))
            ->when($this->user->visibleBranchIds() !== null, fn ($q) => $q->whereIn('branch_id', $this->user->visibleBranchIds() ?: [0]))
            ->select(['id', 'employee_id', 'created_by_employee_id', 'item_type', 'status', 'net_amount', 'raw', 'sold_at'])
            ->chunkById(1000, function ($lines) use (&$revenue, &$daily) {
                foreach ($lines as $line) {
                    if (! self::countsAsAgentRevenue($line)) {
                        continue;
                    }
                    $who = $line->created_by_employee_id;
                    if ($who !== null && isset($revenue[$who])) {
                        $amount = self::agentRevenueAmount($line)[0];
                        $revenue[$who] += $amount;
                        $day = \Carbon\Carbon::parse($line->sold_at)->toDateString();
                        $daily[$day] = ($daily[$day] ?? 0) + $amount;
                    }
                }
            });

        $leadTag = mb_strtolower((string) $widget->option('lead_tag', 'Team lead'));
        $isLead = fn (Employee $e) => $e->tags->contains(fn ($t) => mb_strtolower($t->name) === $leadTag);
        $ladies = $agents->reject($isLead);
        $teamTarget = (float) $widget->option('team_target', 800000);
        $each = $ladies->count() ? round($teamTarget / $ladies->count(), 2) : 0;
        $total = round(array_sum($revenue), 2);
        $counted = round($ladies->sum(fn ($e) => $revenue[$e->id]), 2);

        $rows = $agents->whereIn('id', $shown)->map(fn (Employee $e) => [
            'key' => $e->id,
            'employee' => $e->full_name.($isLead($e) ? ' (team lead)' : ''),
            'lead' => $isLead($e),
            'revenue' => round($revenue[$e->id], 2),
            'target' => $isLead($e) ? null : $each,
            'pct' => ! $isLead($e) && $each > 0 ? round($revenue[$e->id] / $each * 100, 1) : null,
        ])->sortByDesc('revenue')->values();

        $meta = ['team_target' => $teamTarget, 'target_each' => $each, 'agents' => $ladies->count(), 'total' => $total,
            'team_pct' => $teamTarget > 0 ? round($counted / $teamTarget * 100, 1) : null, 'counted' => $counted];

        // Commission tiers are reached by the whole group: every Callgear agent, team lead included.
        $tiers = collect($widget->option('tiers', self::COMMISSION_TIERS))
            ->map(fn ($t) => [(float) $t[0], (float) $t[1]])->sortBy(0)->values();
        $tier = $tiers->filter(fn ($t) => $total >= $t[0])->last();
        $next = $tiers->first(fn ($t) => $total < $t[0]);
        $rate = $tier[1] ?? 0.0;

        if ($widget->type === 'agent_group_target') {
            return $this->groupTarget($widget, $total, $tiers->all(), $tier, $next, $daily, $agents->count());
        }

        if ($widget->type === 'agent_tier_revenue') {
            // Each group tier split equally between all agents (team lead included): each one's own share and rate.
            $n = max(1, $agents->count());
            $shares = $tiers->map(fn ($t) => [round($t[0] / $n, 2), $t[1], $t[0]])->all();
            $own = fn ($v) => collect($shares)->filter(fn ($t) => $v >= $t[0])->last();
            $tierRows = $agents->whereIn('id', $shown)->map(fn (Employee $e) => ['key' => $e->id, 'employee' => $e->full_name, 'revenue' => round($revenue[$e->id], 2)])
                ->sortByDesc('revenue')->values()->map(function ($r) use ($own, $shares) {
                    $t = $own($r['revenue']);
                    $next = collect($shares)->first(fn ($s) => $r['revenue'] < $s[0]);

                    return $r + ['rate' => $t[1] ?? 0.0, 'commission' => round($r['revenue'] * ($t[1] ?? 0) / 100, 2),
                        'next' => $next[0] ?? null, 'next_rate' => $next[1] ?? null, 'to_next' => $next ? round($next[0] - $r['revenue'], 2) : null];
                });

            return [
                'type' => 'tier_bars',
                'field' => 'employee',
                'agents' => $n,
                'shares' => $shares,
                'labels' => $tierRows->pluck('employee')->all(),
                'keys' => $tierRows->pluck('key')->all(),
                'values' => $tierRows->pluck('revenue')->all(),
                'rows' => $tierRows->all(),
            ];
        }

        if ($widget->type === 'agent_commission') {

            return $meta + [
                'type' => 'agent_table',
                'note' => 'Group revenue '.number_format($total).' AED'.($tier ? ': tier '.number_format($tier[0]).' AED reached, '.$rate.'% commission' : ': no tier reached yet')
                    .($next ? '. Next tier '.number_format($next[0]).' AED ('.$next[1].'%), '.number_format($next[0] - $total).' AED to go.' : '.'),
                'tiers' => $tiers->all(),
                'rate' => $rate,
                'columns' => [['employee', 'Agent', 's'], ['revenue', 'Revenue', 'm'], ['target', 'Her target', 'm'], ['pct', '% of target', 'p'], ['commission', 'Commission ('.$rate.'%)', 'm']],
                'sort' => 'revenue',
                'rows' => $rows->map(fn ($r) => $r + ['commission' => $r['lead'] ? null : round($r['revenue'] * $rate / 100, 2)])->all(),
            ];
        }

        return $meta + [
            'type' => 'agent_bars',
            'money' => true,
            'field' => 'employee',
            'unit' => 'AED',
            'days' => null,
            'summary' => 'Team '.number_format($counted).' of '.number_format($teamTarget).' AED ('.($meta['team_pct'] ?? 0).'%) · target '.number_format($each).' AED each for '.$ladies->count().' agents',
            'target' => $each,
            'counts' => ['High' => $rows->where('pct', '>=', 100)->count(), 'On track' => $rows->filter(fn ($r) => $r['pct'] !== null && $r['pct'] >= 80 && $r['pct'] < 100)->count(), 'Low' => $rows->filter(fn ($r) => $r['pct'] !== null && $r['pct'] < 80)->count()],
            'labels' => $rows->pluck('employee')->all(),
            'keys' => $rows->pluck('key')->all(),
            'values' => $rows->pluck('revenue')->all(),
            'percents' => $rows->map(fn ($r) => $r['pct'] ?? 100)->all(),
            'lead' => $rows->pluck('lead')->all(),
        ];
    }

    public const AGENT_REVENUE_TYPES = ['agent_revenue', 'agent_commission', 'agent_group_target', 'agent_tier_revenue'];

    public const COMMISSION_TIERS = [[700000, 0.4], [800000, 0.5], [900000, 0.6], [1000000, 0.7]];

    /**
     * Group commission target: all Callgear agents' revenue for the period against the tiers, what is
     * left to the next tier, and the running total per day (with the pace to month end for this month).
     */
    private function groupTarget(DashboardWidget $widget, float $total, array $tiers, ?array $tier, ?array $next, array $daily, int $agents): array
    {
        [$from, $to] = $this->dateRange($widget->date_range);
        $start = $from ? CarbonImmutable::parse($from)->startOfDay() : (count($daily) ? CarbonImmutable::parse(min(array_keys($daily))) : CarbonImmutable::now()->startOfMonth());
        $end = $to ? CarbonImmutable::parse($to)->startOfDay() : CarbonImmutable::now()->startOfDay();
        $today = CarbonImmutable::now()->startOfDay();
        $labels = $running = [];
        $sum = 0.0;
        for ($d = $start, $n = 0; $d <= $end && $n < 400; $d = $d->addDay(), $n++) {
            $sum += $daily[$d->toDateString()] ?? 0;
            $labels[] = $d->toDateString();
            $running[] = $d > $today ? null : round($sum, 2);
        }
        $projected = null;
        if ($end > $today && $start <= $today) {
            $elapsed = $start->diffInDays($today) + 1;
            $projected = round($total / $elapsed * ($start->diffInDays($end) + 1), 2);
        }
        $top = $tiers ? end($tiers)[0] : 0;

        return [
            'type' => 'group_target',
            'total' => $total,
            'agents' => $agents,
            'tiers' => $tiers,
            'rate' => $tier[1] ?? 0.0,
            'tier' => $tier[0] ?? null,
            'next' => $next[0] ?? null,
            'next_rate' => $next[1] ?? null,
            'to_next' => $next ? round($next[0] - $total, 2) : null,
            'commission' => round($total * ($tier[1] ?? 0) / 100, 2),
            'pct_of_top' => $top > 0 ? round($total / $top * 100, 1) : null,
            'projected' => $projected,
            'projected_rate' => $projected !== null ? (collect($tiers)->filter(fn ($t) => $projected >= $t[0])->last()[1] ?? 0.0) : null,
            'labels' => $labels,
            'values' => $running,
        ];
    }

    /** Sales-Accrual rules: services only, closed invoices, paid by cash, card or custom-financial. */
    public static function countsAsAgentRevenue(object $line): bool
    {
        return self::agentRevenueExclusion($line) === null;
    }

    /** Why a sales line is left out of agent revenue, or null when it counts. */
    public static function agentRevenueExclusion(object $line): ?string
    {
        $type = mb_strtolower((string) $line->item_type);
        if ($type !== '' && ! is_numeric($type) && ! str_contains($type, 'service')) {
            return 'Not a service ('.$line->item_type.')';
        }
        $status = mb_strtolower((string) $line->status);
        if ($status !== '' && preg_match('/open|void|cancel|refund|delete/', $status)) {
            return 'Invoice '.$line->status;
        }
        $pay = self::paymentOf($line);
        if ($pay !== null && trim($pay) !== '') {
            // Like the report's Payment Type filter: the line counts when any of its payment types is
            // Cash, Card or Custom-Financial (gift / prepaid cards, packages, memberships, loyalty don't).
            $ok = collect(preg_split('/\s*[,;\/|]\s*/', mb_strtolower($pay)))->contains(fn ($p) => preg_match('/cash|card|custom[- ]?financial|visa|master|amex/', $p)
                && ! preg_match('/gift|prepaid|package|membership|loyalty|cash ?back|no payment|cheque|check|non[- ]?financial/', $p));
            if (! $ok) {
                return 'Paid by '.$pay;
            }
        }

        return null;
    }

    /**
     * Agent revenue for a line = what was paid by Cash, Card and Custom-Financial (the client's rule).
     * Uses Zenoti's per-payment-type amounts when the line has them (fields such as cash / card /
     * custom_financial, or a payments list); otherwise the line's net amount. Returns [amount, where from].
     */
    public static function agentRevenueAmount(object $line): array
    {
        $raw = is_array($line->raw) ? $line->raw : (json_decode((string) $line->raw, true) ?: []);
        $isPaid = fn (string $name) => preg_match('/cash|card|custom|visa|master|amex/i', $name)
            && ! preg_match('/gift|prepaid|cash[ _-]?back|loyalty|membership|package|count|date|_id$|^id$|type|tax/i', $name);
        $sum = 0.0;
        $used = [];
        foreach (['payments', 'payment_details', 'payment_types', 'collections'] as $listKey) {
            foreach (is_array($raw[$listKey] ?? null) ? $raw[$listKey] : [] as $p) {
                $name = is_array($p) ? (string) (data_get($p, 'type') ?? data_get($p, 'payment_type') ?? data_get($p, 'name') ?? data_get($p, 'payment_type.name') ?? '') : '';
                $amt = is_array($p) ? (data_get($p, 'amount') ?? data_get($p, 'value')) : null;
                if ($name !== '' && is_numeric($amt) && $isPaid($name)) {
                    $sum += (float) $amt;
                    $used[$name] = true;
                }
            }
        }
        if (! $used) {
            foreach ($raw as $key => $value) {
                if (is_string($key) && is_numeric($value) && $isPaid($key)) {
                    $sum += (float) $value;
                    $used[$key] = true;
                }
            }
        }

        if ($used) {
            return [round($sum, 2), implode(' + ', array_keys($used))];
        }
        // Otherwise the column of the Sales-Accrual export the client totals (set on the Revenue widget).
        $column = self::revenueColumn();
        if ($column !== 'sales_exc_tax') {
            foreach (self::REVENUE_COLUMNS[$column][1] as $k) {
                if (array_key_exists($k, $raw) && is_numeric($raw[$k])) {
                    return [(float) $raw[$k], self::REVENUE_COLUMNS[$column][0]];
                }
            }

            return [(float) $line->net_amount, 'Sales (Exc. Tax): '.self::REVENUE_COLUMNS[$column][0].' not sent'];
        }

        return [(float) $line->net_amount, 'Sales (Exc. Tax)'];
    }

    /** Sales-Accrual export columns agent revenue can total, with the field names Zenoti's API may use. */
    public const REVENUE_COLUMNS = [
        'sales_exc_tax' => ['Sales (Exc. Tax)', []],
        'sales_inc_tax' => ['Sales (Inc. Tax)', ['sales_inc_tax', 'sales_including_tax', 'sales_incl_tax', 'sales_inclusive_tax']],
        'collected' => ['Collected', ['collected', 'collected_amount', 'collection']],
        'sales_exc_redemption' => ['Sales (Exc. Redemption)', ['sales_exc_redemption', 'sales_excluding_redemption', 'sales_exc_redemptions']],
    ];

    public static function revenueColumn(): string
    {
        $c = (string) \App\Models\Setting::get('callgear.revenue_column', 'sales_exc_tax');

        return array_key_exists($c, self::REVENUE_COLUMNS) ? $c : 'sales_exc_tax';
    }

    /** The payment type Zenoti sent on the line, if any. */
    public static function paymentOf(object $line): ?string
    {
        $raw = is_array($line->raw) ? $line->raw : (json_decode((string) $line->raw, true) ?: []);
        foreach ($raw as $key => $value) {
            if (is_string($value) && preg_match('/payment/i', (string) $key) && ! preg_match('/date|id$/i', (string) $key)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The sales lines behind the agent revenue widgets, one per line, with who sold it, who created the
     * invoice and whether it counts (and why not), so totals can be checked line by line against Zenoti.
     * A clicked agent shows the invoices she created plus the ones she only sold (not counted).
     */
    private function agentRevenueRecords(DashboardWidget $widget, ?string $key): array
    {
        [$from, $to] = $this->dateRange($widget->date_range);
        $agents = $this->agentEmployees($widget, [], true);
        $ids = $key !== null && $key !== '' ? array_values(array_intersect([(int) $key], $agents->pluck('id')->all())) : $this->agentEmployees($widget)->pluck('id')->all();
        $names = Employee::whereIn('id', \App\Models\Sale::query()->whereIn('created_by_employee_id', $ids ?: [0])->orWhereIn('employee_id', $ids ?: [0])->select('created_by_employee_id'))
            ->get()->keyBy('id');
        $lines = \App\Models\Sale::query()
            ->with(['employee:id,first_name,last_name', 'branch:id,name'])
            ->where(fn ($q) => $q->whereIn('created_by_employee_id', $ids ?: [0])->orWhereIn('employee_id', $ids ?: [0]))
            ->when($from, fn ($q) => $q->where('sold_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('sold_at', '<=', $to))
            ->when($this->user->visibleBranchIds() !== null, fn ($q) => $q->whereIn('branch_id', $this->user->visibleBranchIds() ?: [0]))
            ->orderByDesc('sold_at')->limit(self::RECORD_LIMIT * 4)->get();
        $total = 0.0;
        $rows = $lines->map(function ($l) use ($ids, $names, &$total) {
            $raw = is_array($l->raw) ? $l->raw : [];
            $creatorName = data_get($raw, 'created_by.name') ?? (is_string($raw['created_by'] ?? null) ? $raw['created_by'] : null)
                ?? trim((string) data_get($raw, 'created_by.first_name').' '.(string) data_get($raw, 'created_by.last_name'));
            $creator = $l->created_by_employee_id ? $names->get($l->created_by_employee_id)?->full_name : null;
            $why = ! in_array($l->created_by_employee_id, $ids, true)
                ? ($l->created_by_employee_id ? 'Invoice created by someone else' : 'Invoice creator not matched to an employee')
                : self::agentRevenueExclusion($l);
            [$amount, $from] = self::agentRevenueAmount($l);
            if ($why === null) {
                $total += $amount;
            }

            return [optional($l->sold_at)->toDateTimeString(), $l->invoice_no, $l->item_name, $l->item_type, $l->status ?: null, self::paymentOf($l),
                $amount, $from, (float) $l->net_amount, $l->employee?->full_name, $creator ?? ($creatorName ?: null), $l->branch?->name, $why === null ? 'Yes' : 'No: '.$why];
        });

        return [
            'total' => $rows->count(),
            'shown' => $rows->count(),
            'note' => 'Counted: '.number_format($total, 2),
            'columns' => [['Date', 'd'], ['Invoice', 's'], ['Item', 's'], ['Item type', 's'], ['Status', 's'], ['Payment', 's'], ['Amount', 'm'], ['Amount from', 's'], ['Net amount', 'm'],
                ['Sold by', 's'], ['Invoice created by', 's'], ['Branch', 's'], ['Counted', 's']],
            'rows' => $rows->values()->all(),
            'guest_links' => [],
        ];
    }

    /**
     * Calls per agent split by the result tag agents put on them in CallGear ("Outgoing new sale",
     * "Incoming booking call"...). Option tags limits and orders the tags; otherwise the 8 most used.
     */
    private function agentTags(DashboardWidget $widget): array
    {
        [$from, $to] = $this->dateRange($widget->date_range);
        $agents = $this->agentEmployees($widget);
        $calls = DB::table('calls')->whereIn('employee_id', $agents->pluck('id')->all() ?: [0])->whereNotNull('tags')->where('tags', '!=', '')
            ->when($from, fn ($q) => $q->where('started_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('started_at', '<=', $to))
            ->get(['employee_id', 'tags']);
        $counts = [];
        foreach ($calls as $c) {
            foreach (array_filter(array_map('trim', explode(',', $c->tags))) as $tag) {
                $counts[$tag][$c->employee_id] = ($counts[$tag][$c->employee_id] ?? 0) + 1;
            }
        }
        $wanted = array_filter((array) $widget->option('tags', []));
        $tags = $wanted ?: collect($counts)->sortByDesc(fn ($byAgent) => array_sum($byAgent))->keys()->take(8)->all();
        $agents = $agents->sortByDesc(fn ($e) => collect($tags)->sum(fn ($t) => $counts[$t][$e->id] ?? 0))->values();

        return [
            'type' => 'agent_tags',
            'labels' => $agents->map(fn ($e) => $e->full_name)->all(),
            'keys' => $agents->pluck('id')->all(),
            'series' => collect($tags)->map(fn ($t) => ['name' => $t, 'data' => $agents->map(fn ($e) => $counts[$t][$e->id] ?? 0)->all()])->values()->all(),
        ];
    }

    public const CALL_TARGET = 100;

    public const TALK_TARGET = 120;

    /** The period to compare with: same stretch of the previous month / week / year, else the same length just before. */
    public function previousRange(CarbonImmutable $from, CarbonImmutable $to, ?string $range): array
    {
        $now = CarbonImmutable::now();
        if (! $this->from && ! $this->to && $to->greaterThan($now)) {
            $to = $now->endOfDay();
        }
        if (! $this->from && ! $this->to) {
            switch ($range) {
                case 'this_month':
                case 'last_month':
                    return [$from->subMonthNoOverflow(), $range === 'last_month' ? $from->subMonthNoOverflow()->endOfMonth() : $to->subMonthNoOverflow()];
                case 'this_week':
                    return [$from->subWeek(), $to->subWeek()];
                case 'this_year':
                    return [$from->subYear(), $to->subYear()];
            }
        }
        $seconds = $to->getTimestamp() - $from->getTimestamp();
        $pTo = $from->subSecond();

        return [$pTo->subSeconds($seconds), $pTo];
    }

    private function compareLabel(?string $range): string
    {
        if ($this->from || $this->to) {
            return 'vs previous period';
        }

        return match ($range) {
            'today' => 'vs yesterday',
            'yesterday' => 'vs day before',
            'this_week' => 'vs last week',
            'this_month', 'last_month' => 'vs last month',
            'this_year' => 'vs last year',
            default => 'vs previous period',
        };
    }

    private function baseQuery(DashboardWidget $widget, array $ds, ?array $range = null): Builder
    {
        $t = $ds['table'];
        $query = DB::table($t);
        if (in_array($t, ['appointments', 'guests'], true)) {
            $query->whereNull("$t.deleted_in_zenoti_at");
        }

        // Data scoping from the user's role, then from the filter bar.
        $allowed = $this->user->visibleBranchIds();
        if ($allowed !== null) {
            $query->whereIn("$t.branch_id", $allowed ?: [0]);
        }
        if ($this->branchId) {
            $query->where("$t.branch_id", $this->branchId);
        }

        $ownEmployee = $this->user->visibleEmployeeId();
        $employeeFilter = $ownEmployee ?? $this->employeeId;
        if ($employeeFilter !== null && $ds['employee']) {
            $query->where("$t.{$ds['employee']}", $employeeFilter);
        }
        // Tag filters (the dashboard's own tag and the filter bar): only rows of employees carrying the tag.
        // Datasets without an employee column are left as they are.
        if ($ds['employee']) {
            foreach ($this->tagIds($widget) as $tag) {
                $query->whereIn("$t.{$ds['employee']}", DB::table('employee_tag')->select('employee_id')->where('tag_id', $tag));
            }
        }

        if ($ds['date']) {
            [$from, $to] = $range ?? $this->dateRange($widget->date_range);
            if ($from) {
                $query->where("$t.{$ds['date']}", '>=', $from);
            }
            if ($to) {
                $query->where("$t.{$ds['date']}", '<=', $to);
            }
        }

        foreach ((array) $widget->filters as $filter) {
            $field = $filter['field'] ?? null;
            if (! $field || ! array_key_exists($field, $ds['filters'])) {
                continue;
            }
            $value = $filter['value'] ?? null;
            match ($filter['operator'] ?? '=') {
                '!=' => $query->where("$t.$field", '!=', $value),
                'in' => $query->whereIn("$t.$field", array_map('trim', explode(',', (string) $value))),
                'not_in' => $query->whereNotIn("$t.$field", array_map('trim', explode(',', (string) $value))),
                default => $query->where("$t.$field", $value),
            };
        }

        return $query;
    }

    private function valueExpression(DashboardWidget $widget, array $ds): string
    {
        $agg = $widget->aggregate ?: 'count';
        $t = $ds['table'];
        if (in_array($agg, self::SALES_ONLY, true)) {
            if ($t !== 'sales') {
                return 'COUNT(*)';
            }
            $visit = "COUNT(DISTINCT COALESCE($t.invoice_no, CAST($t.id AS CHAR)))";
            $earlier = "SELECT 1 FROM sales s2 WHERE s2.guest_id = $t.guest_id AND s2.sold_at < $t.sold_at";

            return match ($agg) {
                'invoices' => $visit,
                'atv' => "SUM($t.net_amount) * 1.0 / NULLIF($visit, 0)",
                'per_service' => "SUM($t.net_amount) * 1.0 / NULLIF(COUNT(*), 0)",
                'discount_pct' => "SUM($t.discount) * 100.0 / NULLIF(SUM($t.net_amount) + SUM($t.discount), 0)",
                'new_guests' => "COUNT(DISTINCT CASE WHEN NOT EXISTS ($earlier) THEN $t.guest_id END)",
                'returning_guests' => "COUNT(DISTINCT CASE WHEN EXISTS ($earlier) THEN $t.guest_id END)",
            };
        }
        if ($agg === 'talk_minutes') {
            return $t === 'calls' ? "SUM($t.duration_seconds) / 60.0" : 'COUNT(*)';
        }
        if ($agg === 'count' || ! $widget->metric_field || ! array_key_exists($widget->metric_field, $ds['numeric'])) {
            return 'COUNT(*)';
        }
        $fn = strtoupper(in_array($agg, ['sum', 'avg', 'min', 'max'], true) ? $agg : 'sum');

        return "$fn({$ds['table']}.{$widget->metric_field})";
    }

    private function groupExpression(string $group, array $ds): array
    {
        $t = $ds['table'];
        $sqlite = DB::connection()->getDriverName() === 'sqlite';

        return match ($group) {
            'branch' => ["$t.branch_id", true],
            'employee' => ["$t.{$ds['employee']}", true],
            'day' => [$sqlite ? "strftime('%Y-%m-%d', $t.{$ds['date']})" : "DATE_FORMAT($t.{$ds['date']}, '%Y-%m-%d')", false],
            'week' => [$sqlite ? "strftime('%Y-W%W', $t.{$ds['date']})" : "DATE_FORMAT($t.{$ds['date']}, '%x-W%v')", false],
            'weekday' => [$sqlite ? "CAST(strftime('%w', $t.{$ds['date']}) AS INTEGER)" : "(DAYOFWEEK($t.{$ds['date']}) - 1)", false],
            'month' => [$sqlite ? "strftime('%Y-%m', $t.{$ds['date']})" : "DATE_FORMAT($t.{$ds['date']}, '%Y-%m')", false],
            default => array_key_exists($group, $ds['groups']) ? ["$t.$group", false] : ["$t.id", false],
        };
    }

    private function labelsFor(string $group, array $keys): array
    {
        $keys = array_filter($keys, fn ($k) => $k !== null);

        return match ($group) {
            'branch' => Branch::whereIn('id', $keys)->pluck('name', 'id')->all(),
            'employee' => Employee::whereIn('id', $keys)->get()->mapWithKeys(fn ($e) => [$e->id => $e->full_name])->all(),
            'weekday' => collect($keys)->mapWithKeys(fn ($k) => [$k => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][(int) $k] ?? $k])->all(),
            'stage' => collect($keys)->mapWithKeys(fn ($k) => [$k => ucfirst($k)])->all(),
            default => [],
        };
    }

    /** Filter-bar dates win over the widget's default range. */
    public function dateRange(string $range): array
    {
        if ($this->from || $this->to) {
            return [
                $this->from ? CarbonImmutable::parse($this->from)->startOfDay() : null,
                $this->to ? CarbonImmutable::parse($this->to)->endOfDay() : null,
            ];
        }

        $now = CarbonImmutable::now();

        return match ($range) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'last_month' => [$now->subMonthNoOverflow()->startOfMonth(), $now->subMonthNoOverflow()->endOfMonth()],
            'last_30_days' => [$now->subDays(30)->startOfDay(), $now->endOfDay()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            'all_time' => [null, null],
            default => [$now->startOfMonth(), $now->endOfMonth()],
        };
    }
}

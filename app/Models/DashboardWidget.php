<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardWidget extends Model
{
    public const TYPES = [
        'kpi' => 'KPI number',
        'bar' => 'Bar chart',
        'line' => 'Line chart',
        'pie' => 'Pie chart',
        'doughnut' => 'Doughnut chart',
        'funnel' => 'Funnel',
        'table' => 'Table',
        // Rizz-style elements (ApexCharts)
        'stat' => 'Stat card with icon & trend',
        'sparkline' => 'KPI with sparkline & trend',
        'radial' => 'Radial gauge vs target',
        'area' => 'Area chart (gradient)',
        'column' => 'Column chart (rounded)',
        'hbar' => 'Horizontal bar chart',
        'donut' => 'Donut chart (Apex)',
        'progress' => 'Progress-bar list',
        'branch_table' => 'Branch detail table (sales dataset)',
        'employee_table' => 'Employee target table (sales dataset)',
        'agent_table' => 'Call agent scorecard (calls dataset)',
        'agent_bars' => 'Call agents vs daily target chart (calls dataset)',
        'agent_revenue' => 'Call agents revenue vs team target (sales dataset)',
        'agent_tags' => 'Call results by CallGear tag per agent (calls dataset)',
        'agent_commission' => 'Call agents commission tiers (sales dataset)',
    ];

    /** Types in the second group of the type picker. */
    public const RIZZ_TYPES = ['stat', 'sparkline', 'radial', 'area', 'column', 'hbar', 'donut', 'progress', 'branch_table', 'employee_table', 'agent_table', 'agent_bars', 'agent_revenue', 'agent_tags', 'agent_commission'];

    /** Types that show one number and ignore "Group by". */
    public const SINGLE_VALUE = ['kpi', 'stat', 'sparkline', 'radial', 'branch_table', 'employee_table', 'agent_table', 'agent_bars', 'agent_revenue', 'agent_tags', 'agent_commission'];

    /** Accent colours for stat cards and gauges (Bootstrap theme names). */
    public const COLORS = ['primary' => 'Green', 'info' => 'Cyan', 'warning' => 'Orange', 'danger' => 'Red', 'secondary' => 'Grey', 'dark' => 'Dark'];

    public const DATE_RANGES = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This week',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_30_days' => 'Last 30 days',
        'this_year' => 'This year',
        'all_time' => 'All time',
    ];

    protected $fillable = [
        'dashboard_id', 'title', 'type', 'dataset', 'aggregate', 'metric_field', 'group_by', 'filters', 'options', 'date_range', 'width', 'position',
    ];

    protected $casts = ['filters' => 'array', 'options' => 'array'];

    public function isSingleValue(): bool
    {
        return in_array($this->type, self::SINGLE_VALUE, true);
    }

    /** How the value is shown: money, pct or number. */
    public function format(): string
    {
        return match (true) {
            in_array($this->aggregate, ['atv', 'per_service'], true) => 'money',
            $this->aggregate === 'discount_pct' => 'pct',
            in_array($this->aggregate, ['sum', 'avg', 'min', 'max'], true) && in_array($this->metric_field, ['net_amount', 'gross_amount', 'discount', 'price', 'value', 'amount'], true) => 'money',
            default => 'number',
        };
    }

    public function option(string $key, $default = null)
    {
        return ($this->options ?? [])[$key] ?? $default;
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /**
     * CallGear-only roles may see CallGear data, and employee data (e.g. Zenoti revenue) on a dashboard
     * limited to one employee tag, since that only covers the tagged team. Guests etc. stay hidden.
     */
    public function allowedForCallgearOnly(): bool
    {
        $ds = \App\Support\Datasets::get($this->dataset) ?? [];
        if (($ds['source'] ?? '') === 'CallGear') {
            return true;
        }

        return ! empty($ds['employee']) && filled($this->dashboard?->employee_tag);
    }
}

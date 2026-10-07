<?php

namespace Database\Seeders;

use App\Models\Dashboard;
use Illuminate\Database\Seeder;

/**
 * "Callgear Performance": call-centre agents (employees tagged "Callgear") against
 * 100 calls and 120 talk minutes per day. Every widget reads CallGear data, so the
 * callgear-agent role can open it. Safe to re-run.
 */
class CallgearPerformanceDashboardSeeder extends Seeder
{
    public const NAME = 'Callgear Performance';

    public const TAG = 'Callgear';

    /** Value (price) of the appointments each Callgear agent booked, whoever serves them. */
    public const REVENUE = 'Revenue';

    /** Title the widget had in the first version, when it was a bar per agent. */
    private const OLD_REVENUE = 'Revenue per agent (Zenoti sales)';

    /** Appointments each agent booked in Zenoti (for any provider or branch). */
    public const BOOKINGS = 'Appointments booked';

    private const BOOKINGS_OPTIONS = ['color' => 'success', 'credit' => 'booked_by'];

    public function run(): void
    {
        if ($existing = Dashboard::where('name', self::NAME)->first()) {
            $this->addRevenue($existing);
            $this->addBookings($existing);
            $this->addAfter($existing, self::REVENUE, self::COMMISSION_SPEC);
            $this->addAfter($existing, self::REVENUE, self::GROUP_SPEC);
            $this->addAfter($existing, self::GROUP_SPEC['title'], self::TIER_REVENUE_SPEC);
            $this->addAfter($existing, 'Talk minutes per day vs target (120)', self::TAGS_SPEC);

            return;
        }

        $d = Dashboard::create([
            'name' => self::NAME, 'employee_tag' => self::TAG, 'sort_order' => 0,
            'description' => 'Agents tagged Callgear · targets 100 calls and 120 talk minutes per day',
        ]);
        $targets = ['target_calls' => 100, 'target_minutes' => 120];

        // [title, type, aggregate, field, group_by, width, options, date_range, dataset (default calls)]
        $widgets = [
            ['Calls today', 'stat', 'count', null, null, 3, ['icon' => 'telephone', 'color' => 'primary'], 'today'],
            ['Calls this month', 'stat', 'count', null, null, 3, ['icon' => 'telephone-outbound', 'color' => 'info'], 'this_month'],
            ['Talk time this month (min)', 'stat', 'talk_minutes', null, null, 3, ['icon' => 'clock-history', 'color' => 'warning'], 'this_month'],
            ['Average call length (sec)', 'stat', 'avg', 'duration_seconds', null, 3, ['icon' => 'stopwatch', 'color' => 'secondary'], 'this_month'],
            ['Calls per day vs target (100)', 'agent_bars', 'count', null, null, 6, $targets + ['metric' => 'calls'], 'this_month'],
            ['Talk minutes per day vs target (120)', 'agent_bars', 'count', null, null, 6, $targets + ['metric' => 'talk'], 'this_month'],
            ['Agent scorecard (who is high or low)', 'agent_table', 'count', null, null, 12, $targets, 'this_month'],
            ['Call results by tag', 'agent_tags', 'count', null, null, 12, [], 'this_month'],
            [self::REVENUE, 'agent_revenue', 'sum', 'net_amount', null, 12, self::REVENUE_SPEC['options'], 'this_month', 'sales'],
            [self::GROUP_SPEC['title'], 'agent_group_target', 'sum', 'net_amount', null, 12, self::GROUP_SPEC['options'], 'this_month', 'sales'],
            [self::TIER_REVENUE_SPEC['title'], 'agent_tier_revenue', 'sum', 'net_amount', null, 12, self::TIER_REVENUE_SPEC['options'], 'this_month', 'sales'],
            [self::COMMISSION_SPEC['title'], 'agent_commission', 'sum', 'net_amount', null, 12, self::COMMISSION_SPEC['options'], 'this_month', 'sales'],
            [self::BOOKINGS, 'hbar', 'count', null, 'employee', 12, self::BOOKINGS_OPTIONS, 'this_month', 'appointments'],
            ['Calls per day', 'area', 'count', null, 'day', 8, ['color' => 'primary'], 'this_month'],
            ['Calls by status', 'donut', 'count', null, 'status', 4, [], 'this_month'],
        ];

        foreach ($widgets as $i => $w) {
            [$title, $type, $agg, $field, $group, $width, $options, $range] = $w;
            $d->widgets()->create([
                'title' => $title, 'type' => $type, 'dataset' => $w[8] ?? 'calls', 'aggregate' => $agg, 'metric_field' => $field,
                'group_by' => $group, 'width' => $width, 'filters' => [], 'options' => $options ?: null, 'position' => $i, 'date_range' => $range,
            ]);
        }
    }

    /**
     * Revenue per agent this month as the client's Zenoti Sales-Accrual report counts it (services, closed,
     * cash / card / custom-financial), against the 800,000 AED team target split between the agents
     * (the one tagged "Team lead" is not in the split).
     */
    private const REVENUE_SPEC = ['type' => 'agent_revenue', 'group_by' => null, 'date_range' => 'this_month', 'dataset' => 'sales', 'aggregate' => 'sum',
        'metric_field' => 'net_amount', 'options' => ['team_target' => 800000, 'lead_tag' => 'Team lead']];

    /** Commission tier the team total reached (from 700k at 0.4% to 1M at 0.7%), per agent. */
    private const COMMISSION_SPEC = ['title' => 'Commission', 'type' => 'agent_commission', 'dataset' => 'sales', 'aggregate' => 'sum', 'metric_field' => 'net_amount',
        'date_range' => 'this_month', 'width' => 12,
        'options' => ['team_target' => 800000, 'lead_tag' => 'Team lead', 'tiers' => [[700000, 0.4], [800000, 0.5], [900000, 0.6], [1000000, 0.7]]]];

    /** All Callgear agents together against the commission tiers: achieved, left to the next tier, next rate. */
    private const GROUP_SPEC = ['title' => 'Group target and commission', 'type' => 'agent_group_target', 'dataset' => 'sales', 'aggregate' => 'sum', 'metric_field' => 'net_amount',
        'date_range' => 'this_month', 'width' => 12,
        'options' => ['tiers' => [[700000, 0.4], [800000, 0.5], [900000, 0.6], [1000000, 0.7]]]];

    /** Each agent's revenue against her equal share of every group tier (700k / agents at 0.4% ... 1M / agents at 0.7%). */
    private const TIER_REVENUE_SPEC = ['title' => 'Revenue vs group tiers (equal share)', 'type' => 'agent_tier_revenue', 'dataset' => 'sales', 'aggregate' => 'sum', 'metric_field' => 'net_amount',
        'date_range' => 'this_month', 'width' => 12,
        'options' => ['tiers' => [[700000, 0.4], [800000, 0.5], [900000, 0.6], [1000000, 0.7]]]];

    /** Calls per agent split by the result tag they put on them in CallGear. */
    private const TAGS_SPEC = ['title' => 'Call results by tag', 'type' => 'agent_tags', 'dataset' => 'calls', 'aggregate' => 'count', 'date_range' => 'this_month', 'width' => 12];

    private function addAfter(Dashboard $d, string $afterTitle, array $spec): void
    {
        if ($d->widgets()->where('title', $spec['title'])->exists()) {
            return;
        }
        $after = $d->widgets()->where('title', $afterTitle)->max('position') ?? $d->widgets()->max('position') ?? 0;
        $d->widgets()->where('position', '>', $after)->increment('position');
        $d->widgets()->create($spec + ['filters' => [], 'position' => $after + 1]);
    }

    /** Existing dashboards: earlier versions of the revenue widget (per-agent chart, single card) become the per-employee chart. */
    private function addRevenue(Dashboard $d): void
    {
        if ($card = $d->widgets()->where('title', self::REVENUE)->first()) {
            // The client counts revenue as in Zenoti's Sales-Accrual report, against the team target.
            if ($card->type !== 'agent_revenue') {
                $card->update(self::REVENUE_SPEC);
            }

            return;
        }
        if ($old = $d->widgets()->where('title', self::OLD_REVENUE)->first()) {
            $old->update(['title' => self::REVENUE] + self::REVENUE_SPEC);

            return;
        }
        $after = $d->widgets()->where('type', 'agent_table')->max('position') ?? $d->widgets()->max('position') ?? 0;
        $d->widgets()->where('position', '>', $after)->increment('position');
        $d->widgets()->create(self::REVENUE_SPEC + [
            'title' => self::REVENUE,
            'width' => 12, 'filters' => [], 'position' => $after + 1,
        ]);
    }

    private function addBookings(Dashboard $d): void
    {
        if ($d->widgets()->where('title', self::BOOKINGS)->exists()) {
            return;
        }
        $after = $d->widgets()->where('title', self::REVENUE)->max('position') ?? $d->widgets()->max('position') ?? 0;
        $d->widgets()->where('position', '>', $after)->increment('position');
        $d->widgets()->create([
            'title' => self::BOOKINGS, 'type' => 'hbar', 'dataset' => 'appointments', 'aggregate' => 'count', 'group_by' => 'employee',
            'date_range' => 'this_month', 'options' => self::BOOKINGS_OPTIONS, 'width' => 12, 'filters' => [], 'position' => $after + 1,
        ]);
    }
}

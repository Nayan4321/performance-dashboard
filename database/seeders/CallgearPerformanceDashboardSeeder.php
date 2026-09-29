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
            [self::REVENUE, 'hbar', 'sum', 'price', 'employee', 12, self::REVENUE_SPEC['options'], 'this_month', 'appointments'],
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

    /** Revenue per agent (one bar each), this month: the value of the appointments they booked. */
    private const REVENUE_SPEC = ['type' => 'hbar', 'group_by' => 'employee', 'date_range' => 'this_month', 'dataset' => 'appointments', 'aggregate' => 'sum',
        'metric_field' => 'price', 'options' => ['color' => 'primary', 'credit' => 'booked_by']];

    /** Existing dashboards: earlier versions of the revenue widget (per-agent chart, single card) become the per-employee chart. */
    private function addRevenue(Dashboard $d): void
    {
        if ($card = $d->widgets()->where('title', self::REVENUE)->first()) {
            // Earlier versions summed Zenoti sales; Nayan wants the value of each agent's bookings.
            if ($card->dataset === 'sales') {
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

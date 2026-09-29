<?php

namespace Database\Seeders;

use App\Models\Dashboard;
use Illuminate\Database\Seeder;

/**
 * "Performance overview": a sample dashboard built only from the rizz-style widgets,
 * so every new card and chart type can be seen with real data. Edit or delete freely.
 * Safe to re-run: it does nothing if a dashboard with this name already exists.
 */
class OverviewDashboardSeeder extends Seeder
{
    public const NAME = 'Performance overview';

    public function run(): void
    {
        if (Dashboard::where('name', self::NAME)->exists()) {
            return;
        }

        $d = Dashboard::create(['name' => self::NAME, 'description' => 'Stat cards, trends and charts in the new style', 'sort_order' => 1]);

        // [title, type, dataset, aggregate, field, group_by, width, options]
        $widgets = [
            ['Sales', 'stat', 'sales', 'sum', 'net_amount', null, 3, ['icon' => 'cash-stack', 'color' => 'primary']],
            ['Appointments', 'stat', 'appointments', 'count', null, null, 3, ['icon' => 'calendar-check', 'color' => 'info']],
            ['New guests', 'stat', 'guests', 'count', null, null, 3, ['icon' => 'person-plus', 'color' => 'warning']],
            ['Calls', 'stat', 'calls', 'count', null, null, 3, ['icon' => 'telephone-inbound', 'color' => 'danger']],
            ['Sales trend', 'sparkline', 'sales', 'sum', 'net_amount', null, 4, ['icon' => 'graph-up-arrow', 'color' => 'primary']],
            ['Bookings trend', 'sparkline', 'bookings', 'count', null, null, 4, ['icon' => 'calendar-plus', 'color' => 'info']],
            ['Sales vs monthly target', 'radial', 'sales', 'sum', 'net_amount', null, 4, ['color' => 'primary', 'target' => 100000]],
            ['Sales by day', 'area', 'sales', 'sum', 'net_amount', 'day', 8, ['color' => 'primary']],
            ['Sales by category', 'donut', 'sales', 'sum', 'net_amount', 'category', 4, []],
            ['Appointments by branch', 'column', 'appointments', 'count', null, 'branch', 6, ['color' => 'primary']],
            ['Top services', 'hbar', 'appointments', 'count', null, 'service_name', 6, ['color' => 'info']],
            ['Top employees by sales', 'progress', 'sales', 'sum', 'net_amount', 'employee', 6, ['color' => 'primary']],
            ['Appointments by status', 'progress', 'appointments', 'count', null, 'status', 6, ['color' => 'warning']],
        ];

        foreach ($widgets as $i => [$title, $type, $dataset, $agg, $field, $group, $width, $options]) {
            $d->widgets()->create([
                'title' => $title, 'type' => $type, 'dataset' => $dataset, 'aggregate' => $agg, 'metric_field' => $field,
                'group_by' => $group, 'width' => $width, 'filters' => [], 'options' => $options ?: null, 'position' => $i, 'date_range' => 'this_month',
            ]);
        }
    }
}

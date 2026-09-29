<?php

namespace Database\Seeders;

use App\Models\Dashboard;
use App\Models\Sale;
use Illuminate\Database\Seeder;

/**
 * "Admin dashboard" laid out like Zenoti's own Admin Dashboard, plus guests.
 *   php artisan db:seed --class=AdminDashboardSeeder --force
 * Safe to re-run: it does nothing if a dashboard with this name already exists.
 */
class AdminDashboardSeeder extends Seeder
{
    public const NAME = 'Admin dashboard';

    public function run(): void
    {
        if (Dashboard::where('name', self::NAME)->exists()) {
            return;
        }

        $d = Dashboard::create(['name' => self::NAME, 'description' => 'Same headline numbers as the Zenoti Admin Dashboard', 'sort_order' => 0]);
        $cat = fn (string ...$c) => [['field' => 'category', 'operator' => 'in', 'value' => implode(',', $c)]];

        // [title, type, dataset, aggregate, field, group_by, width, filters]
        $widgets = [
            ['Appointments', 'kpi', 'appointments', 'count', null, null, 3, []],
            ['Bookings', 'kpi', 'bookings', 'count', null, null, 3, []],
            ['New guests', 'kpi', 'guests', 'count', null, null, 3, []],
            ['No-shows', 'kpi', 'appointments', 'count', null, null, 3, [['field' => 'status', 'operator' => '=', 'value' => 'No-show']]],
            ['Collections', 'kpi', 'collections', 'sum', 'amount', null, 3, []],
            ['Sales', 'kpi', 'sales', 'sum', 'net_amount', null, 3, []],
            ['Liabilities sold', 'kpi', 'sales', 'sum', 'net_amount', null, 3, $cat(...Sale::LIABILITIES)],
            ['Service sales', 'kpi', 'sales', 'sum', 'net_amount', null, 3, $cat('Service')],
            ['Product sales', 'kpi', 'sales', 'sum', 'net_amount', null, 3, $cat('Product')],
            ['Package sales', 'kpi', 'sales', 'sum', 'net_amount', null, 3, $cat('Package')],
            ['Gift card sales', 'kpi', 'sales', 'sum', 'net_amount', null, 3, $cat('Gift card')],
            ['Membership sales', 'kpi', 'sales', 'sum', 'net_amount', null, 3, $cat('Membership')],
            ['Appointments by status', 'bar', 'appointments', 'count', null, 'status', 6, []],
            ['Sales by category', 'doughnut', 'sales', 'sum', 'net_amount', 'category', 6, []],
            ['Sales by branch', 'bar', 'sales', 'sum', 'net_amount', 'branch', 6, []],
            ['Appointments by branch', 'bar', 'appointments', 'count', null, 'branch', 6, []],
            ['Top employees by sales', 'table', 'sales', 'sum', 'net_amount', 'employee', 6, []],
            ['New guests by day', 'line', 'guests', 'count', null, 'day', 6, []],
        ];

        foreach ($widgets as $i => [$title, $type, $dataset, $agg, $field, $group, $width, $filters]) {
            $d->widgets()->create([
                'title' => $title, 'type' => $type, 'dataset' => $dataset, 'aggregate' => $agg, 'metric_field' => $field,
                'group_by' => $group, 'width' => $width, 'filters' => $filters, 'position' => $i, 'date_range' => 'this_month',
            ]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Models\Dashboard;
use Illuminate\Database\Seeder;

/** A starter dashboard so the app is useful straight after install. Edit or delete it freely. */
class DefaultDashboardSeeder extends Seeder
{
    public function run(): void
    {
        if (Dashboard::where('name', 'Business overview')->exists()) {
            return;
        }

        $d = Dashboard::create(['name' => 'Business overview', 'description' => 'Sales, appointments and lead funnel', 'sort_order' => 1]);

        $widgets = [
            ['Revenue', 'kpi', 'sales', 'sum', 'net_amount', null, 3],
            ['Appointments', 'kpi', 'appointments', 'count', null, null, 3],
            ['New guests', 'kpi', 'guests', 'count', null, null, 3],
            ['Leads', 'kpi', 'leads', 'count', null, null, 3],
            ['Revenue by day', 'line', 'sales', 'sum', 'net_amount', 'day', 8],
            ['Lead funnel', 'funnel', 'leads', 'count', null, 'stage', 4],
            ['Revenue by branch', 'bar', 'sales', 'sum', 'net_amount', 'branch', 6],
            ['Top employees by revenue', 'table', 'sales', 'sum', 'net_amount', 'employee', 6],
            ['Appointments by status', 'doughnut', 'appointments', 'count', null, 'status', 6],
            ['Leads by source', 'pie', 'leads', 'count', null, 'source', 6],
        ];

        foreach ($widgets as $i => [$title, $type, $dataset, $agg, $field, $group, $width]) {
            $d->widgets()->create([
                'title' => $title, 'type' => $type, 'dataset' => $dataset, 'aggregate' => $agg,
                'metric_field' => $field, 'group_by' => $group, 'width' => $width, 'position' => $i, 'date_range' => 'this_month',
            ]);
        }
    }
}

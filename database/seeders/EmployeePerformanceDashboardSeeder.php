<?php

namespace Database\Seeders;

use App\Models\Dashboard;
use Illuminate\Database\Seeder;

/**
 * "Employee performance (Module B)": leaderboard against salary × multiplier targets
 * (Grand Flora spec section 7). Safe to re-run.
 */
class EmployeePerformanceDashboardSeeder extends Seeder
{
    public const NAME = 'Employee performance (Module B)';

    public function run(): void
    {
        if (Dashboard::where('name', self::NAME)->exists()) {
            return;
        }

        $d = Dashboard::create(['name' => self::NAME, 'description' => 'Targets are salary × 7 (set salary on each employee page)', 'sort_order' => 0]);
        $service = [['field' => 'category', 'operator' => '=', 'value' => 'Service']];

        // [title, type, aggregate, field, group_by, width, options, filters]
        $widgets = [
            ['Employee leaderboard (achievement vs target)', 'employee_table', 'sum', 'net_amount', null, 12, [], []],
            ['Sales per employee', 'hbar', 'sum', 'net_amount', 'employee', 6, ['color' => 'primary'], []],
            ['ATV per employee', 'hbar', 'atv', null, 'employee', 6, ['color' => 'warning'], []],
            ['Services performed per employee', 'progress', 'count', null, 'employee', 6, ['color' => 'info'], $service],
            ['Sales trend (weekly)', 'area', 'sum', 'net_amount', 'week', 6, ['color' => 'primary'], []],
        ];

        foreach ($widgets as $i => [$title, $type, $agg, $field, $group, $width, $options, $filters]) {
            $d->widgets()->create([
                'title' => $title, 'type' => $type, 'dataset' => 'sales', 'aggregate' => $agg, 'metric_field' => $field,
                'group_by' => $group, 'width' => $width, 'filters' => $filters, 'options' => $options ?: null, 'position' => $i, 'date_range' => 'this_month',
            ]);
        }
    }
}

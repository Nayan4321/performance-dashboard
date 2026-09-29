<?php

namespace Database\Seeders;

use App\Models\Dashboard;
use Illuminate\Database\Seeder;

/**
 * "Management (Module A)": Grand Flora's management KPIs, built from the metric
 * definitions in their Management Dashboard specification (section 5).
 * Safe to re-run: it does nothing if a dashboard with this name already exists.
 */
class ManagementDashboardSeeder extends Seeder
{
    public const NAME = 'Management (Module A)';

    public function run(): void
    {
        if (Dashboard::where('name', self::NAME)->exists()) {
            return;
        }

        $d = Dashboard::create(['name' => self::NAME, 'description' => 'Sales ex VAT, footfall, ATV, target, guests, discount, service mix, day and staff distribution', 'sort_order' => 0]);
        $service = [['field' => 'category', 'operator' => '=', 'value' => 'Service']];

        // [title, type, aggregate, field, group_by, width, options, filters]
        $target = 1000000; // placeholder until the Target file is loaded; edit on each target widget

        // [title, type, aggregate, field, group_by, width, options, filters]  (spec section 6.2 cards, 6.3 charts, 6.4 table)
        $widgets = [
            ['Total Sales (AED, ex VAT)', 'stat', 'sum', 'net_amount', null, 3, ['icon' => 'cash-stack', 'color' => 'primary'], []],
            ['Achievement % vs target', 'radial', 'sum', 'net_amount', null, 3, ['color' => 'primary', 'target' => $target], []],
            ['Footfall (distinct visits)', 'stat', 'invoices', null, null, 3, ['icon' => 'door-open', 'color' => 'info'], []],
            ['Average Ticket Value', 'stat', 'atv', null, null, 3, ['icon' => 'receipt', 'color' => 'warning'], []],
            ['Discount %', 'stat', 'discount_pct', null, null, 3, ['icon' => 'percent', 'color' => 'danger'], []],
            ['New guests', 'stat', 'new_guests', null, null, 3, ['icon' => 'person-plus', 'color' => 'info'], []],
            ['Returning guests', 'stat', 'returning_guests', null, null, 3, ['icon' => 'arrow-repeat', 'color' => 'primary'], []],
            ['Service count', 'stat', 'count', null, null, 3, ['icon' => 'scissors', 'color' => 'secondary'], $service],
            ['Sales trend (weekly)', 'area', 'sum', 'net_amount', 'week', 8, ['color' => 'primary'], []],
            ['Service mix', 'donut', 'sum', 'net_amount', 'category', 4, [], []],
            ['Branch comparison: sales', 'column', 'sum', 'net_amount', 'branch', 6, ['color' => 'primary'], []],
            ['Branch comparison: ATV', 'hbar', 'atv', null, 'branch', 6, ['color' => 'warning'], []],
            ['Month over month: sales', 'column', 'sum', 'net_amount', 'month', 6, ['color' => 'primary'], []],
            ['Discounts by branch (%)', 'hbar', 'discount_pct', null, 'branch', 6, ['color' => 'danger'], []],
            ['Day of week: sales', 'column', 'sum', 'net_amount', 'weekday', 6, ['color' => 'primary'], []],
            ['Day of week: footfall', 'column', 'invoices', null, 'weekday', 6, ['color' => 'info'], []],
            ['Technician distribution: sales', 'progress', 'sum', 'net_amount', 'employee', 6, ['color' => 'primary'], []],
            ['Technician distribution: footfall', 'progress', 'invoices', null, 'employee', 6, ['color' => 'info'], []],
            ['Branch detail', 'branch_table', 'sum', 'net_amount', null, 12, ['target' => $target], []],
        ];

        foreach ($widgets as $i => [$title, $type, $agg, $field, $group, $width, $options, $filters]) {
            $d->widgets()->create([
                'title' => $title, 'type' => $type, 'dataset' => 'sales', 'aggregate' => $agg, 'metric_field' => $field,
                'group_by' => $group, 'width' => $width, 'filters' => $filters, 'options' => $options ?: null, 'position' => $i, 'date_range' => 'this_month',
            ]);
        }
    }
}

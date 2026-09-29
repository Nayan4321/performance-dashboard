<?php

namespace Database\Seeders;

use App\Models\Module;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            [Module::DASHBOARDS, 'Performance dashboards', 'Custom dashboards, KPIs, charts and funnels', true],
            [Module::PERFORMANCE, 'Performance data', 'Employees, appointments, sales and leads synced from Zenoti', true],
            [Module::CALLS, 'Call analytics', 'CallGear call performance', false],
            [Module::INVENTORY, 'Inventory', 'Products, branch stock orders, approvals and invoices', false],
        ];

        foreach ($modules as [$key, $name, $description, $global]) {
            Module::updateOrCreate(['key' => $key], ['name' => $name, 'description' => $description, 'is_global' => $global]);
        }
    }
}

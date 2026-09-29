<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\Call;
use App\Models\Collection;
use App\Models\Employee;
use App\Models\Guest;
use App\Models\Lead;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Sample data to try the dashboards and inventory before Zenoti is connected:
 *   php artisan db:seed --class=DemoDataSeeder
 * Demo logins all use the password "password".
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::firstOrCreate(['name' => 'Demo Clinics'], ['code' => 'DEMO']);
        $org->modules()->syncWithoutDetaching(Module::whereIn('key', [Module::INVENTORY, Module::CALLS])->pluck('id'));

        $warehouse = Branch::firstOrCreate(['name' => 'Central Warehouse'], ['organization_id' => $org->id, 'is_warehouse' => true]);
        $branches = collect(['Andheri', 'Bandra', 'Powai'])->map(fn ($n) => Branch::firstOrCreate(['name' => $n], ['organization_id' => $org->id]));

        $employees = collect();
        foreach ($branches as $b) {
            foreach (['Therapist', 'Therapist', 'Front desk'] as $i => $title) {
                $employees->push(Employee::firstOrCreate(
                    ['email' => strtolower($b->name).".$i@demo.test"],
                    ['first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'branch_id' => $b->id, 'organization_id' => $org->id, 'job_title' => $title]
                ));
            }
        }

        $services = ['Hair Botox' => 3500, 'Facial' => 1800, 'Laser Hair Removal' => 4500, 'Hydra Facial' => 2800, 'Consultation' => 500];
        for ($i = 0; $i < 400; $i++) {
            $e = $employees->random();
            $when = now()->subDays(rand(0, 60))->setTime(rand(10, 19), rand(0, 59));
            $guest = Guest::create(['branch_id' => $e->branch_id, 'first_name' => fake()->firstName(), 'phone' => fake()->phoneNumber(), 'gender' => fake()->randomElement(['Female', 'Male']), 'registered_at' => $when, 'source' => 'demo']);
            $svc = array_rand($services);
            Appointment::create(['branch_id' => $e->branch_id, 'employee_id' => $e->id, 'guest_id' => $guest->id, 'service_name' => $svc, 'status' => fake()->randomElement(['Serviced', 'Serviced', 'Serviced', 'In-progress', 'Open & confirmed', 'No-show', 'Cancelled']), 'price' => $services[$svc], 'start_time' => $when, 'booked_at' => $when->copy()->subDays(rand(0, 10))]);
            if (rand(0, 3)) {
                $category = fake()->randomElement(['Service', 'Service', 'Service', 'Product', 'Package', 'Gift card', 'Membership']);
                $net = $services[$svc] * (rand(85, 100) / 100);
                Sale::create(['branch_id' => $e->branch_id, 'employee_id' => $e->id, 'guest_id' => $guest->id, 'item_type' => $category, 'category' => $category, 'item_name' => $svc, 'gross_amount' => $services[$svc], 'net_amount' => $net, 'sold_at' => $when, 'status' => 'Closed']);
                Collection::create(['branch_id' => $e->branch_id, 'employee_id' => $e->id, 'guest_id' => $guest->id, 'payment_type' => fake()->randomElement(['Cash', 'Card']), 'amount' => $net, 'collected_at' => $when]);
            }
            Lead::create(['branch_id' => $e->branch_id, 'employee_id' => $e->id, 'source' => fake()->randomElement(['zenoti', 'callgear', 'manual']), 'channel' => fake()->randomElement(['Walk-in', 'Phone', 'Instagram', 'Website']), 'name' => $guest->first_name, 'stage' => fake()->randomElement(['new', 'new', 'contacted', 'contacted', 'booked', 'visited', 'converted', 'lost']), 'value' => $services[$svc], 'lead_at' => $when]);
            Call::create(['branch_id' => $e->branch_id, 'employee_id' => $e->id, 'direction' => fake()->randomElement(['in', 'out']), 'status' => fake()->randomElement(['answered', 'answered', 'answered', 'missed']), 'duration_seconds' => rand(20, 600), 'wait_seconds' => rand(0, 40), 'started_at' => $when]);
        }

        $cat = ProductCategory::firstOrCreate(['name' => 'Consumables']);
        $products = collect([
            ['HB-001', 'Hair Botox Kit', 'kit', 2200, 18], ['FC-010', 'Facial Cream 250ml', 'jar', 650, 18],
            ['GL-100', 'Nitrile Gloves (100)', 'box', 320, 12], ['TW-050', 'Disposable Towels (50)', 'pack', 180, 12],
        ])->map(fn ($p) => Product::firstOrCreate(['sku' => $p[0]], ['name' => $p[1], 'unit' => $p[2], 'unit_price' => $p[3], 'tax_rate' => $p[4], 'product_category_id' => $cat->id]));
        foreach ($products as $p) {
            BranchStock::firstOrCreate(['branch_id' => $warehouse->id, 'product_id' => $p->id], ['quantity' => 500]);
        }

        $logins = [
            ['manager@demo.test', 'Demo Management', 'management', null],
            ['stock@demo.test', 'Demo Stock Manager', 'stock-manager', $warehouse->id],
            ['andheri@demo.test', 'Andheri Branch Manager', 'branch-manager', $branches[0]->id],
        ];
        foreach ($logins as [$email, $name, $role, $branchId]) {
            User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password', 'organization_id' => $org->id, 'branch_id' => $branchId])->syncRoles([$role]);
        }
    }
}

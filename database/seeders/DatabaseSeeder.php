<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RolesAndPermissionsSeeder::class, ModuleSeeder::class, AdminDashboardSeeder::class, DefaultDashboardSeeder::class, OverviewDashboardSeeder::class, ManagementDashboardSeeder::class, EmployeePerformanceDashboardSeeder::class, CallgearPerformanceDashboardSeeder::class]);

        $email = env('ADMIN_EMAIL') ?: 'admin@example.com';
        $password = env('ADMIN_PASSWORD') ?: Str::password(16, symbols: false);
        $admin = User::firstOrCreate(['email' => $email], [
            'name' => env('ADMIN_NAME') ?: 'Super Admin',
            'password' => $password,
            'is_active' => true,
        ]);
        $admin->assignRole(User::SUPER_ADMIN);

        if ($admin->wasRecentlyCreated) {
            $this->command?->warn("Super admin created: $email / $password (change it after first login)");
        }
    }
}

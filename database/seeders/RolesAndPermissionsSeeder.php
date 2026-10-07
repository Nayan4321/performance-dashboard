<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /** Every permission the app checks, with a human description for the Roles screen. */
    public const PERMISSIONS = [
        'users.manage' => 'Create / edit users and assign roles',
        'roles.manage' => 'Edit roles and their permissions',
        'organizations.manage' => 'Manage organizations, branches and module visibility',
        'integrations.manage' => 'See integration status and run Zenoti / CallGear syncs',
        'dashboards.view' => 'Open performance dashboards',
        'dashboards.manage' => 'Create and edit dashboards and widgets',
        'performance.view-all-branches' => 'See performance of every branch',
        'performance.view-organization' => 'See performance of all branches in own organization',
        'performance.view-branch' => 'See performance of every employee in own branch (otherwise own numbers only)',
        'leads.manage' => 'Add and edit manual leads',
        'callgear.only' => 'Only CallGear data: CallGear dashboards and calls, no Zenoti sales, guests, appointments or employees',
        'inventory.access' => 'Open the inventory module',
        'inventory.products.manage' => 'Manage products, prices and stock levels',
        'inventory.orders.create' => 'Raise stock order requests for own branch',
        'inventory.orders.approve' => 'Approve / reject orders and change order status (stock manager)',
        'inventory.orders.view-all' => 'See orders of every branch',
        'inventory.invoices.generate' => 'Generate and download invoices',
        'guides.manage' => 'Add, edit and remove notes on the Call center guides',
        'complaints.manage' => 'Follow up complaints: change status, add the resolution, delete',
    ];

    public const ROLES = [
        'super-admin' => ['*'],
        'management' => [
            'dashboards.view', 'dashboards.manage', 'performance.view-all-branches', 'performance.view-organization',
            'performance.view-branch', 'integrations.manage', 'leads.manage', 'inventory.access', 'inventory.orders.view-all',
            'inventory.invoices.generate', 'complaints.manage',
        ],
        'org-admin' => [
            'users.manage', 'dashboards.view', 'performance.view-organization', 'performance.view-branch', 'leads.manage',
        ],
        'branch-manager' => [
            'dashboards.view', 'performance.view-branch', 'leads.manage', 'inventory.access', 'inventory.orders.create',
        ],
        'employee' => ['dashboards.view'],
        'callgear-agent' => ['dashboards.view', 'callgear.only', 'performance.view-all-branches', 'performance.view-branch'],
        // Callgear team admin: CallGear data only, plus logins and permissions of the callgear-agent users.
        'callgear-admin' => ['dashboards.view', 'callgear.only', 'performance.view-all-branches', 'performance.view-branch',
            'users.manage', 'guides.manage', 'complaints.manage'],
        'stock-manager' => [
            'inventory.access', 'inventory.products.manage', 'inventory.orders.approve', 'inventory.orders.view-all',
            'inventory.invoices.generate',
        ],
        'branch-stock-admin' => ['inventory.access', 'inventory.orders.create', 'inventory.invoices.generate'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(self::PERMISSIONS) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (self::ROLES as $role => $perms) {
            $r = Role::findOrCreate($role, 'web');
            $r->syncPermissions($perms === ['*'] ? Permission::all() : $perms);
        }
        // Temporary role under super admin: starts with everything except the CallGear-only restriction and
        // roles.manage; super admin changes it afterwards, so only set it the first time.
        $main = Role::findOrCreate('main-admin', 'web');
        if ($main->permissions()->count() === 0) {
            $main->syncPermissions(Permission::whereNotIn('name', ['callgear.only', 'roles.manage'])->get());
        }
    }
}

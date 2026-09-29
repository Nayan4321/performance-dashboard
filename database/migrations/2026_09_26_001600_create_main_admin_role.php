<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Temporary "main-admin" role (Nayan, 2026-09-26), one level under super admin. Only super admin assigns it
 * and changes its permissions. Starts with every permission except callgear.only (a restriction) and
 * roles.manage (so it can't raise its own permissions); super admin trims it on Roles & permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('main-admin', 'web');
        // On a fresh install the permissions don't exist yet; RolesAndPermissionsSeeder fills the role then.
        if ($role->permissions()->count() === 0 && Permission::where('name', 'users.manage')->exists()) {
            $role->syncPermissions(Permission::where('guard_name', 'web')->whereNotIn('name', ['callgear.only', 'roles.manage'])->get());
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'main-admin')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

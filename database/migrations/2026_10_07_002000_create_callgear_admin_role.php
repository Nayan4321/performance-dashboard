<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Adds the Callgear team admin role without re-running the roles seeder (which would reset edited roles).
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('roles') || Role::where('name', 'callgear-admin')->exists()) {
            return;
        }
        $perms = \Database\Seeders\RolesAndPermissionsSeeder::ROLES['callgear-admin'];
        foreach ($perms as $p) {
            Permission::findOrCreate($p, 'web');
        }
        Role::findOrCreate('callgear-admin', 'web')->syncPermissions($perms);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', 'callgear-admin')->delete();
    }
};

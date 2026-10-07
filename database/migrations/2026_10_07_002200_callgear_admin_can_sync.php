<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Nayan (2026-10-07): the Callgear admin may open Integrations and run syncs. Only adds the permission.
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('roles') || ! ($role = Role::where('name', 'callgear-admin')->first())) {
            return;
        }
        Permission::findOrCreate('integrations.manage', 'web');
        $role->givePermissionTo('integrations.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};

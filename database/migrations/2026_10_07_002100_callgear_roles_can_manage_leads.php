<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Callgear staff now see Leads (their own, or the team's for a Callgear admin). Only adds the permission.
return new class extends Migration
{
    public function up(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('roles')) {
            return;
        }
        Permission::findOrCreate('leads.manage', 'web');
        foreach (Role::whereIn('name', ['callgear-agent', 'callgear-admin'])->get() as $role) {
            $role->givePermissionTo('leads.manage');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void {}
};

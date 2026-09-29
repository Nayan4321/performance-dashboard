<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Dashboards can be limited to employees with one tag, and a "callgear-agent" role that sees
 * only CallGear data. Other roles are left exactly as they are (they may have been edited).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dashboards', 'employee_tag')) {
            Schema::table('dashboards', function (Blueprint $table) {
                $table->string('employee_tag', 60)->nullable()->after('visible_to_roles');
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $only = Permission::findOrCreate('callgear.only', 'web');
        foreach (['dashboards.view', 'performance.view-all-branches', 'performance.view-branch'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $role = Role::findOrCreate('callgear-agent', 'web');
        if ($role->permissions()->count() === 0) {
            $role->givePermissionTo(['dashboards.view', 'callgear.only', 'performance.view-all-branches', 'performance.view-branch']);
        }
        // Super admins hold every permission; this one only restricts, and they are exempt anyway.
        Role::where('name', 'super-admin')->first()?->givePermissionTo($only);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::table('dashboards', fn (Blueprint $table) => $table->dropColumn('employee_tag'));
    }
};

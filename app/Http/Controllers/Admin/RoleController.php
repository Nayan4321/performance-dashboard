<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        return view('admin.roles.index', [
            'roles' => Role::with('permissions')->withCount('users')->orderBy('name')->get(),
            'permissions' => Permission::orderBy('name')->pluck('name'),
            'labels' => RolesAndPermissionsSeeder::PERMISSIONS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:60|alpha_dash|unique:roles,name']);
        Role::create(['name' => strtolower($data['name']), 'guard_name' => 'web']);

        return back()->with('status', 'Role created. Tick its permissions below.');
    }

    public function update(Request $request, Role $role)
    {
        abort_if($role->name === User::SUPER_ADMIN, 422, 'Super admin always has every permission.');
        abort_if($role->name === User::MAIN_ADMIN && ! $request->user()->isSuperAdmin(), 403, 'Only super admin can change main-admin permissions.');
        $data = $request->validate(['permissions' => 'array', 'permissions.*' => 'exists:permissions,name']);
        $role->syncPermissions($data['permissions'] ?? []);

        return back()->with('status', "Permissions for {$role->name} saved.");
    }

    public function destroy(Request $request, Role $role)
    {
        abort_if($role->name === User::MAIN_ADMIN && ! $request->user()->isSuperAdmin(), 403, 'Only super admin can remove main-admin.');
        abort_if(in_array($role->name, array_keys(RolesAndPermissionsSeeder::ROLES), true), 422, 'Built-in roles cannot be deleted.');
        $role->delete();

        return back()->with('status', 'Role deleted.');
    }
}

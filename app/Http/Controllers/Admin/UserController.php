<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Manual users and synced (Zenoti / CallGear) users are managed the same way:
 * roles, extra permissions, organization/branch scope and per-user module visibility.
 * Org admins only see and manage users of their own organization.
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = $this->scoped($request->user())
            ->with(['roles', 'organization', 'branch'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%")))
            ->when($request->source, fn ($q, $s) => $q->where('source', $s))
            ->when($request->role, fn ($q, $r) => $q->role($r))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('admin.users.index', ['users' => $users, 'roles' => $this->assignableRoles($request->user())]);
    }

    public function create(Request $request)
    {
        return view('admin.users.form', ['user' => new User(['is_active' => true]), ...$this->options($request->user())]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $user = User::create($data['attributes'] + ['source' => 'manual']);
        $this->syncAccess($user, $data);

        return redirect()->route('admin.users.index')->with('status', "User {$user->name} created.");
    }

    public function edit(Request $request, User $user)
    {
        $this->authorizeTarget($request->user(), $user);

        return view('admin.users.form', ['user' => $user->load('roles', 'permissions', 'moduleOverrides', 'employee'), ...$this->options($request->user())]);
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeTarget($request->user(), $user);
        $data = $this->validated($request, $user);
        if (empty($data['attributes']['password'])) {
            unset($data['attributes']['password']);
        }
        $user->update($data['attributes']);
        $this->syncAccess($user, $data);

        return redirect()->route('admin.users.index')->with('status', "User {$user->name} updated.");
    }

    public function destroy(Request $request, User $user)
    {
        $this->authorizeTarget($request->user(), $user);
        abort_if($user->id === $request->user()->id, 422, 'You cannot delete yourself.');
        $user->delete();

        return back()->with('status', 'User deleted.');
    }

    // ---------------------------------------------------------------- helpers

    private function scoped(User $actor)
    {
        return User::query()->when(! $actor->seesEverything(), fn ($q) => $q->where('organization_id', $actor->organization_id));
    }

    private function authorizeTarget(User $actor, User $target): void
    {
        if ($actor->seesEverything()) {
            abort_if($target->isSuperAdmin() && ! $actor->isSuperAdmin(), 403);

            return;
        }
        abort_unless($target->organization_id === $actor->organization_id && ! $target->seesEverything(), 403);
    }

    /** Only super admin can hand out super-admin and main-admin; only full-access admins can hand out management. */
    private function assignableRoles(User $actor)
    {
        return Role::orderBy('name')->pluck('name')
            ->when(! $actor->isSuperAdmin(), fn ($c) => $c->reject(fn ($r) => in_array($r, [User::SUPER_ADMIN, User::MAIN_ADMIN], true)))
            ->when(! $actor->seesEverything(), fn ($c) => $c->reject(fn ($r) => $r === User::MANAGEMENT))
            ->values();
    }

    private function options(User $actor): array
    {
        $orgs = Organization::orderBy('name')->when(! $actor->seesEverything(), fn ($q) => $q->whereKey($actor->organization_id))->get();

        return [
            'organizations' => $orgs,
            'branches' => Branch::whereIn('organization_id', $orgs->pluck('id'))->orderBy('name')->get(),
            'roles' => $this->assignableRoles($actor),
            'permissions' => Permission::orderBy('name')->pluck('name'),
            'permissionLabels' => RolesAndPermissionsSeeder::PERMISSIONS,
            'modules' => Module::orderBy('name')->get(),
            'employees' => Employee::whereIn('organization_id', $orgs->pluck('id'))->orWhereNull('organization_id')->orderBy('first_name')->get(),
        ];
    }

    private function validated(Request $request, ?User $user): array
    {
        $actor = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'phone' => 'nullable|string|max:50',
            'password' => [$user ? 'nullable' : 'required', Password::min(8)],
            'organization_id' => 'nullable|exists:organizations,id',
            'branch_id' => 'nullable|exists:branches,id',
            'is_active' => 'boolean',
            'roles' => 'array',
            'roles.*' => [Rule::in($this->assignableRoles($actor)->all())],
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,name',
            'modules' => 'array', // module_id => '' | allow | deny
            'employee_id' => 'nullable|exists:employees,id',
        ]);

        if (! $actor->seesEverything()) {
            $data['organization_id'] = $actor->organization_id; // org admins can't move users out of their org
        }

        return [
            'attributes' => [
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null,
                'password' => $data['password'] ?? null,
                'organization_id' => $data['organization_id'] ?? null, 'branch_id' => $data['branch_id'] ?? null,
                'is_active' => $request->boolean('is_active'),
            ],
            'roles' => $data['roles'] ?? [],
            'permissions' => $actor->can('roles.manage') ? ($data['permissions'] ?? []) : null,
            'modules' => $data['modules'] ?? [],
            'employee_id' => $data['employee_id'] ?? null,
        ];
    }

    private function syncAccess(User $user, array $data): void
    {
        // Keep roles the actor can't assign (e.g. super-admin set by someone else).
        $kept = $user->roles->pluck('name')->diff($this->assignableRoles(request()->user()));
        $user->syncRoles($kept->merge($data['roles'])->unique()->all());

        if ($data['permissions'] !== null) {
            $user->syncPermissions($data['permissions']);
        }

        $overrides = collect($data['modules'])
            ->filter(fn ($v) => in_array($v, ['allow', 'deny'], true))
            ->mapWithKeys(fn ($v, $moduleId) => [$moduleId => ['allowed' => $v === 'allow']]);
        $user->moduleOverrides()->sync($overrides->all());

        Employee::where('user_id', $user->id)->where('id', '!=', $data['employee_id'])->update(['user_id' => null]);
        if ($data['employee_id']) {
            Employee::whereKey($data['employee_id'])->update(['user_id' => $user->id]);
        }
    }
}

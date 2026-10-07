<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;

    public const SUPER_ADMIN = 'super-admin';
    public const MANAGEMENT = 'management';

    protected $fillable = [
        'name', 'email', 'password', 'phone', 'organization_id', 'branch_id', 'source', 'is_active', 'last_login_at',
    ];

    protected $attributes = ['is_active' => true, 'source' => 'manual'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function moduleOverrides(): BelongsToMany
    {
        return $this->belongsToMany(Module::class)->withPivot('allowed');
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        return strtoupper(mb_substr($parts[0] ?? '?', 0, 1).(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? route('avatars.show', ['user' => $this->id, 'v' => substr(md5($this->avatar_path), 0, 8)]) : null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::SUPER_ADMIN);
    }

    /** Temporary admin role under super admin; only super admin assigns it and sets its permissions. */
    public const MAIN_ADMIN = 'main-admin';

    /** Super admin and management see every organization, branch and module. */
    public function seesEverything(): bool
    {
        return $this->hasAnyRole([self::SUPER_ADMIN, self::MANAGEMENT]);
    }

    public const CALLGEAR_ADMIN = 'callgear-admin';

    public const CALLGEAR_AGENT = 'callgear-agent';

    /** Permissions a Callgear admin may switch on or off for her agents (never user or role management). */
    public const CALLGEAR_GRANTABLE = ['guides.manage', 'complaints.manage'];

    /** Callgear team admin: manages only callgear-agent users (see Admin\UserController). */
    public function managesCallgearOnly(): bool
    {
        return ! $this->seesEverything() && $this->hasRole(self::CALLGEAR_ADMIN) && ! $this->hasAnyRole([self::MAIN_ADMIN, 'org-admin']);
    }

    /** Roles with "callgear.only" see CallGear dashboards and calls, and nothing from Zenoti. */
    public function callgearOnly(): bool
    {
        return ! $this->seesEverything() && $this->can('callgear.only');
    }

    /**
     * Module visibility: super admin / management see all. Otherwise a per-user
     * override wins; if there is none, the module must be global or enabled for
     * the user's organization.
     */
    public function canAccessModule(string $key): bool
    {
        if ($this->seesEverything()) {
            return true;
        }

        $module = Module::where('key', $key)->first();
        if (! $module) {
            return false;
        }

        $override = $this->moduleOverrides->firstWhere('id', $module->id);
        if ($override) {
            return (bool) $override->pivot->allowed;
        }

        if ($module->is_global) {
            return true;
        }

        return $this->organization_id
            && $module->organizations()->whereKey($this->organization_id)->exists();
    }

    /** Branch ids whose performance data this user may see. */
    public function visibleBranchIds(): ?array
    {
        if ($this->seesEverything() || $this->can('performance.view-all-branches')) {
            return null; // no restriction
        }
        if ($this->can('performance.view-organization') && $this->organization_id) {
            return Branch::where('organization_id', $this->organization_id)->pluck('id')->all();
        }

        return $this->branch_id ? [$this->branch_id] : [];
    }

    /** Employee id restriction for users who may only see their own numbers. */
    public function visibleEmployeeId(): ?int
    {
        if ($this->seesEverything() || $this->can('performance.view-branch')) {
            return null;
        }

        return $this->employee?->id ?? 0;
    }
}

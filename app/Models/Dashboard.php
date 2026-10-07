<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dashboard extends Model
{
    protected $fillable = ['name', 'description', 'organization_id', 'visible_to_roles', 'employee_tag', 'sort_order', 'created_by'];

    protected $casts = ['visible_to_roles' => 'array'];

    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('position');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Dashboards the given user is allowed to open, in display order. */
    public static function visibleTo(User $user): Collection
    {
        return static::orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (Dashboard $d) => $d->isVisibleTo($user))
            ->values();
    }

    public function isVisibleTo(User $user): bool
    {
        if ($user->seesEverything()) {
            return true;
        }
        if ($this->organization_id && $this->organization_id !== $user->organization_id) {
            return false;
        }

        if ($user->callgearOnly() && ! $this->isCallgearOnly() && ! $this->grantedByRole($user)) {
            return false;
        }

        return empty($this->visible_to_roles) || $user->hasAnyRole($this->visible_to_roles);
    }

    /** The dashboard's "Visible to roles" ticks one of the user's roles: an explicit grant, so CallGear-only limits don't apply. */
    public function grantedByRole(User $user): bool
    {
        return ! empty($this->visible_to_roles) && $user->hasAnyRole($this->visible_to_roles);
    }

    /** Every widget is one CallGear-only users may see (CallGear data, or tagged-team employee data). */
    public function isCallgearOnly(): bool
    {
        $widgets = $this->relationLoaded('widgets') ? $this->widgets : $this->widgets()->get(['id', 'dashboard_id', 'dataset']);

        return $widgets->isNotEmpty()
            && $widgets->every(fn ($w) => $w->setRelation('dashboard', $this)->allowedForCallgearOnly());
    }
}

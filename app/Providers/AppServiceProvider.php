<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191); // older MySQL / MariaDB on shared hosting
        Paginator::useBootstrapFive();

        // Super admin passes every permission check.
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Route middleware: `module:inventory` blocks users whose organization / overrides hide that module. */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next, string $module)
    {
        abort_unless($request->user()?->canAccessModule($module), 403, 'This module is not enabled for your account.');

        return $next($request);
    }
}

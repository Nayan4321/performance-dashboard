<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Zenoti pages (sales, guests, appointments, employees, activity, leads) are closed to CallGear-only users. */
class DenyCallgearOnly
{
    public function handle(Request $request, Closure $next)
    {
        abort_if($request->user()?->callgearOnly(), 403, 'Your role can only see CallGear data.');

        return $next($request);
    }
}

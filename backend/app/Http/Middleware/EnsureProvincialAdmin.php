<?php

namespace App\Http\Middleware;

use App\Support\AdminScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For admin routes about the whole province (e.g. hero slides): municipal admins get 403.
 * Use after role:admin.
 */
class EnsureProvincialAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        AdminScope::requireProvincial($request->user());

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed admin scopes: an admin account whose admin_scope isn't null, 'provincial' or
 * 'municipal' gets no admin access at all until admin:assign gives it a valid scope. Use after
 * role:admin.
 */
class EnsureValidAdminScope
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user()?->hasInvalidAdminScope(), 403,
            "This admin account's scope isn't valid, so admin access is blocked. Ask the system administrator to assign its scope.");

        return $next($request);
    }
}

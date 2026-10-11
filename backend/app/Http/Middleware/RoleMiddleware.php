<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * The only role names that ever exist in this system. Route definitions
     * (e.g. `role:admin`) must reference one of these — if a route typos a
     * role name, that's a bug in the route, and it should fail loudly at
     * request time rather than just silently locking every user out with a
     * generic 403 that looks identical to a normal permission denial.
     */
    private const VALID_ROLES = ['admin', 'business_owner', 'tourist'];

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        foreach ($roles as $role) {
            if (! in_array($role, self::VALID_ROLES, true)) {
                throw new \InvalidArgumentException("Unknown role \"{$role}\" referenced in route middleware.");
            }
        }

        if (! $request->user()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $userRole = $request->user()->role?->name;

        if (! $userRole || ! in_array($userRole, $roles, true)) {
            return response()->json(['message' => 'Unauthorized. Required role: '.implode(' or ', $roles)], 403);
        }

        return $next($request);
    }
}

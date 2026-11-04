<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Business Owner dashboard and listing actions require an approved (active)
 * account. Enforced server-side for every business route except the owner's
 * own registration-application endpoints, so a pending, rejected or suspended
 * owner can't act through the API even with a token issued earlier.
 */
class EnsureOwnerApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // A model built in memory may not carry the column default; read the stored value then.
        $status = $user->status ?? $user->newQuery()->whereKey($user->getKey())->value('status');

        if ($status !== 'active') {
            return response()->json([
                'message' => $user->hasRejectedApplication()
                    ? 'Your registration was not approved. Review the reason and resubmit your application.'
                    : 'Your account is not approved for Business Owner actions.',
                'code' => 'owner_not_approved',
            ], 403);
        }

        return $next($request);
    }
}

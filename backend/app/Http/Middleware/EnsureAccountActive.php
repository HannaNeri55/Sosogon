<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side account enforcement for every signed-in request.
 *
 * - An expired temporary suspension is lifted first (automatic restore).
 * - Suspended or deactivated accounts may only use the account-status area
 *   (status, notifications, appeals), their own profile read and logout.
 * - Closed accounts may only log out.
 *
 * Blocked requests get 403 with code "account_restricted" so the app can show the
 * Account Health page instead of failing silently.
 */
class EnsureAccountActive
{
    /** Route names/paths a restricted (suspended or deactivated) account can still use. */
    private const RESTRICTED_ALLOWED = ['api/v1/logout', 'api/v1/user', 'api/v1/account/*', 'api/v1/notifications', 'api/v1/notifications/*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->liftExpiredSuspension()) {
            $user->refresh();
        }

        if (! $user->isRestricted()) {
            return $next($request);
        }

        $state = $user->accountState();
        $allowed = $state === User::STATE_CLOSED ? ['api/v1/logout'] : self::RESTRICTED_ALLOWED;
        if ($request->is(...$allowed)) {
            return $next($request);
        }

        return response()->json([
            'message' => match ($state) {
                User::STATE_CLOSED => 'This account has been closed.',
                User::STATE_DEACTIVATED => 'Your account is deactivated. You can view your account status and submit an appeal.',
                default => 'Your account is temporarily suspended. You can view your account status and submit an appeal.',
            },
            'code' => 'account_restricted',
            'account_state' => $state,
            'suspended_until' => $user->suspended_until?->toIso8601String(),
        ], 403);
    }
}

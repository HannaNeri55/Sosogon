<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountViolation;
use App\Models\User;
use App\Services\AccountEnforcementService;
use App\Support\AdminScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manage Users list and basic profile edits. Account status is never changed here:
 * warnings, suspensions, deactivation, reactivation and closure go through
 * AdminAccountEnforcementController so each one is recorded, logged and notified.
 * There is no instant-delete endpoint.
 */
class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::with(['role:id,name', 'ownerApplication:id,user_id,status'])
            ->withCount([
                'violations',
                'violations as current_warnings_count' => fn ($q) => $q->where('action', AccountViolation::ACTION_WARNING)->current(),
            ]);

        // A municipal admin lists the business owners of their municipality and the tourists who
        // reviewed its listings (see AdminScope::applyToUsers).
        AdminScope::applyToUsers($query, $request->user());

        if ($request->filled('role')) {
            $query->whereHas('role', fn ($q) => $q->where('name', $request->role));
        }

        if ($request->filled('q')) {
            $search = $request->q;
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        // Closed accounts are kept but listed only when asked for.
        $request->input('state') === 'closed' ? $query->whereNotNull('closed_at') : $query->whereNull('closed_at');
        match ($request->input('state')) {
            'restricted' => $query->where('status', 'suspended'),
            'pending' => $query->where('status', 'pending'),
            default => null,
        };

        $perPage = $this->perPage($request, 100, 200);
        $users = $query->orderByDesc('created_at')->paginate($perPage);

        $users->getCollection()->each(function (User $user) {
            $user->liftExpiredSuspension();
            $state = $user->closed_at ? User::STATE_CLOSED : ($user->status === 'pending' ? User::STATE_PENDING : null);
            if (! $state && $user->status === 'suspended') {
                $user->refresh();
                $state = $user->status === 'suspended' ? ($user->suspended_until ? User::STATE_SUSPENDED : User::STATE_DEACTIVATED) : null;
            }
            $user->setAttribute('account_state', $state ?? ($user->current_warnings_count > 0 ? User::STATE_WARNING : User::STATE_ACTIVE));
        });

        return response()->json($users);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        AdminScope::authorizeUser($request->user(), $user);

        if ($request->has('status')) {
            // The one direct status change kept: activating an owner who registered before
            // registration applications existed (others are approved in Owner Registrations).
            if ($request->input('status') === 'active' && $user->status === 'pending' && ! $user->ownerApplication && ! $user->closed_at) {
                $user->forceFill(['status' => 'active'])->save();
                app(AccountEnforcementService::class)->log($request->user(), $user, 'activated_legacy_owner', User::STATE_PENDING, User::STATE_ACTIVE, 'Activated a business owner registered before registration review existed.', null, $request->ip());

                return response()->json(['message' => 'User activated.', 'user' => $user->load('role')]);
            }

            return response()->json([
                'message' => $user->status === 'pending' && $user->ownerApplication
                    ? 'This owner\'s registration must be approved in Owner Registrations, where the business permit is reviewed.'
                    : 'Account status can only be changed through Send warning, Suspend, Deactivate or Reactivate, so the reason is recorded and the user is notified.',
            ], 422);
        }

        $validated = $request->validate(['name' => 'sometimes|string|max:255']);
        $user->update($validated);

        return response()->json(['message' => 'User updated.', 'user' => $user->load('role')]);
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\OwnerApplicationRules;
use App\Models\BusinessOwnerApplication;
use App\Models\Role;
use App\Models\User;
use App\Support\Notify;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        if ($refusal = $this->alreadySignedIn()) {
            return $refusal;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
            'role' => 'required|in:tourist,business_owner',
            'phone' => 'nullable|string|max:20',
            'business_type' => 'required_if:role,business_owner|nullable|in:accommodation,food_beverage,retail_product',
        ] + OwnerApplicationRules::forRegistration());

        $role = Role::where('name', $validated['role'])->firstOrFail();
        $isOwner = $validated['role'] === 'business_owner';

        // Store the permit first (private disk) so the DB rows can reference it;
        // remove it again if the account can't be created.
        $permitPath = $isOwner
            ? $request->file('business_permit_file')->store('business-permits/registrations', 'local')
            : null;

        try {
            $user = DB::transaction(function () use ($validated, $role, $isOwner, $permitPath) {
                $user = User::create([
                    'role_id' => $role->id,
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                    'phone' => $validated['phone'] ?? null,
                    'status' => $isOwner ? 'pending' : 'active',
                    'business_type' => $validated['business_type'] ?? null,
                ]);

                if ($isOwner) {
                    BusinessOwnerApplication::create([
                        'user_id' => $user->id,
                        'business_name' => $validated['business_name'],
                        'business_type' => $validated['business_type'],
                        'business_address' => $validated['business_address'],
                        'municipality_id' => $validated['municipality_id'],
                        'barangay_id' => $validated['barangay_id'] ?? null,
                        'business_permit_number' => $validated['business_permit_number'],
                        'business_permit_path' => $permitPath,
                    ]);
                }

                return $user;
            });
        } catch (\Throwable $e) {
            if ($permitPath) {
                Storage::disk('local')->delete($permitPath);
            }

            throw $e;
        }

        if ($isOwner) {
            Notify::admins('owner_registered', 'New business owner registration',
                "{$user->name} registered {$validated['business_name']} (".Notify::typeLabel($user->business_type).') and is waiting for approval.',
                '/admin/owner-registrations');
        } else {
            Notify::admins('tourist_registered', 'New tourist account', "{$user->name} ({$user->email}) created a tourist account.", '/admin/users');
        }

        // If business owner, don't return a token (they can't log in yet)
        if ($isOwner) {
            return response()->json([
                'message' => 'Registration submitted. Your account is pending admin approval.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $role->name,
                    'status' => 'pending',
                    'business_type' => $user->business_type,
                ],
            ], 201);
        }

        $token = $this->issueToken($user);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $role->name,
            ],
            'token' => $token,
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        if ($refusal = $this->alreadySignedIn()) {
            return $refusal;
        }

        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! Auth::validate($validated)) {
            return response()->json(['message' => 'Incorrect email or password.'], 401);
        }

        $user = User::where('email', $validated['email'])->firstOrFail();

        // Closed accounts cannot sign in at all. Suspended or deactivated accounts can, but
        // EnsureAccountActive limits them to the Account Health page (status and appeals).
        $user->liftExpiredSuspension();
        $user->refresh();
        if ($user->closed_at) {
            return response()->json(['message' => 'This account has been closed. Contact the Sorsogon Tourism Office if you believe this is a mistake.', 'code' => 'account_closed'], 403);
        }

        // A rejected registration may sign in, but only to correct and resubmit it
        // (EnsureOwnerApproved blocks every other business route).
        if ($user->isBusinessOwner() && $user->status === 'pending' && ! $user->hasRejectedApplication()) {
            return response()->json([
                'message' => 'Your account is pending approval. You will be notified once the Tourism Office reviews your application.',
            ], 403);
        }

        $token = $this->issueToken($user);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->name,
                'phone' => $user->phone,
                'business_type' => $user->business_type,
                'status' => $user->status,
                'application_status' => $user->ownerApplication?->status, 'account_state' => $user->accountState(), 'suspended_until' => $user->suspended_until?->toIso8601String(),
            ],
            'token' => $token,
        ]);
    }

    /**
     * One account per browser: the site keeps one login token per browser (shared by its tabs), and
     * sends it with every request. A login or registration that arrives with a valid token is
     * refused, so another account never replaces the signed-in one; that token and its account are
     * left as they are. Logging out (which deletes the token) lets another account sign in.
     */
    private function alreadySignedIn(): ?JsonResponse
    {
        $current = Auth::guard('sanctum')->user();
        if (! $current) {
            return null;
        }

        return response()->json([
            'message' => "You're already logged in as {$current->name} in this browser. Log out first to use a different account.",
            'code' => 'already_authenticated',
            'user' => ['name' => $current->name, 'email' => $current->email, 'role' => $current->role?->name],
        ], 409);
    }

    /**
     * New login token. The user's expired tokens (e.g. from tabs closed without logging
     * out) are deleted first, since no scheduler is guaranteed to prune them.
     */
    private function issueToken(User $user): string
    {
        $user->tokens()
            ->where('created_at', '<', now()->subMinutes(config('sanctum.expiration')))
            ->delete();

        return $user->createToken('auth-token')->plainTextToken;
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        PasswordBroker::sendResetLink($request->only('email'));

        // Always return a generic message — never reveal whether the account exists.
        return response()->json([
            'message' => 'If an account with that email exists, a password reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $status = PasswordBroker::reset(
            $validated,
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();

                // Revoke all existing sessions so a leaked token can't survive a password reset.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status === PasswordBroker::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Your password has been reset successfully. You can now log in.',
            ]);
        }

        return response()->json([
            'message' => 'This password reset link is invalid or has expired.',
            'errors' => [
                'email' => ['This password reset link is invalid or has expired.'],
            ],
        ], 422);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function user(Request $request): JsonResponse
    {
        $user = $request->user()->load('role');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->name,
            'phone' => $user->phone,
            'avatar' => $user->avatar,
            'business_type' => $user->business_type,
            'status' => $user->status,
            'application_status' => $user->ownerApplication?->status, 'account_state' => $user->accountState(), 'suspended_until' => $user->suspended_until?->toIso8601String(),
            'created_at' => $user->created_at,
        ]);
    }
}

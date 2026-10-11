<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
            'role' => 'required|in:tourist,business_owner',
            'phone' => 'nullable|string|max:20',
            'business_type' => 'required_if:role,business_owner|nullable|in:accommodation,food_beverage,retail_product',
        ]);

        $role = Role::where('name', $validated['role'])->firstOrFail();

        $user = User::create([
            'role_id' => $role->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
            'status' => $validated['role'] === 'business_owner' ? 'pending' : 'active',
            'business_type' => $validated['business_type'] ?? null,
        ]);

        // If business owner, don't return a token (they can't log in yet)
        if ($validated['role'] === 'business_owner') {
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
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! Auth::validate($validated)) {
            return response()->json(['message' => 'Incorrect Password'], 401);
        }

        $user = User::where('email', $validated['email'])->firstOrFail();

        if ($user->status === 'suspended') {
            return response()->json(['message' => 'Account suspended. Contact administrator.'], 403);
        }

        if ($user->isBusinessOwner() && $user->status === 'pending') {
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
            ],
            'token' => $token,
        ]);
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
            'created_at' => $user->created_at,
        ]);
    }
}

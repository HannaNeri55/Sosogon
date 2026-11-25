<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminInviteMail;
use App\Models\Municipality;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountEnforcementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * The provincial administrator gives a municipality's tourism office its admin account (route:
 * provincial admins only). The account manages that one municipality (AdminScope) and is never
 * provincial: a provincial account is still created only with `php artisan admin:create`.
 *
 * No password is chosen here. The account gets a random one nobody knows, and the officer sets
 * their own through the link emailed to them (the password reset flow), so the provincial admin
 * never knows it. Deactivating or restoring the account stays in Manage Users (adminAccess).
 */
class AdminOfficeAccountController extends Controller
{
    public function __construct(private AccountEnforcementService $enforcement)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'email' => ['required', 'string', 'email:filter', 'max:255', function ($attribute, $value, $fail) {
                if (User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($value))])->exists()) {
                    $fail('An account with this email already exists.');
                }
            }],
            'phone' => 'nullable|string|max:20',
            'municipality_id' => 'required|integer|exists:municipalities,id',
            'title' => 'nullable|string|max:100',
            'confirm_municipality' => 'required|string',
        ], [
            'municipality_id.required' => 'Choose the municipality this office manages.',
        ]);

        $municipality = Municipality::findOrFail($validated['municipality_id']);
        // Typed out, like admin:create asks, so a wrong pick in the list can't slip through.
        if (mb_strtolower(trim($validated['confirm_municipality'])) !== mb_strtolower($municipality->name)) {
            return response()->json([
                'message' => "Type the municipality's name exactly to confirm.",
                'errors' => ['confirm_municipality' => ["Type \"{$municipality->name}\" to confirm."]],
            ], 422);
        }
        $role = Role::where('name', 'admin')->firstOrFail();
        $admin = $request->user();
        $title = trim((string) ($validated['title'] ?? '')) ?: null;

        $user = DB::transaction(function () use ($validated, $municipality, $role, $admin, $title, $request) {
            $user = User::create([
                'role_id' => $role->id,
                'name' => trim($validated['name']),
                'email' => trim($validated['email']),
                'phone' => trim((string) ($validated['phone'] ?? '')) ?: null,
                'password' => Str::password(40),
                'status' => 'active',
            ]);
            $user->forceFill([
                'email_verified_at' => now(),
                'admin_scope' => User::ADMIN_SCOPE_MUNICIPAL,
                'municipality_id' => $municipality->id,
                'admin_title' => $title,
            ])->save();
            $this->enforcement->log($admin, $user, 'admin_created', null, 'active', "Tourism office account for {$municipality->name}",
                ['scope' => User::ADMIN_SCOPE_MUNICIPAL, 'municipality_id' => $municipality->id, 'admin_title' => $title], $request->ip());

            return $user;
        });

        $sent = $this->sendInvite($user, $municipality);

        return response()->json([
            'message' => $sent
                ? "Account created. {$user->name} will get an email to set their password, then manage {$municipality->name}."
                : "Account created, but the email could not be sent. Ask {$user->name} to use Forgot password on the login page with {$user->email}.",
            'email_sent' => $sent,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ], 201);
    }

    /** Emails the officer a link to set their password; false when it couldn't be sent. */
    private function sendInvite(User $user, Municipality $municipality): bool
    {
        $frontend = rtrim(config('app.frontend_url'), '/');
        $token = Password::broker()->createToken($user);
        $url = $frontend.'/reset-password?token='.$token.'&email='.urlencode($user->email);

        try {
            Mail::to($user->email)->send(new AdminInviteMail($user->name, $municipality->name, $url, $frontend.'/login/admin'));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send admin invitation email', ['email' => $user->email, 'error' => $e->getMessage()]);

            return false;
        }
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\OwnerApplicationRules;
use App\Models\BusinessOwnerApplication;
use App\Models\Role;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Support\Notify;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /** Letters (any language, e.g. ñ) with spaces, periods, hyphens and apostrophes between them. */
    private const NAME_PATTERN = "/^[\pL\pM][\pL\pM .'’-]*$/u";

    /** A Philippine mobile number (09XXXXXXXXX or +639XXXXXXXXX), or another country's number with its + code. */
    private const PHONE_PATTERN = '/^(09\d{9}|\+639\d{9}|\+(?!63)[1-9]\d{6,14})$/';

    private const EMAIL_TAKEN = 'This email is already registered. Log in instead, or use a different email.';

    /** The same wording as the registration forms (frontend/src/utils/authValidation.js). */
    private const MESSAGES = [
        'name.required' => 'Enter your full name.',
        'name.min' => 'Enter your full name (at least 2 letters).',
        'name.max' => 'Keep your name to 100 characters or fewer.',
        'name.regex' => 'Use letters only. Spaces, periods, hyphens and apostrophes are fine.',
        'email.required' => 'Enter your email address.',
        'email.email' => 'Enter a valid email address, like juandelacruz@gmail.com.',
        'email.max' => 'Keep your email address to 255 characters or fewer.',
        'password.required' => 'Enter a password.',
        'password_confirmation.required' => 'Re-enter your password.',
        'password_confirmation.same' => 'Passwords do not match.',
        'phone.required' => 'Enter your phone number.',
        'phone.regex' => 'Enter a mobile number like 09171234567. From abroad, start with + and your country code.',
        'business_name.required_if' => 'Enter your business name.',
        'business_address.required_if' => 'Enter the business address.',
        'municipality_id.required_if' => 'Choose the municipality.',
        'barangay_id.required_if' => 'Choose the barangay.',
        'business_permit_number.required_if' => 'Enter the business permit number.',
        'business_permit_file.required_if' => 'Upload your business permit.',
        'business_permit_file.mimes' => 'Upload a PDF or Word document (.pdf, .doc or .docx).',
        'business_permit_file.max' => 'The file is too large. Upload one of 10 MB or less.',
    ];

    public function register(Request $request): JsonResponse
    {
        if ($refusal = $this->alreadySignedIn()) {
            return $refusal;
        }

        // "0917 123 4567" and "0917-123-4567" are stored as 09171234567.
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => preg_replace('/[\s\-().]/', '', $request->input('phone'))]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:100', 'regex:'.self::NAME_PATTERN],
            'email' => ['required', 'string', 'email:filter', 'max:255', function (string $attribute, mixed $value, \Closure $fail) {
                if (self::emailTaken($value)) {
                    $fail(self::EMAIL_TAKEN);
                }
            }],
            'password' => ['required', 'string', new StrongPassword],
            'password_confirmation' => 'required|same:password',
            'role' => 'required|in:tourist,business_owner',
            'phone' => ['required', 'string', 'regex:'.self::PHONE_PATTERN],
            'business_type' => 'required_if:role,business_owner|nullable|in:accommodation,food_beverage,retail_product',
        ] + OwnerApplicationRules::forRegistration(), self::MESSAGES);

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
            Notify::admins((int) $validated['municipality_id'], 'owner_registered', 'New business owner registration',
                "{$user->name} registered {$validated['business_name']} (".Notify::typeLabel($user->business_type).') and is waiting for approval.',
                '/admin/owner-registrations');
        } else {
            // Tourists aren't tied to a municipality: the provincial admins are told.
            Notify::admins(null, 'tourist_registered', 'New tourist account', "{$user->name} ({$user->email}) created a tourist account.", '/admin/users');
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
            'email' => 'required|email:filter',
            'password' => 'required|string',
        ], [
            'email.required' => 'Enter your email address.',
            'email.email' => 'Enter a valid email address, like juandelacruz@gmail.com.',
            'password.required' => 'Enter your password.',
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
                // Admins: their scope (provincial or one municipality) and designation.
                'admin' => $user->adminProfile(),
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

    /**
     * Whether an email can still be used to register. Registration itself says the same when
     * it refuses a taken email, so this reveals nothing more; it is throttled like the forms.
     */
    public function emailAvailable(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|string|email:filter|max:255'], self::MESSAGES);

        return response()->json(['available' => ! self::emailTaken($validated['email'])]);
    }

    /** Whether an account uses this email, ignoring letter case (Hanna@Gmail.com is hanna@gmail.com). */
    private static function emailTaken(string $email): bool
    {
        return User::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists();
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
            'password' => ['required', 'string', new StrongPassword],
            'password_confirmation' => 'required|same:password',
        ], self::MESSAGES);

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
            'admin' => $user->adminProfile(),
            'created_at' => $user->created_at,
        ]);
    }
}

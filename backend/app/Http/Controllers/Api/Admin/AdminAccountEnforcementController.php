<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountActionLog;
use App\Models\AccountAppeal;
use App\Models\AccountViolation;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\AccountEnforcementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin account management: user details and activity, warnings, temporary
 * suspensions, deactivations, reactivation and the guarded account-closure step.
 * All changes go through AccountEnforcementService (records, audit log, notifications).
 */
class AdminAccountEnforcementController extends Controller
{
    /** Evidence and appeal attachments: images or PDF, up to 5 MB each. */
    public const FILE_RULE = 'file|mimes:jpg,jpeg,png,webp,pdf|max:5120';

    public function __construct(private AccountEnforcementService $enforcement) {}

    /** Options for the enforcement form. */
    public function meta(): JsonResponse
    {
        return response()->json([
            'categories' => collect(AccountViolation::CATEGORIES)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'actions' => AccountViolation::ACTIONS,
            'warning_days' => AccountViolation::WARNING_DAYS,
        ]);
    }

    /** Profile, account state, activity and full enforcement history of one user. */
    public function show(int $id): JsonResponse
    {
        $user = User::with(['role:id,name', 'ownerApplication:id,user_id,status,business_name'])
            ->withCount(['ratings', 'itineraries', 'businessSubmissions'])
            ->findOrFail($id);
        $user->liftExpiredSuspension();
        $user->refresh();

        $lastSeen = $user->tokens()->max('last_used_at');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role?->name,
                'business_type' => $user->business_type,
                'status' => $user->status,
                'account_state' => $user->accountState(),
                'suspended_until' => $user->suspended_until?->toIso8601String(),
                'closed_at' => $user->closed_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'last_seen_at' => $lastSeen,
                'application' => $user->ownerApplication,
                'counts' => [
                    'reviews' => $user->ratings_count,
                    'itineraries' => $user->itineraries_count,
                    'business_submissions' => $user->business_submissions_count,
                ],
                'is_protected' => $user->isAdmin(),
            ],
            'violations' => $user->violations()
                ->with(['reviewer:id,name', 'resolver:id,name', 'latestAppeal'])
                ->latest('id')
                ->get(),
            'account_log' => AccountActionLog::where('user_id', $user->id)->latest('id')->limit(50)->get(),
            'activity' => ActivityLog::where('user_id', $user->id)->latest('created_at')->limit(50)
                ->get(['id', 'action', 'description', 'model_type', 'model_id', 'created_at']),
        ]);
    }

    /** Warn, temporarily suspend or deactivate (reviewed decision; never automatic). */
    public function enforce(Request $request, int $id): JsonResponse
    {
        $target = User::findOrFail($id);
        $validated = $request->validate([
            'action' => ['required', Rule::in(AccountViolation::ACTIONS)],
            'category' => ['required', Rule::in(array_keys(AccountViolation::CATEGORIES))],
            'reason' => 'required|string|min:5|max:255',
            'details' => 'required|string|min:20|max:5000',
            'evidence_notes' => 'nullable|string|max:5000',
            'evidence_files' => 'nullable|array|max:5',
            'evidence_files.*' => self::FILE_RULE,
            'suspension_days' => 'required_if:action,suspension|nullable|integer|min:1|max:365',
            'review_date' => 'nullable|date|after:today',
            'reported_at' => 'nullable|date|before_or_equal:today',
            'confirmed' => 'accepted',
        ], [
            'confirmed.accepted' => 'Please confirm the action after reading its consequences.',
            'suspension_days.required_if' => 'Enter how many days the suspension lasts.',
        ]);

        $violation = $this->enforcement->enforce(
            $target,
            $request->user(),
            $validated,
            $request->file('evidence_files', []),
            $request->ip(),
        );

        $label = ['warning' => 'Warning sent', 'suspension' => 'Account suspended', 'deactivation' => 'Account deactivated'][$violation->action];

        return response()->json([
            'message' => "{$label}. The user has been notified.",
            'violation' => $violation->load('reviewer:id,name'),
            'account_state' => $target->fresh()->accountState(),
        ], 201);
    }

    public function reactivate(Request $request, int $id): JsonResponse
    {
        $target = User::findOrFail($id);
        $validated = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        $this->enforcement->reactivate($target, $request->user(), $validated['reason'], $request->ip());

        return response()->json(['message' => 'Account reactivated. The user has been notified.', 'account_state' => $target->fresh()->accountState()]);
    }

    /**
     * The separate "delete account" process: closes the account (no sign-in) while keeping all
     * data. The admin must give a reason and type the user's email address to confirm.
     */
    public function close(Request $request, int $id): JsonResponse
    {
        $target = User::findOrFail($id);
        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:1000',
            'confirm_email' => ['required', 'string', function ($attribute, $value, $fail) use ($target) {
                if (mb_strtolower(trim($value)) !== mb_strtolower($target->email)) {
                    $fail("Type the user's email address exactly to confirm.");
                }
            }],
        ]);

        $this->enforcement->close($target, $request->user(), $validated['reason'], $request->ip());

        return response()->json(['message' => 'Account closed. All of the user\'s data has been kept.']);
    }

    /** Download one evidence file of a violation (admins only; files are stored privately). */
    public function evidence(int $violationId, int $index): StreamedResponse
    {
        $file = AccountViolation::findOrFail($violationId)->evidence_files[$index] ?? abort(404);

        return $this->stream($file);
    }

    /** Download one attachment of an appeal (admins only). */
    public function appealAttachment(int $appealId, int $index): StreamedResponse
    {
        $file = AccountAppeal::findOrFail($appealId)->attachments[$index] ?? abort(404);

        return $this->stream($file);
    }

    private function stream(array $file): StreamedResponse
    {
        $disk = Storage::disk(AccountEnforcementService::FILES_DISK);
        abort_unless(! empty($file['path']) && $disk->exists($file['path']), 404);

        return $disk->response($file['path'], $file['name'] ?? basename($file['path']), [
            'Content-Type' => $file['mime'] ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

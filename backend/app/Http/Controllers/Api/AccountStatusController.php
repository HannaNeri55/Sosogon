<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Admin\AdminAccountEnforcementController;
use App\Http\Controllers\Controller;
use App\Models\AccountViolation;
use App\Services\AccountEnforcementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The signed-in user's own Account Health: current state, enforcement history, appeal
 * status, notifications and appeal submission. Reachable even while suspended or
 * deactivated (see EnsureAccountActive). Users can read their records but never change them.
 */
class AccountStatusController extends Controller
{
    public function __construct(private AccountEnforcementService $enforcement) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $violations = $user->violations()->with('latestAppeal')->latest('id')->get()->map(fn (AccountViolation $v) => [
            'id' => $v->id,
            'action' => $v->action,
            'category' => $v->category,
            'category_label' => $v->category_label,
            'reason' => $v->reason,
            'details' => $v->details,
            'suspension_days' => $v->suspension_days,
            'starts_at' => $v->starts_at?->toIso8601String(),
            'ends_at' => $v->ends_at?->toIso8601String(),
            'review_date' => $v->review_date?->toDateString(),
            'status' => $v->status,
            'is_current' => $v->status === AccountViolation::STATUS_ACTIVE && (! $v->ends_at || $v->ends_at->isFuture()),
            'resolution_note' => $v->status === AccountViolation::STATUS_ACTIVE ? null : $v->resolution_note,
            'resolved_at' => $v->resolved_at?->toIso8601String(),
            'can_appeal' => $v->status !== AccountViolation::STATUS_REVERSED && ! $v->latestAppeal,
            'appeal' => $v->latestAppeal ? [
                'id' => $v->latestAppeal->id,
                'status' => $v->latestAppeal->status,
                'explanation' => $v->latestAppeal->explanation,
                'attachments' => collect($v->latestAppeal->attachments ?? [])->map(fn ($f, $i) => ['index' => $i, 'name' => $f['name'] ?? 'Attachment'])->values(),
                'submitted_at' => $v->latestAppeal->created_at?->toIso8601String(),
                'decision_note' => $v->latestAppeal->decision_note,
                'decided_at' => $v->latestAppeal->decided_at?->toIso8601String(),
            ] : null,
        ]);

        return response()->json([
            'account_state' => $user->accountState(),
            'suspended_until' => $user->suspended_until?->toIso8601String(),
            'role' => $user->role?->name,
            'violations' => $violations,
            'notifications' => $user->notifications()->latest()->limit(30)->get(['id', 'data', 'read_at', 'created_at']),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markNotificationsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => 'Notifications marked as read.']);
    }

    public function appeal(Request $request, int $violationId): JsonResponse
    {
        $violation = $request->user()->violations()->findOrFail($violationId);
        $validated = $request->validate([
            'explanation' => 'required|string|min:30|max:5000',
            'attachments' => 'nullable|array|max:3',
            'attachments.*' => AdminAccountEnforcementController::FILE_RULE,
        ], [
            'explanation.min' => 'Please explain your appeal in at least 30 characters.',
        ]);

        $appeal = $this->enforcement->submitAppeal($request->user(), $violation, $validated['explanation'], $request->file('attachments', []));

        return response()->json([
            'message' => 'Your appeal has been submitted. The Sorsogon Tourism Office will review it and notify you of the decision.',
            'appeal' => $appeal->only(['id', 'status', 'created_at']),
        ], 201);
    }

    /** The user's own appeal attachments only. */
    public function appealAttachment(Request $request, int $appealId, int $index): StreamedResponse
    {
        $appeal = $request->user()->appeals()->findOrFail($appealId);
        $file = $appeal->attachments[$index] ?? abort(404);
        $disk = Storage::disk(AccountEnforcementService::FILES_DISK);
        abort_unless(! empty($file['path']) && $disk->exists($file['path']), 404);

        return $disk->response($file['path'], $file['name'] ?? basename($file['path']), [
            'Content-Type' => $file['mime'] ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

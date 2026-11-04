<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccountActionLog;
use App\Models\AccountAppeal;
use App\Models\AccountViolation;
use App\Services\AccountEnforcementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only admin views of enforcement records: violation records (with appeal status),
 * the appeals queue and decisions, and the append-only account activity log.
 */
class AdminViolationController extends Controller
{
    public function __construct(private AccountEnforcementService $enforcement) {}

    public function violations(Request $request): JsonResponse
    {
        $query = AccountViolation::with(['user:id,name,email,role_id', 'user.role:id,name', 'reviewer:id,name', 'resolver:id,name', 'latestAppeal.decider:id,name']);

        if ($request->filled('q')) {
            $search = $request->q;
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        foreach (['category', 'action', 'status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('role')) {
            $query->whereHas('user.role', fn ($q) => $q->where('name', $request->role));
        }
        if ($request->filled('appeal')) {
            $request->appeal === 'none'
                ? $query->doesntHave('appeals')
                : $query->whereHas('appeals', fn ($q) => $q->where('status', $request->appeal));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);

        return response()->json($query->latest('id')->paginate($perPage));
    }

    public function appeals(Request $request): JsonResponse
    {
        $query = AccountAppeal::with(['user:id,name,email,role_id', 'user.role:id,name', 'violation.reviewer:id,name', 'decider:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('q')) {
            $search = $request->q;
            $query->whereHas('user', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        $perPage = min((int) $request->input('per_page', 25), 100);

        // Pending first (oldest waiting longest at the top), then decided ones, newest first.
        return response()->json(
            $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
                ->orderByRaw("CASE WHEN status = 'pending' THEN created_at END ASC")
                ->latest('id')
                ->paginate($perPage)
        );
    }

    public function decideAppeal(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'decision' => 'required|in:approved,rejected',
            'note' => 'required|string|min:10|max:2000',
        ]);

        $appeal = $this->enforcement->decideAppeal(
            AccountAppeal::findOrFail($id),
            $request->user(),
            $validated['decision'],
            $validated['note'],
            $request->ip(),
        );

        return response()->json([
            'message' => $validated['decision'] === 'approved'
                ? 'Appeal approved. The action was reversed and the user has been notified.'
                : 'Appeal rejected. The user has been notified.',
            'appeal' => $appeal,
        ]);
    }

    /** Account activity log: read-only, there is no endpoint that edits or deletes it. */
    public function log(Request $request): JsonResponse
    {
        $query = AccountActionLog::query();

        if ($request->filled('q')) {
            $search = $request->q;
            $query->where(fn ($q) => $q->where('user_name', 'like', "%{$search}%")
                ->orWhere('user_email', 'like', "%{$search}%")
                ->orWhere('admin_name', 'like', "%{$search}%"));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        $perPage = min((int) $request->input('per_page', 50), 200);

        return response()->json($query->latest('id')->paginate($perPage));
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Rating;
use App\Models\ReviewReport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Tourism Office's queue of reported reviews. A report moves from Pending Review to Reviewed
 * (looked at, still open), then to Action Taken (the review is hidden from the public and left out
 * of averages, never deleted) or Dismissed (the review stays). Changing a decision later restores
 * or hides the review to match.
 */
class AdminReviewReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['nullable', Rule::in(['all', ...array_keys(ReviewReport::STATUSES)])]]);
        $status = $validated['status'] ?? 'pending';

        $reports = ReviewReport::query()
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->with(['rating.user:id,name', 'rating.reply', 'rating.rateable:id,name', 'reporter:id,name', 'decider:id,name'])
            ->latest()
            ->orderByDesc('id')
            ->paginate(10);

        $reports->getCollection()->each(fn (ReviewReport $report) => $report->rating?->setAttribute('listing_type', AdminReviewController::typeKey($report->rating->rateable_type)));

        $counts = ReviewReport::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return response()->json($reports->toArray() + [
            'counts' => collect(ReviewReport::STATUSES)->map(fn ($label, $key) => (int) ($counts[$key] ?? 0)),
            'reasons' => ReviewReport::REASONS,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $report = ReviewReport::with('rating.rateable')->findOrFail($id);
        $validated = $request->validate([
            'status' => ['required', Rule::in(['reviewed', 'action_taken', 'dismissed'])],
            // Action and dismissal are explained to the owner who reported the review.
            'decision_note' => ['nullable', 'string', 'max:1000', Rule::requiredIf(in_array($request->input('status'), ['action_taken', 'dismissed'], true))],
        ]);

        DB::transaction(function () use ($report, $validated, $request) {
            $report->forceFill([
                'status' => $validated['status'],
                'decision_note' => isset($validated['decision_note']) ? trim($validated['decision_note']) : $report->decision_note,
                'decided_by' => $request->user()->id,
                'decided_at' => now(),
            ])->save();

            $this->syncVisibility($report->rating);
        });

        return response()->json(['message' => 'Report updated.', 'report' => $report->fresh(['decider:id,name'])]);
    }

    public function evidence(int $id): StreamedResponse
    {
        $report = ReviewReport::findOrFail($id);
        abort_unless($report->evidence_path && Storage::disk('local')->exists($report->evidence_path), 404, 'No evidence was attached.');

        return Storage::disk('local')->response($report->evidence_path, 'report-'.$report->id.'-evidence.'.pathinfo($report->evidence_path, PATHINFO_EXTENSION),
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    /** A review is hidden while any report on it ended in action, and public again otherwise. */
    private function syncVisibility(?Rating $rating): void
    {
        if (! $rating) {
            return;
        }
        $hide = $rating->reports()->where('status', 'action_taken')->exists();
        if ($hide === ($rating->hidden_at !== null)) {
            return;
        }
        $rating->forceFill(['hidden_at' => $hide ? now() : null])->save();

        if ($rating->rateable) {
            $this->refreshAggregates($rating->rateable);
        }
    }

    /** The listing's stored average and review count, recounted from its visible reviews. */
    private function refreshAggregates(Model $listing): void
    {
        if (method_exists($listing, 'recalculateRating')) {
            $listing->recalculateRating();

            return;
        }
        // Shops and products have no stored aggregate columns; they are computed on read.
        if (! Schema::hasColumn($listing->getTable(), 'average_rating')) {
            return;
        }
        $listing->forceFill([
            'average_rating' => $listing->ratings()->visible()->avg('score') ?? 0,
            'total_reviews' => $listing->ratings()->visible()->count(),
        ])->save();
    }
}

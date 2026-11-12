<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Models\Rating;
use App\Models\ReviewReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A Business Owner reports a review on their own listing to the Tourism Office: a reason, what is
 * wrong with it and, optionally, evidence (a photo or PDF, kept private). The review stays public
 * while the report is open; only the Tourism Office decides (see AdminReviewReportController).
 */
class ReviewReportController extends Controller
{
    public function store(Request $request, int $ratingId): JsonResponse
    {
        $rating = Rating::with('rateable')->findOrFail($ratingId);
        if (! $rating->rateable || (int) $rating->rateable->user_id !== (int) $request->user()->id) {
            abort(403, 'You can only report reviews of your own listing.');
        }

        $validated = $request->validate([
            'reason' => ['required', Rule::in(array_keys(ReviewReport::REASONS))],
            'details' => 'required|string|min:10|max:1000',
            'evidence' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);

        if ($rating->reports()->whereIn('status', ReviewReport::OPEN)->exists()) {
            return response()->json(['message' => 'This review has already been reported and is waiting for the Tourism Office.'], 422);
        }

        $report = ReviewReport::create([
            'rating_id' => $rating->id,
            'reported_by' => $request->user()->id,
            'reason' => $validated['reason'],
            'details' => trim($validated['details']),
            'evidence_path' => $request->file('evidence')?->store("review-reports/{$rating->id}", 'local'),
        ]);

        return response()->json(['message' => 'Report sent to the Tourism Office.', 'report' => $report], 201);
    }
}

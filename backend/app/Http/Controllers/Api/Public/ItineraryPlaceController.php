<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Services\InterestMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The places a tourist can pick in the trip form ("Places you'd love to visit"): the published
 * destinations in the chosen towns that the chosen interests select, by the same InterestMatcher
 * rule itinerary generation uses, so the form never offers a place the planner would leave out.
 * Open to guests, who fill in the form before logging in. Shows only what the form displays:
 * never review fields or tag slugs.
 */
class ItineraryPlaceController extends Controller
{
    private const PER_PAGE = 50;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request, InterestMatcher $matcher): JsonResponse
    {
        $validated = $request->validate([
            // Unknown towns simply match nothing (no lookup per ID).
            'municipality_ids' => 'required|array|min:1|max:30',
            'municipality_ids.*' => 'integer|min:1|distinct',
            'interests' => 'required|array|min:1|max:8',
            'interests.*' => 'string|distinct|in:'.implode(',', array_keys(InterestMatcher::INTEREST_CATEGORIES)),
            'q' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
        ]);
        $perPage = $this->perPage($request, self::PER_PAGE, self::MAX_PER_PAGE);
        $sightseeing = $matcher->sightseeing($validated['interests']) !== [];

        // Food and Shopping only: they're planned from restaurants and shops, so there are no places to pick.
        if (! $sightseeing) {
            $places = new LengthAwarePaginator([], 0, $perPage, LengthAwarePaginator::resolveCurrentPage(), ['path' => $request->url()]);

            return response()->json(['sightseeing' => false] + $places->toArray());
        }

        $places = Destination::published()
            ->whereIn('municipality_id', array_map('intval', $validated['municipality_ids']))
            ->tap(fn ($query) => $matcher->constrain($query, $validated['interests']))
            ->when($validated['q'] ?? null, fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->select('id', 'name', 'category_id', 'municipality_id')
            ->with([
                'category:id,name',
                'municipality:id,name',
                'images:id,destination_id,image_path,thumbnail_path,alt_text,is_primary,sort_order',
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (Destination $destination) {
                // The form shows one picture: the primary one, else the first.
                $image = $destination->images->firstWhere('is_primary', true) ?? $destination->images->first();

                return [
                    'id' => $destination->id,
                    'name' => $destination->name,
                    'category' => $destination->category?->only(['id', 'name']),
                    'municipality' => $destination->municipality?->only(['id', 'name']),
                    'images' => $image ? [$image->only(['id', 'image_path', 'thumbnail_path', 'alt_text', 'is_primary'])] : [],
                ];
            });

        return response()->json(['sightseeing' => true] + $places->toArray());
    }
}

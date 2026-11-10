<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Concerns\FindsNearbyPlaces;
use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccommodationController extends Controller
{
    use FindsNearbyPlaces;

    public function index(Request $request): JsonResponse
    {
        $query = Accommodation::approved()
            ->with([
                'municipality',
                'barangay',
                'images' => fn ($query) => $query
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ]);

        if ($request->filled('municipality_id')) {
            $query->where('municipality_id', $request->municipality_id);
        }

        if ($request->filled('barangay_id')) {
            $query->where('barangay_id', $request->barangay_id);
        }

        // The trip planner's stay picker: each stay's approved options and estimates in the same
        // request (without counting a page view the way show() does).
        if ($request->boolean('with_rooms')) {
            $query->with([
                'rooms' => fn ($rooms) => $rooms->approved()->with('images'),
                'estimates',
                'roomTypes',
            ]);
        }

        if ($request->filled('type')) {
            // Its main type or one of its classifications; the value is quoted as stored in the JSON list.
            $query->where(fn ($q) => $q->where('type', $request->type)->orWhere('classifications', 'like', '%"'.addcslashes($request->type, '%_"\\').'"%'));
        }

        if ($request->filled('price_range')) {
            $query->where('price_range', $request->price_range);
        }

        if ($request->filled('q')) {
            $search = $request->q;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $perPage = $this->perPage($request, 12, 50);

        return response()->json(
            $query->orderBy('name')->paginate($perPage)
        );
    }

    public function show(int $id): JsonResponse
    {
        $accommodation = Accommodation::approved()
            ->with([
                'municipality',
                'barangay',
                'images' => fn ($query) => $query
                    ->orderByDesc('is_primary')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
                // Newest first, so a Tourist's fresh review leads the carousel ahead of seeded demo reviews.
                'ratings' => fn ($query) => $query
                    ->visible()
                    ->with(['user:id,name', 'reply'])
                    ->latest()
                    ->orderByDesc('id'),
                'rooms' => fn ($query) => $query
                    ->approved()
                    ->with('images'),
                // Usual check-in/out and an estimated nightly rate when not stated (shown as approximate).
                'estimates',
                // Legacy display-only room types; the stay's options are 'rooms'.
                'roomTypes',
            ])
            ->findOrFail($id);

        $accommodation->increment('view_count');

        return response()->json($accommodation->toArray() + $this->nearbyPlaces($accommodation, 6));
    }
}

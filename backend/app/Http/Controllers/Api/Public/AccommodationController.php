<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\FoodBeverage;
use App\Models\RetailShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class AccommodationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Accommodation::approved()
            ->with(['municipality', 'barangay', 'images']);

        if ($request->filled('municipality_id')) {
            $query->where('municipality_id', $request->municipality_id);
        }

        if ($request->filled('barangay_id')) {
            $query->where('barangay_id', $request->barangay_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
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

        $perPage = min($request->input('per_page', 12), 50);

        return response()->json($query->orderBy('name')->paginate($perPage));
    }

    public function show(int $id): JsonResponse
    {
        $accommodation = Accommodation::approved()
            ->with([
                'municipality',
                'barangay',
                'images',
                'ratings.user',
                'rooms' => fn ($query) => $query->approved()->with('images'),
            ])
            ->findOrFail($id);

        $accommodation->increment('view_count');

        return response()->json($accommodation->toArray() + [
            'nearby_food_beverages' => $this->nearest(FoodBeverage::approved(), $accommodation),
            'nearby_retail_shops' => $this->nearest(RetailShop::approved(), $accommodation),
        ]);
    }

    /** The closest listings to the accommodation from any town, each with its `distance_km`. */
    private function nearest(Builder $query, Accommodation $from, int $limit = 6): Collection
    {
        if ($from->latitude === null || $from->longitude === null) {
            return collect();
        }

        return $query->whereNotNull('latitude')->whereNotNull('longitude')
            ->with(['images', 'municipality'])
            ->get()
            ->each(fn ($place) => $place->setAttribute('distance_km', round($this->km($from, $place), 1)))
            ->sortBy('distance_km')
            ->take($limit)
            ->values();
    }

    private function km($a, $b): float
    {
        $dLat = deg2rad((float) $b->latitude - (float) $a->latitude);
        $dLng = deg2rad((float) $b->longitude - (float) $a->longitude);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad((float) $a->latitude)) * cos(deg2rad((float) $b->latitude)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }
}

<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\RetailShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DestinationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Destination::published()
            ->with(['category', 'municipality', 'barangay', 'images']);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('municipality_id')) {
            $query->where('municipality_id', $request->municipality_id);
        }

        if ($request->filled('barangay_id')) {
            $query->where('barangay_id', $request->barangay_id);
        }

        if ($request->filled('q')) {
            $search = $request->q;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->input('sort', 'name');
        $sortDir = $request->input('dir', 'asc');
        $allowedSorts = ['name', 'average_rating', 'view_count', 'created_at'];

        if ($sortBy === 'featured') {
            $query->orderByDesc('is_featured')->orderByDesc('created_at');
        } elseif (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'desc' ? 'desc' : 'asc');
        }

        $perPage = min($request->input('per_page', 12), 50);
        $destinations = $query->paginate($perPage);

        return response()->json($destinations);
    }

    public function show(int $id): JsonResponse
    {
        $destination = Destination::published()
            ->with(['category', 'municipality', 'barangay', 'images', 'ratings.user', 'activities'])
            ->findOrFail($id);

        $destination->increment('view_count');

        // Accommodations, food & beverage spots, and retail products in the same municipality
        $nearbyAccommodations = Accommodation::approved()
            ->where('municipality_id', $destination->municipality_id)
            ->with(['images', 'municipality'])
            ->limit(6)
            ->get();

        $nearbyFoodBeverages = FoodBeverage::approved()
            ->where('municipality_id', $destination->municipality_id)
            ->with('images')
            ->limit(6)
            ->get();

        $nearbyRetailShops = RetailShop::approved()
            ->where('municipality_id', $destination->municipality_id)
            ->with('images')
            ->limit(6)
            ->get();

        return response()->json([
            'destination' => $destination,
            'nearby_accommodations' => $nearbyAccommodations,
            'nearby_food_beverages' => $nearbyFoodBeverages,
            'nearby_retail_shops' => $nearbyRetailShops,
        ]);
    }
}

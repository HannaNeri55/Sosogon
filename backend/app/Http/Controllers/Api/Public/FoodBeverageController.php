<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Concerns\FindsNearbyPlaces;
use App\Http\Controllers\Controller;
use App\Models\FoodBeverage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FoodBeverageController extends Controller
{
    use FindsNearbyPlaces;

    public function index(Request $request): JsonResponse
    {
        $query = FoodBeverage::approved()
            ->with(['municipality', 'barangay', 'images']);

        if ($request->filled('municipality_id')) {
            $query->where('municipality_id', $request->municipality_id);
        }

        if ($request->filled('barangay_id')) {
            $query->where('barangay_id', $request->barangay_id);
        }

        if ($request->filled('cuisine_type')) {
            $query->where('cuisine_type', $request->cuisine_type);
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
        $item = FoodBeverage::approved()
            ->with([
                'municipality',
                'barangay',
                'images',
                'ratings' => fn ($query) => $query
                    ->visible()
                    ->with(['user:id,name', 'reply'])
                    ->latest()
                    ->orderByDesc('id'),
                'menuItems' => fn ($query) => $query->approved()->with('estimates'),
                // Typical hours, cuisine and price per person when not stated (shown as approximate).
                'estimates',
            ])
            ->findOrFail($id);

        $item->increment('view_count');

        return response()->json($item->toArray() + $this->nearbyPlaces($item, 6));
    }
}

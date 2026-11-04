<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Concerns\FindsNearbyPlaces;
use App\Http\Controllers\Controller;
use App\Models\RetailShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetailShopController extends Controller
{
    use FindsNearbyPlaces;

    public function index(Request $request): JsonResponse
    {
        $query = RetailShop::approved()
            ->with(['municipality', 'barangay', 'images']);

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
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $perPage = min($request->input('per_page', 12), 50);

        return response()->json($query->orderBy('name')->paginate($perPage));
    }

    public function show(int $id): JsonResponse
    {
        $shop = RetailShop::approved()
            ->with([
                'municipality',
                'barangay',
                'images',
                'products' => fn ($query) => $query->approved()->with('images'),
                // An estimated souvenir price range when no products are listed (shown as approximate).
                'estimates',
                'ratings' => fn ($query) => $query
                    ->visible()
                    ->with(['user:id,name', 'reply'])
                    ->latest()
                    ->orderByDesc('id'),
            ])
            ->findOrFail($id);

        $shop->increment('view_count');
        $shop->setAttribute('average_rating', round((float) $shop->ratings->avg('score'), 2));
        $shop->setAttribute('total_reviews', $shop->ratings->count());

        return response()->json($shop->toArray() + $this->nearbyPlaces($shop, 6));
    }
}

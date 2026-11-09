<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only overview of reviews and owner replies across every listing type.
 * There are no moderation rules yet, so this deliberately offers no edits.
 */
class AdminReviewController extends Controller
{
    private const TYPES = [
        'accommodation' => Accommodation::class,
        'destination' => Destination::class,
        'food_beverage' => FoodBeverage::class,
        'retail_shop' => RetailShop::class,
        'retail_product' => RetailProduct::class,
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'nullable|in:'.implode(',', array_keys(self::TYPES)),
            'filter' => 'nullable|in:all,replied,unreplied',
            'source' => 'nullable|in:all,demo,genuine',
        ]);

        $reviews = Rating::query()
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('rateable_type', self::TYPES[$type]))
            ->when(($validated['filter'] ?? null) === 'replied', fn ($query) => $query->has('reply'))
            ->when(($validated['filter'] ?? null) === 'unreplied', fn ($query) => $query->doesntHave('reply'))
            // Seeded sample reviews carry the "Demo Review:" comment prefix.
            ->when(($validated['source'] ?? null) === 'demo', fn ($query) => $query->where('comment', 'like', 'Demo Review:%'))
            ->when(($validated['source'] ?? null) === 'genuine', fn ($query) => $query->where(
                fn ($q) => $q->whereNull('comment')->orWhere('comment', 'not like', 'Demo Review:%')
            ))
            ->with(['user:id,name', 'reply.user:id,name', 'rateable:id,name'])
            ->latest()
            ->orderByDesc('id')
            ->paginate(15);

        $typeKeys = array_flip(self::TYPES);
        $reviews->getCollection()->each(
            fn (Rating $rating) => $rating->setAttribute('listing_type', $typeKeys[$rating->rateable_type] ?? null)
        );

        $counts = Rating::selectRaw('rateable_type, count(*) as total')
            ->groupBy('rateable_type')
            ->pluck('total', 'rateable_type');

        return response()->json($reviews->toArray() + [
            'counts' => collect(self::TYPES)->map(fn ($class) => (int) ($counts[$class] ?? 0)),
        ]);
    }
}

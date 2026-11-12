<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every guest review and owner response across all listing types, with filters and a summary of
 * the reviews the filters select. Reviews are never edited here; reported reviews are decided in
 * AdminReviewReportController.
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

    /** The listing type key (accommodation, destination, …) for a rateable class. */
    public static function typeKey(?string $class): ?string
    {
        return array_flip(self::TYPES)[$class] ?? null;
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'nullable|in:'.implode(',', array_keys(self::TYPES)),
            'filter' => 'nullable|in:all,replied,unreplied',
            'rating' => 'nullable|integer|between:1,5',
            'search' => 'nullable|string|max:100',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        // Listing type, search and dates choose the reviews the summary describes; rating and
        // owner response narrow the list below it.
        $selected = $this->selected($validated);

        $reviews = (clone $selected)
            ->when($validated['rating'] ?? null, fn ($query, $score) => $query->where('score', $score))
            ->when(($validated['filter'] ?? null) === 'replied', fn ($query) => $query->has('reply'))
            ->when(($validated['filter'] ?? null) === 'unreplied', fn ($query) => $query->doesntHave('reply'))
            ->with(['user:id,name', 'reply.user:id,name', 'rateable:id,name', 'latestReport'])
            ->withCount('reports')
            ->latest()
            ->orderByDesc('id')
            ->paginate(15);

        $reviews->getCollection()->each(fn (Rating $rating) => $rating->setAttribute('listing_type', self::typeKey($rating->rateable_type)));

        $counts = Rating::selectRaw('rateable_type, count(*) as total')
            ->groupBy('rateable_type')
            ->pluck('total', 'rateable_type');

        return response()->json($reviews->toArray() + [
            'counts' => collect(self::TYPES)->map(fn ($class) => (int) ($counts[$class] ?? 0)),
            'summary' => [
                'total' => (clone $selected)->count(),
                'average' => round((float) (clone $selected)->avg('score'), 1),
                'negative' => (clone $selected)->where('score', '<=', 2)->count(),
                'unreplied' => (clone $selected)->doesntHave('reply')->count(),
                'by_rating' => collect(range(5, 1))->mapWithKeys(fn ($score) => [$score => (clone $selected)->where('score', $score)->count()]),
            ],
        ]);
    }

    private function selected(array $validated): Builder
    {
        $search = trim((string) ($validated['search'] ?? ''));
        $like = '%'.addcslashes($search, '%_\\').'%';

        return Rating::query()
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('rateable_type', self::TYPES[$type]))
            ->when($validated['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($validated['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('comment', 'like', $like)
                ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $like))
                ->orWhereHasMorph('rateable', array_values(self::TYPES), fn ($listing) => $listing->where('name', 'like', $like))));
    }
}

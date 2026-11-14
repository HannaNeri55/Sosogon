<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Support\AdminScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Every guest review and owner response across all listing types, filtered on the server (listing
 * type, rating, owner response, search, submission dates) so the list, its pages and its summary
 * all describe the same reviews. Reviews are never edited here; reported reviews are decided in
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
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ], ['to.after_or_equal' => 'The end date must be on or after the start date.']);

        // Every filter applies to the list and to its summary. The rating chips count each rating
        // under the other filters, so each shows how many reviews choosing it would list.
        // A municipal admin's reviews are those of their municipality's listings.
        $withoutRating = AdminScope::applyToRatings($this->filtered($validated), $request->user());
        $filtered = (clone $withoutRating)->when($validated['rating'] ?? null, fn ($query, $score) => $query->where('score', (int) $score));

        $reviews = (clone $filtered)
            ->with(['user:id,name', 'reply.user:id,name', 'rateable:id,name', 'latestReport'])
            ->withCount('reports')
            ->latest()
            ->orderByDesc('id')
            ->paginate(15);

        $reviews->getCollection()->each(fn (Rating $rating) => $rating->setAttribute('listing_type', self::typeKey($rating->rateable_type)));

        $counts = AdminScope::applyToRatings(Rating::query(), $request->user())->selectRaw('rateable_type, count(*) as total')
            ->groupBy('rateable_type')
            ->pluck('total', 'rateable_type');
        $byRating = (clone $withoutRating)->selectRaw('score, count(*) as total')->groupBy('score')->pluck('total', 'score');

        return response()->json($reviews->toArray() + [
            'counts' => collect(self::TYPES)->map(fn ($class) => (int) ($counts[$class] ?? 0)),
            'summary' => [
                'total' => (clone $filtered)->count(),
                'average' => round((float) (clone $filtered)->avg('score'), 1),
                'negative' => (clone $filtered)->where('score', '<=', 2)->count(),
                'unreplied' => (clone $filtered)->doesntHave('reply')->count(),
                'by_rating' => collect(range(5, 1))->mapWithKeys(fn ($score) => [$score => (int) ($byRating[$score] ?? 0)]),
            ],
        ]);
    }

    /**
     * Reviews matching every filter except the rating. Dates are calendar days in Sorsogon's time
     * zone (app.local_timezone); reviews are stored in UTC, so each day becomes its UTC bounds.
     */
    private function filtered(array $validated): Builder
    {
        $search = trim((string) ($validated['search'] ?? ''));
        $like = '%'.addcslashes($search, '%_\\').'%';
        $zone = config('app.local_timezone');
        $day = fn (string $date) => Carbon::createFromFormat('Y-m-d', $date, $zone);

        return Rating::query()
            ->when($validated['type'] ?? null, fn ($query, $type) => $query->where('rateable_type', self::TYPES[$type]))
            ->when($validated['from'] ?? null, fn ($query, $from) => $query->where('ratings.created_at', '>=', $day($from)->startOfDay()->setTimezone(config('app.timezone'))))
            ->when($validated['to'] ?? null, fn ($query, $to) => $query->where('ratings.created_at', '<=', $day($to)->endOfDay()->setTimezone(config('app.timezone'))))
            ->when(($validated['filter'] ?? null) === 'replied', fn ($query) => $query->has('reply'))
            ->when(($validated['filter'] ?? null) === 'unreplied', fn ($query) => $query->doesntHave('reply'))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('comment', 'like', $like)
                ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $like))
                ->orWhereHasMorph('rateable', array_values(self::TYPES), fn ($listing) => $listing->where('name', 'like', $like))));
    }
}

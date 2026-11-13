<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\FoodBeverage;
use App\Models\ItineraryItem;
use App\Models\PageView;
use App\Models\Rating;
use App\Models\RetailShop;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * How the signed-in owner's listing is doing, for their portal's overview: page views of its
 * public page (per day for the last 30 days, and the 30 days before for comparison), its
 * guest reviews (average, star breakdown, response rate, the latest few) and how many
 * itineraries include it. Only real counts: nothing is estimated.
 */
class BusinessInsightsController extends Controller
{
    /** Portal type => [model, public page path prefix, itinerary_items column]. */
    private const TYPES = [
        'accommodation' => [Accommodation::class, '/accommodations/', 'accommodation_id'],
        'food_beverage' => [FoodBeverage::class, '/food-beverage/', 'food_beverage_id'],
        'retail_shop' => [RetailShop::class, '/shops/', 'retail_shop_id'],
    ];

    public function show(Request $request, string $type): JsonResponse
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        [$model, $prefix, $column] = self::TYPES[$type];

        $listings = $model::where('user_id', $request->user()->id)->get(['id', 'name', 'status', 'view_count']);
        $ids = $listings->pluck('id')->all();
        $paths = array_map(fn ($id) => $prefix.$id, $ids);

        // Page views per day (Philippine days, as recorded), the last 30 days and the 30 before.
        $today = CarbonImmutable::parse(PageView::today());
        $from = $today->subDays(29);
        $daily = PageView::whereIn('path', $paths)->where('date', '>=', $from->toDateString())
            ->selectRaw('date, sum(views) as views')->groupBy('date')->pluck('views', 'date')
            ->mapWithKeys(fn ($views, $date) => [CarbonImmutable::parse($date)->toDateString() => (int) $views]);
        $previous = (int) PageView::whereIn('path', $paths)
            ->whereBetween('date', [$from->subDays(30)->toDateString(), $from->subDay()->toDateString()])->sum('views');

        $ratings = Rating::where('rateable_type', $model)->whereIn('rateable_id', $ids);
        $total = (clone $ratings)->count();
        $replied = (clone $ratings)->has('reply')->count();
        $byRating = (clone $ratings)->selectRaw('score, count(*) as n')->groupBy('score')->pluck('n', 'score');

        $itineraries = ItineraryItem::whereIn($column, $ids)->whereHas('itinerary', fn ($q) => $q->whereNotNull('user_id'));

        return response()->json([
            'listings' => $listings->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'status' => $l->status, 'url' => $prefix.$l->id]),
            'views' => [
                'all_time' => (int) $listings->sum('view_count'),
                'last_30_days' => array_sum($daily->all()),
                'previous_30_days' => $previous,
                'daily' => collect(range(0, 29))->map(fn ($i) => $from->addDays($i)->toDateString())
                    ->map(fn ($date) => ['date' => $date, 'views' => $daily[$date] ?? 0])->values(),
            ],
            'reviews' => [
                'total' => $total,
                'average' => round((float) (clone $ratings)->avg('score'), 1),
                'by_rating' => collect(range(1, 5))->mapWithKeys(fn ($star) => [$star => (int) ($byRating[$star] ?? 0)]),
                'response_rate' => $total ? (int) round($replied / $total * 100) : 0,
                'last_30_days' => (clone $ratings)->where('created_at', '>=', now()->subDays(30))->count(),
                'recent' => (clone $ratings)->with(['user:id,name', 'reply'])->latest()->orderByDesc('id')->limit(3)->get(),
            ],
            'itineraries' => [
                'total' => (clone $itineraries)->distinct()->count('itinerary_id'),
                'last_30_days' => (clone $itineraries)->where('created_at', '>=', now()->subDays(30))->distinct()->count('itinerary_id'),
            ],
        ]);
    }
}

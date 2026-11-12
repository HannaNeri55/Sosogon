<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Activity;
use App\Models\Destination;
use App\Models\Event;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\PageView;
use App\Models\RetailShop;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Records one page view when a visitor opens a page of the public website. The site sends this
 * once per navigation (not per API call, render or poll). Only real public pages count: anything
 * else (admin and business pages, sign-in, unknown addresses, a listing that doesn't exist or
 * isn't public) is accepted and ignored, so it can't fill the figures with failed visits.
 */
class PageViewController extends Controller
{
    /** Public pages without an id in their address. */
    private const PAGES = ['/', '/destinations', '/accommodations', '/food-beverage', '/shops', '/events', '/about',
        '/plan-trip', '/search', '/photo-credits', '/privacy-policy', '/terms-and-conditions'];

    public function store(Request $request): Response
    {
        $request->validate(['path' => 'required|string|max:191']);
        $path = $this->page((string) $request->input('path'));

        if ($path !== null) {
            $now = now();
            try {
                DB::table('page_views')->upsert(
                    [['date' => PageView::today(), 'path' => $path, 'views' => 1, 'created_at' => $now, 'updated_at' => $now]],
                    ['date', 'path'],
                    ['views' => DB::raw('views + 1'), 'updated_at' => $now]
                );
            } catch (\Throwable $e) {
                Log::warning('Could not record a page view', ['path' => $path, 'error' => $e->getMessage()]);
            }
        }

        return response()->noContent();
    }

    /** The page's address as counted (no query, no trailing slash), or null when it isn't a public page. */
    private function page(string $raw): ?string
    {
        $path = '/'.trim((string) parse_url($raw, PHP_URL_PATH), '/');

        if (in_array($path, self::PAGES, true)) {
            return $path;
        }

        if (preg_match('#^/shared/itineraries/([A-Za-z0-9]{20,100})$#', $path, $m)) {
            return Itinerary::where('share_token', $m[1])->exists() ? '/shared/itineraries' : null;
        }

        if (! preg_match('#^/(destinations|accommodations|food-beverage|shops|events|activities)/(\d{1,10})$#', $path, $m)) {
            return null;
        }
        $listing = match ($m[1]) {
            'destinations' => Destination::published(),
            'accommodations' => Accommodation::approved(),
            'food-beverage' => FoodBeverage::approved(),
            'shops' => RetailShop::approved(),
            'events' => Event::published(),
            'activities' => Activity::query(),
        };

        return $listing->whereKey((int) $m[2])->exists() ? $path : null;
    }
}

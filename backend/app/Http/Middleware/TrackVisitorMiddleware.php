<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TrackVisitorMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Track after response for specific detail routes
        $route = $request->route();
        if ($route && $response->isSuccessful()) {
            $this->trackView($request);
        }

        return $response;
    }

    private function trackView(Request $request): void
    {
        $route = $request->route();
        $routeName = $route?->getName();

        $mapping = [
            'destinations.show' => ['App\Models\Destination', 'destination'],
            'accommodations.show' => ['App\Models\Accommodation', 'accommodation'],
            'food-beverages.show' => ['App\Models\FoodBeverage', 'food_beverage'],
            'retail-products.show' => ['App\Models\RetailProduct', 'retail_product'],
            'retail-shops.show' => ['App\Models\RetailShop', 'retail_shop'],
        ];

        if (! isset($mapping[$routeName])) {
            return;
        }

        [$type, $param] = $mapping[$routeName];
        $id = $route->parameter($param) ?? $route->parameter('id');

        if (! $id) {
            return;
        }

        // One row per listing per day (the all-time view_count is raised by each show action).
        // The upsert adds the view in a single statement, so two visitors opening the same page
        // at once can't both try to create that day's row. A failure here is logged, never
        // shown: the visitor still gets the page.
        try {
            $now = now();
            DB::table('visitor_statistics')->upsert(
                [[
                    'trackable_type' => $type,
                    'trackable_id' => (int) $id,
                    'date' => $now->toDateString(),
                    'page_views' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['trackable_type', 'trackable_id', 'date'],
                ['page_views' => DB::raw('page_views + 1'), 'updated_at' => $now]
            );
        } catch (\Throwable $e) {
            Log::warning('Could not record a page view', ['route' => $routeName, 'id' => $id, 'error' => $e->getMessage()]);
        }
    }
}

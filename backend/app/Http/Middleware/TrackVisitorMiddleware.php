<?php

namespace App\Http\Middleware;

use App\Models\VisitorStatistic;
use Closure;
use Illuminate\Http\Request;
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

        if (isset($mapping[$routeName])) {
            [$type, $param] = $mapping[$routeName];
            $id = $route->parameter($param) ?? $route->parameter('id');

            if ($id) {
                VisitorStatistic::updateOrCreate(
                    ['trackable_type' => $type, 'trackable_id' => $id, 'date' => now()->toDateString()],
                    []
                )->increment('page_views');
            }
        }
    }
}

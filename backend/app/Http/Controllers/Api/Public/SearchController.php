<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\SearchLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2|max:100']);

        $q = $request->q;
        $type = $request->input('type', 'all');

        $results = [];

        if ($type === 'all' || $type === 'destination') {
            $results['destinations'] = Destination::published()
                ->where(fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%"))
                ->with('category:id,name', 'images')
                ->select('id', 'name', 'category_id', 'municipality_id', 'average_rating', 'latitude', 'longitude')
                ->limit(10)->get();
        }

        if ($type === 'all' || $type === 'accommodation') {
            $results['accommodations'] = Accommodation::approved()
                ->where(fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%"))
                ->with('images')
                ->select('id', 'name', 'type', 'classifications', 'municipality_id', 'price_range', 'average_rating')
                ->limit(10)->get();
        }

        if ($type === 'all' || $type === 'food_beverage') {
            $results['food_beverages'] = FoodBeverage::approved()
                ->where(fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%"))
                ->with('images')
                ->select('id', 'name', 'cuisine_type', 'municipality_id', 'price_range', 'average_rating')
                ->limit(10)->get();
        }

        if ($type === 'all' || $type === 'retail_product') {
            $results['retail_products'] = RetailProduct::approved()
                ->where(fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%"))
                ->with('images')
                ->select('id', 'name', 'product_type', 'municipality_id', 'price')
                ->limit(10)->get();
        }

        if ($type === 'all' || $type === 'retail_shop') {
            $results['retail_shops'] = RetailShop::approved()
                ->where(fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%"))
                ->with('images')
                ->select('id', 'name', 'municipality_id')
                ->limit(10)->get();
        }

        $totalCount = collect($results)->flatten(1)->count();

        // Log search
        SearchLog::create([
            'query' => $q,
            'filters' => ['type' => $type],
            'results_count' => $totalCount,
            'user_id' => $request->user()?->id,
            'created_at' => now(),
        ]);

        return response()->json([
            'query' => $q,
            'total_results' => $totalCount,
            'results' => $results,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\BusinessSubmission;
use App\Models\FoodBeverage;
use App\Models\RetailShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $submissions = BusinessSubmission::where('user_id', $userId)->get();
        $totalSubmissions = $submissions->count();
        $pendingCount = $submissions->where('status', 'pending')->count();
        $approvedCount = $submissions->where('status', 'approved')->count();
        $rejectedCount = $submissions->where('status', 'rejected')->count();
        $removedCount = $submissions->where('status', 'removed')->count();

        $warnings = $submissions
            ->filter(fn ($s) => $s->status === 'approved' && $s->warned_at)
            ->map(fn ($s) => $s->only(['id', 'business_name', 'warned_at', 'warning_deadline', 'warning_notes']))
            ->values();

        $accommodationViews = Accommodation::where('user_id', $userId)->approved()->sum('view_count');
        $foodBeverageViews = FoodBeverage::where('user_id', $userId)->approved()->sum('view_count');
        $retailProductViews = RetailShop::where('user_id', $userId)->approved()->sum('view_count');
        $totalViews = $accommodationViews + $foodBeverageViews + $retailProductViews;

        $recentSubmissions = BusinessSubmission::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $hasAccommodation = Accommodation::where('user_id', $userId)->exists();
        $hasFoodBeverage = FoodBeverage::where('user_id', $userId)->exists();
        $hasRetailShop = RetailShop::where('user_id', $userId)->exists();

        return response()->json([
            'has_accommodation' => $hasAccommodation,
            'has_food_beverage' => $hasFoodBeverage,
            'has_retail_shop' => $hasRetailShop,
            'stats' => [
                'total_submissions' => $totalSubmissions,
                'pending' => $pendingCount,
                'approved' => $approvedCount,
                'rejected' => $rejectedCount,
                'removed' => $removedCount,
                'total_views' => $totalViews,
            ],
            'warnings' => $warnings,
            'submissions_by_type' => [
                'accommodation' => $submissions->where('type', 'accommodation')->count(),
                'food_beverage' => $submissions->where('type', 'food_beverage')->count(),
                'retail_product' => $submissions->where('type', 'retail_product')->count(),
            ],
            'views_by_type' => [
                'accommodation' => $accommodationViews,
                'food_beverage' => $foodBeverageViews,
                'retail_product' => $retailProductViews,
            ],
            'recent_submissions' => $recentSubmissions,
        ]);
    }
}

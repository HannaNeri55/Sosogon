<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Activity;
use App\Models\ActivityLog;
use App\Models\BusinessSubmission;
use App\Models\Destination;
use App\Models\Event;
use App\Models\FoodBeverage;
use App\Models\PageView;
use App\Models\RetailProduct;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $stats = [
            'total_destinations' => Destination::published()->count(),
            'total_accommodations' => Accommodation::approved()->count(),
            'total_food_beverages' => FoodBeverage::approved()->count(),
            'total_retail_products' => RetailProduct::approved()->count(),
            'total_events' => Event::published()->count(),
            'total_activities' => Activity::count(),
            'pending_submissions' => BusinessSubmission::pending()->count(),
            'total_users' => User::count(),
            // Page views of the public site this month (Sorsogon calendar); see PageViewController.
            'monthly_views' => PageView::totalSince(now(config('app.local_timezone'))->startOfMonth()->toDateString()),
        ];

        $pendingSubmissions = BusinessSubmission::pending()
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $recentActivity = ActivityLog::with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Seven days ending today, oldest first, each a whole number (0 when nobody visited).
        $visitorTrend = PageView::daily(7);

        return response()->json([
            'stats' => $stats,
            'pending_submissions' => $pendingSubmissions,
            'recent_activity' => $recentActivity,
            'visitor_trend' => $visitorTrend,
        ]);
    }
}

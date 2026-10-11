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
use App\Models\RetailProduct;
use App\Models\User;
use App\Models\VisitorStatistic;
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
            'monthly_views' => VisitorStatistic::where('date', '>=', now()->startOfMonth()->toDateString())->sum('page_views'),
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

        $visitorTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $views = VisitorStatistic::where('date', $date)->sum('page_views');
            $visitorTrend[] = ['date' => $date, 'views' => $views];
        }

        return response()->json([
            'stats' => $stats,
            'pending_submissions' => $pendingSubmissions,
            'recent_activity' => $recentActivity,
            'visitor_trend' => $visitorTrend,
        ]);
    }
}

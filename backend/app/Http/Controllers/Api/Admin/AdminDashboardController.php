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
use App\Support\AdminScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The admin dashboard. A municipal admin's figures are their municipality's: its listings, events,
 * submissions, the accounts they work with (AdminScope::applyToUsers), and the page views of its
 * listings' pages; a provincial admin's are the whole province's, as before.
 */
class AdminDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();
        $paths = AdminScope::pagePaths($admin);
        $municipal = AdminScope::municipalityIds($admin) !== null;

        $stats = [
            'total_destinations' => AdminScope::apply(Destination::published(), $admin)->count(),
            'total_accommodations' => AdminScope::apply(Accommodation::approved(), $admin)->count(),
            'total_food_beverages' => AdminScope::apply(FoodBeverage::approved(), $admin)->count(),
            'total_retail_products' => AdminScope::applyToProducts(RetailProduct::approved(), $admin)->count(),
            'total_events' => AdminScope::apply(Event::published(), $admin)->count(),
            'total_activities' => AdminScope::applyThrough(Activity::query(), $admin, 'destination')->count(),
            'pending_submissions' => AdminScope::apply(BusinessSubmission::pending(), $admin)->count(),
            'total_users' => AdminScope::applyToUsers(User::query(), $admin)->count(),
            // Page views of the public site this month (Sorsogon calendar); see PageViewController.
            'monthly_views' => PageView::totalSince(now(config('app.local_timezone'))->startOfMonth()->toDateString(), $paths),
        ];

        $pendingSubmissions = AdminScope::apply(BusinessSubmission::pending(), $admin)
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        // A municipal admin's feed: their own actions and those of their municipality's owners.
        $recentActivity = ActivityLog::with('user:id,name')
            ->when($municipal, fn ($q) => $q->where(fn ($mine) => $mine
                ->where('user_id', $admin->id)
                ->orWhereIn('user_id', AdminScope::applyToUsers(User::whereHas('role', fn ($r) => $r->where('name', 'business_owner')), $admin)->select('users.id'))))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Seven days ending today, oldest first, each a whole number (0 when nobody visited).
        $visitorTrend = PageView::daily(7, $paths);

        return response()->json([
            'stats' => $stats,
            'pending_submissions' => $pendingSubmissions,
            'recent_activity' => $recentActivity,
            'visitor_trend' => $visitorTrend,
        ]);
    }
}

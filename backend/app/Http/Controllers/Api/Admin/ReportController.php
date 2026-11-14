<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\PageView;
use App\Models\SearchLog;
use App\Models\User;
use App\Support\AdminScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Reports & Analytics. A municipal admin's reports cover their municipality: the page views of
 * its listings' pages and its most viewed destinations. Site searches aren't tied to a
 * municipality, so they are reported to provincial admins only (municipal admins get none).
 */
class ReportController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $totalViews = PageView::daily(30, AdminScope::pagePaths($request->user()))->sum('views');

        return response()->json([
            'monthly_views' => $totalViews,
            'monthly_searches' => $this->searchCount($request->user(), 30),
        ]);
    }

    public function visitors(Request $request): JsonResponse
    {
        $days = max(1, min(366, (int) $request->input('days', 30)));

        return response()->json($this->dailyViews($days, $request->user()));
    }

    public function popular(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);

        $destinations = AdminScope::apply(Destination::published(), $request->user())
            ->orderByDesc('view_count')
            ->limit($limit)
            ->select('id', 'name', 'view_count', 'average_rating', 'total_reviews')
            ->get();

        return response()->json($destinations);
    }

    public function searches(Request $request): JsonResponse
    {
        return response()->json($this->topSearches($request->user(), 30));
    }

    /** PDF export of everything shown on the Reports & Analytics page. */
    public function export(Request $request)
    {
        $days = 30;
        $admin = $request->user();
        $visitors = $this->dailyViews($days, $admin);

        $pdf = Pdf::loadView('reports.analytics', [
            'generatedAt' => now(),
            'generatedBy' => $request->user()?->name,
            'days' => $days,
            'monthlyViews' => (int) $visitors->sum('total_views'),
            'monthlySearches' => $this->searchCount($admin, $days),
            'scopeLabel' => $admin->isMunicipalAdmin() ? ($admin->municipality?->name ?? 'Municipality') : 'Whole province',
            'visitors' => $visitors,
            'popular' => AdminScope::apply(Destination::published(), $admin)->orderByDesc('view_count')->limit(10)
                ->get(['id', 'name', 'view_count', 'average_rating', 'total_reviews']),
            'searches' => $this->topSearches($admin, $days),
        ])->setPaper('a4');

        return $pdf->download('reports-analytics-'.now()->format('Y-m-d').'.pdf');
    }

    /**
     * Page views per day (Sorsogon calendar), oldest first, with 0 for quiet days: the whole site,
     * or a municipal admin's listing pages.
     */
    private function dailyViews(int $days, User $admin)
    {
        return PageView::daily($days, AdminScope::pagePaths($admin))->map(fn ($day) => (object) ['date' => $day['date'], 'total_views' => $day['views']]);
    }

    /** Searches in the last $days days; null for a municipal admin (searches are province-wide). */
    private function searchCount(User $admin, int $days): ?int
    {
        return $admin->isProvincialAdmin() ? SearchLog::where('created_at', '>=', now()->subDays($days))->count() : null;
    }

    /** The 20 most searched terms in the last $days days; none for a municipal admin. */
    private function topSearches(User $admin, int $days)
    {
        if (! $admin->isProvincialAdmin()) {
            return collect();
        }

        return SearchLog::select('query', DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('query')->orderByDesc('count')->limit(20)->get();
    }
}

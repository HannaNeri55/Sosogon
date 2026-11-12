<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\PageView;
use App\Models\SearchLog;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function overview(): JsonResponse
    {
        $totalViews = PageView::daily(30)->sum('views');
        $totalSearches = SearchLog::where('created_at', '>=', now()->subDays(30))->count();

        return response()->json([
            'monthly_views' => $totalViews,
            'monthly_searches' => $totalSearches,
        ]);
    }

    public function visitors(Request $request): JsonResponse
    {
        $days = max(1, min(366, (int) $request->input('days', 30)));

        return response()->json($this->dailyViews($days));
    }

    public function popular(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);

        $destinations = Destination::published()
            ->orderByDesc('view_count')
            ->limit($limit)
            ->select('id', 'name', 'view_count', 'average_rating', 'total_reviews')
            ->get();

        return response()->json($destinations);
    }

    public function searches(Request $request): JsonResponse
    {
        $topSearches = SearchLog::select('query', DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('query')
            ->orderByDesc('count')
            ->limit(20)
            ->get();

        return response()->json($topSearches);
    }

    /** PDF export of everything shown on the Reports & Analytics page. */
    public function export(Request $request)
    {
        $days = 30;
        $visitors = $this->dailyViews($days);

        $pdf = Pdf::loadView('reports.analytics', [
            'generatedAt' => now(),
            'generatedBy' => $request->user()?->name,
            'days' => $days,
            'monthlyViews' => (int) $visitors->sum('total_views'),
            'monthlySearches' => SearchLog::where('created_at', '>=', now()->subDays($days))->count(),
            'visitors' => $visitors,
            'popular' => Destination::published()->orderByDesc('view_count')->limit(10)
                ->get(['id', 'name', 'view_count', 'average_rating', 'total_reviews']),
            'searches' => SearchLog::select('query', DB::raw('COUNT(*) as count'))
                ->where('created_at', '>=', now()->subDays($days))
                ->groupBy('query')->orderByDesc('count')->limit(20)->get(),
        ])->setPaper('a4');

        return $pdf->download('reports-analytics-'.now()->format('Y-m-d').'.pdf');
    }

    /** Page views of the public site per day (Sorsogon calendar), oldest first, with 0 for quiet days. */
    private function dailyViews(int $days)
    {
        return PageView::daily($days)->map(fn ($day) => (object) ['date' => $day['date'], 'total_views' => $day['views']]);
    }
}

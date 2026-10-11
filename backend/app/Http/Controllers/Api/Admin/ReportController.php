<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\SearchLog;
use App\Models\VisitorStatistic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class ReportController extends Controller
{
    public function overview(): JsonResponse
    {
        $totalViews = VisitorStatistic::where('date', '>=', now()->subDays(30)->toDateString())->sum('page_views');
        $totalSearches = SearchLog::where('created_at', '>=', now()->subDays(30))->count();

        return response()->json([
            'monthly_views' => $totalViews,
            'monthly_searches' => $totalSearches,
        ]);
    }

    public function visitors(Request $request): JsonResponse
    {
        $days = $request->input('days', 30);
        $startDate = now()->subDays($days)->toDateString();

        $data = VisitorStatistic::where('date', '>=', $startDate)
            ->select(DB::raw('date, SUM(page_views) as total_views'))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json($data);
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
        $visitors = VisitorStatistic::where('date', '>=', now()->subDays($days)->toDateString())
            ->select(DB::raw('date, SUM(page_views) as total_views'))
            ->groupBy('date')->orderBy('date')->get();

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
}

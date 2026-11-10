<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Event::published()->with('municipality');

        if ($request->boolean('upcoming')) {
            // Soonest first: ongoing events, then the next ones by date. Past events are excluded.
            $query->upcoming()->orderBy('start_date')->orderBy('id');
        } else {
            if ($request->boolean('featured_first')) {
                $query->orderByDesc('is_featured');
            }
            $query->orderBy('start_date', 'desc');
        }

        $events = $query->paginate($this->perPage($request, 10, 50));

        return response()->json($events);
    }

    public function calendar(Request $request): JsonResponse
    {
        $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|between:2000,2100',
        ]);

        $month = (int) $request->input('month');
        $year = (int) $request->input('year');

        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = date('Y-m-t', strtotime($monthStart));

        $events = Event::published()
            ->with('municipality')
            ->where('start_date', '<=', $monthEnd)
            ->whereRaw('COALESCE(end_date, start_date) >= ?', [$monthStart])
            ->orderBy('start_date')
            ->get();

        return response()->json($events);
    }

    public function show(int $id): JsonResponse
    {
        $event = Event::published()->with('municipality')->findOrFail($id);

        return response()->json($event);
    }
}

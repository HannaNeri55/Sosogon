<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Concerns\FindsNearbyPlaces;
use App\Http\Controllers\Controller;
use App\Models\Destination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DestinationController extends Controller
{
    use FindsNearbyPlaces;

    public function index(Request $request): JsonResponse
    {
        $query = Destination::published()
            ->with(['category', 'municipality', 'barangay', 'images']);

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->filled('municipality_id')) {
            $query->where('municipality_id', $request->municipality_id);
        }

        if ($request->filled('barangay_id')) {
            $query->where('barangay_id', $request->barangay_id);
        }

        if ($request->filled('q')) {
            $search = $request->q;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $sortBy = $request->input('sort', 'name');
        $sortDir = $request->input('dir', 'asc');
        $allowedSorts = ['name', 'average_rating', 'view_count', 'created_at'];

        if ($sortBy === 'featured') {
            // Admin-chosen featured places first, best rated first among them.
            $query->orderByDesc('is_featured')->orderByDesc('average_rating')->orderByDesc('created_at');
        } elseif (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir === 'desc' ? 'desc' : 'asc');
        }

        $perPage = $this->perPage($request, 12, 50);
        $destinations = $query->paginate($perPage);

        return response()->json($destinations);
    }

    public function show(int $id): JsonResponse
    {
        $destination = Destination::published()

            ->with([
                'category',
                'municipality',
                'barangay',
                'images',
                // Newest first, each with the reviewer's public name and any reply.
                'ratings' => fn ($query) => $query
                    ->visible()
                    ->with(['user:id,name', 'reply'])
                    ->latest()
                    ->orderByDesc('id'),
                'activities',
                'fees',
                'rates',
                'amenities',
                // Typical hours / fee for what the listing doesn't state (shown as approximate).
                'estimates',
            ])

            ->findOrFail($id);

        $destination->increment('view_count');

        // Ranked purely by straight-line distance; a destination without usable
        // coordinates gets empty lists rather than a same-municipality guess.
        return response()->json(['destination' => $destination] + $this->nearbyPlaces($destination, precision: 3));
    }
}

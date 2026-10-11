<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Event::with('municipality');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min((int) $request->input('per_page', 100), 200);

        return response()->json($query->orderByDesc('start_date')->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'location' => 'nullable|string|max:500',
            'municipality_id' => 'nullable|exists:municipalities,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'is_featured' => 'boolean',
            'status' => 'required|in:draft,published',
        ]);

        $validated['created_by'] = $request->user()->id;
        $event = Event::create($validated);

        return response()->json(['message' => 'Event created.', 'event' => $event], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Event::with('municipality')->findOrFail($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'location' => 'nullable|string|max:500',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date',
            'is_featured' => 'boolean',
            'status' => 'sometimes|in:draft,published,archived',
        ]);
        $event->update($validated);

        return response()->json(['message' => 'Event updated.', 'event' => $event]);
    }

    public function destroy(int $id): JsonResponse
    {
        Event::findOrFail($id)->delete();

        return response()->json(['message' => 'Event deleted.']);
    }
}

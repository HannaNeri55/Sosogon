<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Activity::with('destination:id,name');
        if ($request->filled('destination_id')) {
            $query->where('destination_id', $request->destination_id);
        }
        if ($request->filled('q')) {
            $query->where('name', 'like', "%{$request->q}%");
        }

        $perPage = min((int) $request->input('per_page', 100), 200);

        return response()->json($query->orderByDesc('created_at')->paginate($perPage));
    }

    public function store(Request $request, ImageOptimizationService $images): JsonResponse
    {
        $validated = $request->validate([
            'destination_id' => 'required|exists:destinations,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'required|in:indoor,outdoor',
            'best_time_to_visit' => 'nullable|string|max:255',
            'what_to_wear' => 'nullable|string|max:255',
            'things_to_do' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        $item = Activity::create($validated);

        if ($request->hasFile('image')) {
            $paths = $images->store($request->file('image'), "activities/{$item->id}");
            $item->update([
                'image_path' => $paths['image_path'],
                'thumbnail_path' => $paths['thumbnail_path'],
            ]);
        }

        return response()->json($item->load('destination:id,name'), 201);
    }

    public function update(Request $request, int $id, ImageOptimizationService $images): JsonResponse
    {
        $item = Activity::findOrFail($id);
        $validated = $request->validate([
            'destination_id' => 'sometimes|exists:destinations,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'type' => 'sometimes|in:indoor,outdoor',
            'best_time_to_visit' => 'nullable|string|max:255',
            'what_to_wear' => 'nullable|string|max:255',
            'things_to_do' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);
        unset($validated['image']);
        $item->update($validated);

        if ($request->hasFile('image')) {
            $images->delete($item->image_path, $item->thumbnail_path);
            $paths = $images->store($request->file('image'), "activities/{$item->id}");
            $item->update([
                'image_path' => $paths['image_path'],
                'thumbnail_path' => $paths['thumbnail_path'],
            ]);
        }

        return response()->json($item->load('destination:id,name'));
    }

    public function destroy(int $id, ImageOptimizationService $images): JsonResponse
    {
        $item = Activity::findOrFail($id);
        $images->delete($item->image_path, $item->thumbnail_path);
        $item->delete();

        return response()->json(['message' => 'Activity deleted']);
    }
}

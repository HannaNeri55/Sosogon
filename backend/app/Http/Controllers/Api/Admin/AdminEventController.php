<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminEventController extends Controller
{
    /** Cover image rules: the same formats and size limit as hero slides. */
    private const IMAGE_RULE = 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120';

    public function index(Request $request): JsonResponse
    {
        $query = Event::with('municipality');
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = $this->perPage($request, 100, 200);

        return response()->json($query->orderByDesc('start_date')->paginate($perPage));
    }

    public function store(Request $request, ImageOptimizationService $images): JsonResponse
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
            'image' => self::IMAGE_RULE,
        ]);
        unset($validated['image']);

        if ($request->hasFile('image')) {
            $validated['image_path'] = $images->store($request->file('image'), 'events')['image_path'];
        }

        $validated['created_by'] = $request->user()->id;
        $event = Event::create($validated);

        return response()->json(['message' => 'Event created.', 'event' => $event], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Event::with('municipality')->findOrFail($id));
    }

    public function update(Request $request, int $id, ImageOptimizationService $images): JsonResponse
    {
        $event = Event::findOrFail($id);
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'location' => 'nullable|string|max:500',
            'municipality_id' => 'nullable|exists:municipalities,id',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date',
            'is_featured' => 'boolean',
            'status' => 'sometimes|in:draft,published,archived',
            'image' => self::IMAGE_RULE,
            'remove_image' => 'boolean',
        ]);
        unset($validated['image'], $validated['remove_image']);

        // A new upload replaces the old cover; "remove image" clears it. Old files are deleted either way.
        if ($request->hasFile('image') || $request->boolean('remove_image')) {
            $this->deleteImage($images, $event->image_path);
            $validated['image_path'] = $request->hasFile('image')
                ? $images->store($request->file('image'), 'events')['image_path']
                : null;
        }

        $event->update($validated);

        return response()->json(['message' => 'Event updated.', 'event' => $event->fresh('municipality')]);
    }

    public function destroy(int $id, ImageOptimizationService $images): JsonResponse
    {
        $event = Event::findOrFail($id);
        $this->deleteImage($images, $event->image_path);
        $event->delete();

        return response()->json(['message' => 'Event deleted.']);
    }

    /** Delete a cover image and the thumbnail the image service saved beside it. */
    private function deleteImage(ImageOptimizationService $images, ?string $path): void
    {
        if (! $path) {
            return;
        }
        $images->delete($path, dirname($path).'/thumbnails/'.basename($path));
    }
}

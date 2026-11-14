<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\ImageOptimizationService;
use App\Support\AdminScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminEventController extends Controller
{
    /** Cover image rules: the same formats and size limit as hero slides. */
    private const IMAGE_RULE = 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120';

    /**
     * A municipal admin sees their municipality's events and, read-only, the province-wide ones
     * (no municipality); each row says whether this admin can change it (can_manage).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Event::with('municipality');
        AdminScope::applyWithProvinceWide($query, $request->user());
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = $this->perPage($request, 100, 200);
        $page = $query->orderByDesc('start_date')->paginate($perPage);
        AdminScope::markManageable($page->getCollection(), $request->user());

        return response()->json($page);
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
        // A municipal admin's events are for their municipality; province-wide ones are provincial.
        $validated['municipality_id'] = AdminScope::defaultMunicipality($request->user(), $validated['municipality_id'] ?? null);
        AdminScope::authorize($request->user(), $validated['municipality_id']);
        // Featured events are shown on the home page for the whole province.
        AdminScope::guardProvincialFields($request->user(), $validated, null, ['is_featured']);

        if ($request->hasFile('image')) {
            $validated['image_path'] = $images->store($request->file('image'), 'events')['image_path'];
        }

        $validated['created_by'] = $request->user()->id;
        $event = Event::create($validated);

        return response()->json(['message' => 'Event created.', 'event' => $event], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $event = Event::with('municipality')->findOrFail($id);
        // Province-wide events can be read by every admin.
        if ($event->municipality_id !== null) {
            AdminScope::authorizeRecord($request->user(), $event);
        }
        AdminScope::markManageable([$event], $request->user());

        return response()->json($event);
    }

    public function update(Request $request, int $id, ImageOptimizationService $images): JsonResponse
    {
        $event = Event::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $event);
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
        // Moving an event to another municipality, or making it province-wide, needs authority there too.
        AdminScope::guardProvincialFields($request->user(), $validated, $event, ['is_featured']);
        if (array_key_exists('municipality_id', $validated)) {
            AdminScope::authorize($request->user(), $validated['municipality_id'] === null ? null : (int) $validated['municipality_id']);
        }

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

    public function destroy(Request $request, int $id, ImageOptimizationService $images): JsonResponse
    {
        $event = Event::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $event);
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

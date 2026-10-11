<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Destination;
use App\Models\DestinationImage;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminDestinationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Destination::with(['category', 'municipality', 'barangay', 'images']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
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
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|exists:categories,id',
            'municipality_id' => 'required|exists:municipalities,id',
            'barangay_id' => 'nullable|exists:barangays,id',
            'address' => 'required|string|max:500',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'operating_hours' => 'nullable|string|max:255',
            'entrance_fee' => 'nullable|numeric|min:0',
            'contact_number' => 'nullable|string|max:20',
            'website' => 'nullable|string|max:500',
            'status' => 'required|in:draft,published',
            'is_featured' => 'boolean',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        $destination = Destination::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        // Handle images
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $paths = $images->store($image, "destinations/{$destination->id}");
                DestinationImage::create([
                    'destination_id' => $destination->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                ]);
            }
        }

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'created_destination',
            'description' => "Created destination: {$destination->name}",
            'model_type' => Destination::class,
            'model_id' => $destination->id,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Destination created.',
            'destination' => $destination->load('images'),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(
            Destination::with(['category', 'municipality', 'barangay', 'images'])->findOrFail($id)
        );
    }

    public function update(Request $request, int $id, ImageOptimizationService $images): JsonResponse
    {
        $destination = Destination::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'category_id' => 'sometimes|exists:categories,id',
            'municipality_id' => 'sometimes|exists:municipalities,id',
            'barangay_id' => 'sometimes|nullable|exists:barangays,id',
            'address' => 'sometimes|string|max:500',
            'latitude' => 'sometimes|numeric|between:-90,90',
            'longitude' => 'sometimes|numeric|between:-180,180',
            'operating_hours' => 'nullable|string|max:255',
            'entrance_fee' => 'nullable|numeric|min:0',
            'contact_number' => 'nullable|string|max:20',
            'website' => 'nullable|string|max:500',
            'status' => 'sometimes|in:draft,published,archived',
            'is_featured' => 'boolean',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        $destination->update($validated);

        // Handle new images
        if ($request->hasFile('images')) {
            $maxOrder = $destination->images()->max('sort_order') ?? -1;
            foreach ($request->file('images') as $index => $image) {
                $paths = $images->store($image, "destinations/{$destination->id}");
                DestinationImage::create([
                    'destination_id' => $destination->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $maxOrder + $index + 1,
                    'is_primary' => false,
                ]);
            }
        }

        return response()->json(['message' => 'Destination updated.', 'destination' => $destination->fresh('images')]);
    }

    public function destroyImage(int $id, int $imageId, ImageOptimizationService $images): JsonResponse
    {
        $destination = Destination::findOrFail($id);

        /** @var DestinationImage $image */
        $image = $destination->images()->where('id', $imageId)->firstOrFail();

        $images->delete($image->image_path, $image->thumbnail_path);
        $image->delete();

        return response()->json(['message' => 'Image removed.']);
    }

    public function destroy(int $id, ImageOptimizationService $images): JsonResponse
    {
        $destination = Destination::findOrFail($id);

        // Delete images (and their thumbnails) from storage
        foreach ($destination->images as $img) {
            $images->delete($img->image_path, $img->thumbnail_path);
        }

        $destination->delete();

        return response()->json(['message' => 'Destination deleted.']);
    }
}

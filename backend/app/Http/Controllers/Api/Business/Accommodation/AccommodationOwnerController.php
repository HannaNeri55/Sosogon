<?php

namespace App\Http\Controllers\Api\Business\Accommodation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpdateAccommodationRequest;
use App\Models\Accommodation;
use App\Models\AccommodationImage;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccommodationOwnerController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $accommodation = Accommodation::where('user_id', $request->user()->id)
            ->with(['municipality', 'barangay', 'images', 'rooms.images'])
            ->firstOrFail();

        return response()->json($accommodation);
    }

    public function update(UpdateAccommodationRequest $request, ImageOptimizationService $images): JsonResponse
    {
        $accommodation = Accommodation::where('user_id', $request->user()->id)->firstOrFail();

        $validated = $request->validated();

        $needsReReview = $accommodation->status !== 'pending';

        $accommodation->update([
            'name' => $validated['name'] ?? $accommodation->name,
            'description' => $validated['description'] ?? $accommodation->description,
            'website' => $validated['website'] ?? $accommodation->website,
        ]);

        if ($needsReReview) {
            $accommodation->update([
                'status' => 'pending',
                'approved_at' => null,
                'approved_by' => null,
            ]);
        }

        if ($request->hasFile('images')) {
            $maxOrder = $accommodation->images()->max('sort_order') ?? -1;
            foreach ($request->file('images') as $index => $image) {
                $paths = $images->store($image, "accommodations/{$accommodation->id}");
                AccommodationImage::create([
                    'accommodation_id' => $accommodation->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $maxOrder + $index + 1,
                    'is_primary' => false,
                ]);
            }
        }

        return response()->json([
            'message' => $needsReReview
                ? 'Changes saved. Since your property was already reviewed, the Tourism Office needs to approve the update before it goes live.'
                : 'Property updated.',
            'accommodation' => $accommodation->fresh(['municipality', 'barangay', 'images', 'rooms.images']),
        ]);
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $accommodation = Accommodation::where('user_id', $request->user()->id)->firstOrFail();

        $validated = $request->validate([
            'address' => 'required|string|max:500',
            'municipality_id' => 'required|exists:municipalities,id',
        ]);

        $accommodation->update($validated);

        return response()->json([
            'message' => 'Location updated.',
            'accommodation' => $accommodation->fresh(['municipality', 'barangay', 'images', 'rooms.images']),
        ]);
    }

    public function destroyImage(Request $request, int $imageId, ImageOptimizationService $images): JsonResponse
    {
        $accommodation = Accommodation::where('user_id', $request->user()->id)->firstOrFail();

        /** @var AccommodationImage $image */
        $image = $accommodation->images()->where('id', $imageId)->firstOrFail();

        $images->delete($image->image_path, $image->thumbnail_path);
        $image->delete();

        return response()->json(['message' => 'Image removed.']);
    }
}

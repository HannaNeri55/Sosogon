<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\AccommodationImage;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAccommodationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Accommodation::with(['municipality', 'barangay', 'images', 'user:id,name']);

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
            'type' => 'required|string|max:100',
            'municipality_id' => 'required|exists:municipalities,id',
            'barangay_id' => 'nullable|exists:barangays,id',
            'address' => 'required|string|max:500',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'price_range' => 'required|in:budget,moderate,luxury',
            'contact_number' => 'nullable|string|max:20',
            'website' => 'nullable|string|max:500',
            'operating_hours' => 'nullable|string|max:255',
            'status' => 'required|in:pending,approved',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        $validated['approved_at'] = $validated['status'] === 'approved' ? now() : null;
        $validated['approved_by'] = $validated['status'] === 'approved' ? $request->user()->id : null;

        $accommodation = Accommodation::create($validated);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $paths = $images->store($image, "accommodations/{$accommodation->id}");
                AccommodationImage::create([
                    'accommodation_id' => $accommodation->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                ]);
            }
        }

        return response()->json(['message' => 'Accommodation created.', 'accommodation' => $accommodation->load('images')], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Accommodation::with(['municipality', 'barangay', 'images', 'user'])->findOrFail($id));
    }

    public function update(Request $request, int $id, ImageOptimizationService $images): JsonResponse
    {
        $accommodation = Accommodation::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'type' => 'sometimes|string|max:100',
            'address' => 'sometimes|string|max:500',
            'price_range' => 'sometimes|in:budget,moderate,luxury',
            'status' => 'sometimes|in:pending,approved,rejected,archived',
            'contact_number' => 'nullable|string|max:20',
            'website' => 'nullable|string|max:500',
            'operating_hours' => 'nullable|string|max:255',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'approved' && $accommodation->status !== 'approved') {
            $validated['approved_at'] = now();
            $validated['approved_by'] = $request->user()->id;
        }

        $accommodation->update($validated);

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

        return response()->json(['message' => 'Accommodation updated.', 'accommodation' => $accommodation->fresh('images')]);
    }

    public function destroy(int $id, ImageOptimizationService $images): JsonResponse
    {
        $accommodation = Accommodation::findOrFail($id);

        foreach ($accommodation->images as $img) {
            $images->delete($img->image_path, $img->thumbnail_path);
        }

        $accommodation->delete();

        return response()->json(['message' => 'Accommodation deleted.']);
    }
}

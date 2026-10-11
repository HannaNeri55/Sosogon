<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FoodBeverage;
use App\Models\FoodBeverageImage;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFoodBeverageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FoodBeverage::with(['municipality', 'barangay', 'images', 'user:id,name']);
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
            'cuisine_type' => 'nullable|string|max:100',
            'municipality_id' => 'required|exists:municipalities,id',
            'barangay_id' => 'nullable|exists:barangays,id',
            'address' => 'required|string|max:500',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'price_range' => 'required|in:budget,moderate,luxury',
            'contact_number' => 'nullable|string',
            'website' => 'nullable|string|max:500',
            'operating_hours' => 'nullable|string',
            'status' => 'required|in:pending,approved',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if ($validated['status'] === 'approved') {
            $validated['approved_at'] = now();
            $validated['approved_by'] = $request->user()->id;
        }

        $item = FoodBeverage::create($validated);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $paths = $images->store($image, "food-beverages/{$item->id}");
                FoodBeverageImage::create([
                    'food_beverage_id' => $item->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                ]);
            }
        }

        return response()->json(['message' => 'Created.', 'food_beverage' => $item->load('images')], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(FoodBeverage::with(['municipality', 'barangay', 'images'])->findOrFail($id));
    }

    public function update(Request $request, int $id, ImageOptimizationService $images): JsonResponse
    {
        $item = FoodBeverage::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'cuisine_type' => 'nullable|string',
            'price_range' => 'sometimes|in:budget,moderate,luxury',
            'status' => 'sometimes|in:pending,approved,rejected,archived',
            'contact_number' => 'nullable|string',
            'website' => 'nullable|string|max:500',
            'operating_hours' => 'nullable|string',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'approved' && $item->status !== 'approved') {
            $validated['approved_at'] = now();
            $validated['approved_by'] = $request->user()->id;
        }

        $item->update($validated);

        if ($request->hasFile('images')) {
            $maxOrder = $item->images()->max('sort_order') ?? -1;
            foreach ($request->file('images') as $index => $image) {
                $paths = $images->store($image, "food-beverages/{$item->id}");
                FoodBeverageImage::create([
                    'food_beverage_id' => $item->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $maxOrder + $index + 1,
                    'is_primary' => false,
                ]);
            }
        }

        return response()->json(['message' => 'Updated.', 'food_beverage' => $item->fresh('images')]);
    }

    public function destroy(int $id, ImageOptimizationService $images): JsonResponse
    {
        $item = FoodBeverage::findOrFail($id);

        foreach ($item->images as $img) {
            $images->delete($img->image_path, $img->thumbnail_path);
        }

        $item->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}

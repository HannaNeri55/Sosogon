<?php

namespace App\Http\Controllers\Api\Business\FoodBeverage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpdateFoodBeverageRequest;
use App\Models\FoodBeverage;
use App\Models\FoodBeverageImage;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FoodBeverageOwnerController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $foodBeverage = FoodBeverage::where('user_id', $request->user()->id)
            ->with(['municipality', 'barangay', 'images', 'menuItems'])
            ->firstOrFail();

        return response()->json($foodBeverage);
    }

    public function update(UpdateFoodBeverageRequest $request, ImageOptimizationService $images): JsonResponse
    {
        $foodBeverage = FoodBeverage::where('user_id', $request->user()->id)->firstOrFail();

        $validated = $request->validated();

        $needsReReview = $foodBeverage->status !== 'pending';

        $foodBeverage->update([
            'name' => $validated['name'] ?? $foodBeverage->name,
            'description' => $validated['description'] ?? $foodBeverage->description,
            'website' => $validated['website'] ?? $foodBeverage->website,
        ]);

        if ($needsReReview) {
            $foodBeverage->update([
                'status' => 'pending',
                'approved_at' => null,
                'approved_by' => null,
            ]);
        }

        if ($request->hasFile('images')) {
            $maxOrder = $foodBeverage->images()->max('sort_order') ?? -1;
            foreach ($request->file('images') as $index => $image) {
                $paths = $images->store($image, "food-beverages/{$foodBeverage->id}");
                FoodBeverageImage::create([
                    'food_beverage_id' => $foodBeverage->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $maxOrder + $index + 1,
                    'is_primary' => false,
                ]);
            }
        }

        return response()->json([
            'message' => $needsReReview
                ? 'Changes saved. Since your establishment was already reviewed, the Tourism Office needs to approve the update before it goes live.'
                : 'Establishment updated.',
            'food_beverage' => $foodBeverage->fresh(['municipality', 'barangay', 'images', 'menuItems']),
        ]);
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $foodBeverage = FoodBeverage::where('user_id', $request->user()->id)->firstOrFail();

        $validated = $request->validate([
            'address' => 'required|string|max:500',
            'municipality_id' => 'required|exists:municipalities,id',
        ]);

        $foodBeverage->update($validated);

        return response()->json([
            'message' => 'Location updated.',
            'food_beverage' => $foodBeverage->fresh(['municipality', 'barangay', 'images', 'menuItems']),
        ]);
    }

    public function destroyImage(Request $request, int $imageId, ImageOptimizationService $images): JsonResponse
    {
        $foodBeverage = FoodBeverage::where('user_id', $request->user()->id)->firstOrFail();

        /** @var FoodBeverageImage $image */
        $image = $foodBeverage->images()->where('id', $imageId)->firstOrFail();

        $images->delete($image->image_path, $image->thumbnail_path);
        $image->delete();

        return response()->json(['message' => 'Image removed.']);
    }
}

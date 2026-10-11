<?php

namespace App\Http\Controllers\Api\Business\RetailShop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpdateRetailShopRequest;
use App\Models\RetailShop;
use App\Models\RetailShopImage;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetailShopOwnerController extends Controller
{
    public const MAX_GALLERY_IMAGES = 10;

    public function show(Request $request): JsonResponse
    {
        $shop = RetailShop::where('user_id', $request->user()->id)
            ->with(['municipality', 'barangay', 'images', 'products.images'])
            ->firstOrFail();

        return response()->json($shop);
    }

    public function update(UpdateRetailShopRequest $request, ImageOptimizationService $images): JsonResponse
    {
        $shop = RetailShop::where('user_id', $request->user()->id)->firstOrFail();

        $validated = $request->validated();

        if ($request->hasFile('images')) {
            $existingCount = $shop->images()->count();
            $incomingCount = count($request->file('images'));

            if ($existingCount + $incomingCount > self::MAX_GALLERY_IMAGES) {
                return response()->json([
                    'message' => 'Your storefront gallery is limited to 10 photos. Remove one before adding another.',
                ], 422);
            }
        }

        $needsReReview = $shop->status !== 'pending';

        $shop->update([
            'name' => $validated['name'] ?? $shop->name,
            'description' => $validated['description'] ?? $shop->description,
        ]);

        if ($needsReReview) {
            $shop->update([
                'status' => 'pending',
                'admin_notes' => null,
                'approved_at' => null,
                'approved_by' => null,
            ]);
        }

        if ($request->hasFile('images')) {
            $maxOrder = $shop->images()->max('sort_order') ?? -1;
            foreach ($request->file('images') as $index => $image) {
                $paths = $images->store($image, "retail-shops/{$shop->id}");
                RetailShopImage::create([
                    'retail_shop_id' => $shop->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $maxOrder + $index + 1,
                    'is_primary' => false,
                ]);
            }
        }

        return response()->json([
            'message' => $needsReReview
                ? 'Changes saved. Since your shop was already reviewed, the Tourism Office needs to approve the update before it goes live.'
                : 'Shop updated.',
            'shop' => $shop->fresh(['municipality', 'barangay', 'images', 'products.images']),
        ]);
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $shop = RetailShop::where('user_id', $request->user()->id)->firstOrFail();

        $validated = $request->validate([
            'address' => 'required|string|max:500',
            'municipality_id' => 'required|exists:municipalities,id',
        ]);

        $shop->update($validated);

        return response()->json([
            'message' => 'Location updated.',
            'shop' => $shop->fresh(['municipality', 'barangay', 'images', 'products.images']),
        ]);
    }

    public function destroyImage(Request $request, int $imageId, ImageOptimizationService $images): JsonResponse
    {
        $shop = RetailShop::where('user_id', $request->user()->id)->firstOrFail();

        /** @var RetailShopImage $image */
        $image = $shop->images()->where('id', $imageId)->firstOrFail();

        $images->delete($image->image_path, $image->thumbnail_path);
        $image->delete();

        return response()->json(['message' => 'Image removed.']);
    }
}

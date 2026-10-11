<?php

namespace App\Http\Controllers\Api\Business\RetailShop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StoreRetailProductRequest;
use App\Http\Requests\Business\UpdateRetailProductRequest;
use App\Models\RetailProduct;
use App\Models\RetailProductImage;
use App\Models\RetailShop;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RetailShopProductController extends Controller
{
    public const MAX_PRODUCTS = 20;

    public const CAPACITY_MESSAGE = 'Maximum capacity reached: You have registered the limit of 20 products. Delete an existing product to feature a new one.';

    private function ownedShop(Request $request): RetailShop
    {
        return RetailShop::where('user_id', $request->user()->id)->firstOrFail();
    }

    private function ownedProduct(Request $request, int $productId): RetailProduct
    {
        return $this->ownedShop($request)->products()->where('id', $productId)->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $shop = $this->ownedShop($request);

        return response()->json($shop->products()->with('images')->get());
    }

    public function store(StoreRetailProductRequest $request, ImageOptimizationService $images): JsonResponse
    {
        $shop = $this->ownedShop($request);

        if ($shop->products()->count() >= self::MAX_PRODUCTS) {
            return response()->json(['message' => self::CAPACITY_MESSAGE], 422);
        }

        $validated = $request->validated();

        $trusted = $shop->status === 'approved';

        $product = RetailProduct::create([
            'user_id' => $shop->user_id,
            'retail_shop_id' => $shop->id,
            'name' => $validated['name'],
            'description' => $validated['description'],
            'product_type' => $validated['product_type'],
            'price' => $validated['price'],
            'website' => $validated['website'] ?? null,
            'municipality_id' => $shop->municipality_id,
            'barangay_id' => $shop->barangay_id,
            'address' => $shop->address,
            'latitude' => $shop->latitude,
            'longitude' => $shop->longitude,
            'contact_number' => $shop->contact_number,
            'status' => $trusted ? 'approved' : 'pending',
            'approved_at' => $trusted ? now() : null,
        ]);

        if ($request->hasFile('image')) {
            $this->replaceImage($product, $request->file('image'), $images);
        }

        return response()->json([
            'message' => $trusted ? 'Product added and is now live for tourists.' : 'Product added. It will be visible to tourists once approved.',
            'product' => $product->fresh('images'),
        ], 201);
    }

    public function update(UpdateRetailProductRequest $request, int $product, ImageOptimizationService $images): JsonResponse
    {
        $product = $this->ownedProduct($request, $product);

        $validated = $request->validated();

        $trusted = $product->shop->status === 'approved';
        $needsReReview = ! $trusted && $product->status !== 'pending';

        $product->update([
            'name' => $validated['name'] ?? $product->name,
            'description' => $validated['description'] ?? $product->description,
            'price' => $validated['price'] ?? $product->price,
            'product_type' => $validated['product_type'] ?? $product->product_type,
            'website' => $validated['website'] ?? $product->website,
        ]);

        if ($trusted) {
            if ($product->status !== 'approved') {
                $product->update([
                    'status' => 'approved',
                    'admin_notes' => null,
                    'approved_at' => now(),
                    // Auto-approved via the parent shop's trust, not by a human
                    // review of this specific edit — attribute it to whichever
                    // admin approved the shop, rather than losing the trail.
                    'approved_by' => $product->shop->approved_by,
                ]);
            }
        } elseif ($needsReReview) {
            $product->update([
                'status' => 'pending',
                'admin_notes' => null,
                'approved_at' => null,
                'approved_by' => null,
            ]);
        }

        if ($request->hasFile('image')) {
            $this->replaceImage($product, $request->file('image'), $images);
        }

        return response()->json([
            'message' => $needsReReview
                ? 'Changes saved. This product needs Tourism Office approval again before it is visible to tourists.'
                : 'Product updated.',
            'product' => $product->fresh('images'),
        ]);
    }

    public function destroy(Request $request, int $product, ImageOptimizationService $images): JsonResponse
    {
        $product = $this->ownedProduct($request, $product);

        foreach ($product->images as $image) {
            $images->delete($image->image_path, $image->thumbnail_path);
        }

        $product->delete();

        return response()->json(['message' => 'Product deleted.']);
    }

    /**
     * Each product carries exactly one image (no gallery, per spec) even
     * though retail_product_images supports many — replace rather than append.
     */
    private function replaceImage(RetailProduct $product, $file, ImageOptimizationService $images): void
    {
        foreach ($product->images as $old) {
            $images->delete($old->image_path, $old->thumbnail_path);
            $old->delete();
        }

        $paths = $images->store($file, "retail-products/{$product->id}");

        RetailProductImage::create([
            'retail_product_id' => $product->id,
            'image_path' => $paths['image_path'],
            'thumbnail_path' => $paths['thumbnail_path'],
            'is_primary' => true,
            'sort_order' => 0,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\RetailProduct;
use App\Models\RetailProductImage;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRetailProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RetailProduct::with(['municipality', 'barangay', 'images', 'user:id,name', 'shop:id,name,user_id', 'shop.user:id,name']);
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('q')) {
            $query->where('name', 'like', "%{$request->q}%");
        }

        $perPage = min((int) $request->input('per_page', 100), 200);

        return response()->json($query->orderByDesc('created_at')->paginate($perPage));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $item = RetailProduct::pending()->findOrFail($id);

        $item->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'admin_notes' => $request->input('notes'),
        ]);

        return response()->json(['message' => 'Product approved.']);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['notes' => 'required|string|max:1000']);

        $item = RetailProduct::pending()->findOrFail($id);

        $item->update([
            'status' => 'rejected',
            'admin_notes' => $request->notes,
        ]);

        return response()->json(['message' => 'Product rejected.']);
    }

    public function store(Request $request, ImageOptimizationService $images): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'product_type' => 'required|string|max:100',
            'municipality_id' => 'required|exists:municipalities,id',
            'barangay_id' => 'nullable|exists:barangays,id',
            'address' => 'nullable|string|max:500',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'price' => 'nullable|numeric|min:0',
            'contact_number' => 'nullable|string',
            'website' => 'nullable|string|max:500',
            'status' => 'required|in:pending,approved',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if ($validated['status'] === 'approved') {
            $validated['approved_at'] = now();
            $validated['approved_by'] = $request->user()->id;
        }

        $item = RetailProduct::create($validated);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $paths = $images->store($image, "retail-products/{$item->id}");
                RetailProductImage::create([
                    'retail_product_id' => $item->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                ]);
            }
        }

        return response()->json(['message' => 'Created.', 'retail_product' => $item->load('images')], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(RetailProduct::with(['municipality', 'barangay', 'images'])->findOrFail($id));
    }

    public function update(Request $request, int $id, ImageOptimizationService $images): JsonResponse
    {
        $item = RetailProduct::findOrFail($id);
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'product_type' => 'sometimes|string',
            'municipality_id' => 'sometimes|exists:municipalities,id',
            'barangay_id' => 'sometimes|nullable|exists:barangays,id',
            'address' => 'nullable|string|max:500',
            'price' => 'nullable|numeric|min:0',
            'contact_number' => 'nullable|string',
            'website' => 'nullable|string|max:500',
            'status' => 'sometimes|in:pending,approved,rejected,archived',
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
                $paths = $images->store($image, "retail-products/{$item->id}");
                RetailProductImage::create([
                    'retail_product_id' => $item->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $maxOrder + $index + 1,
                    'is_primary' => false,
                ]);
            }
        }

        return response()->json(['message' => 'Updated.', 'retail_product' => $item->fresh('images')]);
    }

    public function destroy(int $id, ImageOptimizationService $images): JsonResponse
    {
        $item = RetailProduct::findOrFail($id);

        foreach ($item->images as $img) {
            $images->delete($img->image_path, $img->thumbnail_path);
        }

        $item->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}

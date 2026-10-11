<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\HeroSlide;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminHeroSlideController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 100), 200);

        return response()->json(HeroSlide::orderBy('sort_order')->paginate($perPage));
    }

    public function store(Request $request, ImageOptimizationService $images): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string',
            'eyebrow' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        $paths = $images->store($request->file('image'), 'hero-slides');

        $slide = HeroSlide::create([
            'title' => $validated['title'],
            'subtitle' => $validated['subtitle'] ?? null,
            'eyebrow' => $validated['eyebrow'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
            'image_path' => $paths['image_path'],
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Hero slide created.', 'slide' => $slide], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(HeroSlide::findOrFail($id));
    }

    public function update(Request $request, int $id, ImageOptimizationService $images): JsonResponse
    {
        $slide = HeroSlide::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'subtitle' => 'nullable|string',
            'eyebrow' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if ($request->hasFile('image')) {
            $images->delete($slide->image_path);
            $paths = $images->store($request->file('image'), 'hero-slides');
            $validated['image_path'] = $paths['image_path'];
        }
        unset($validated['image']);

        $slide->update($validated);

        return response()->json(['message' => 'Hero slide updated.', 'slide' => $slide->fresh()]);
    }

    public function destroy(int $id, ImageOptimizationService $images): JsonResponse
    {
        $slide = HeroSlide::findOrFail($id);
        $images->delete($slide->image_path);
        $slide->delete();

        return response()->json(['message' => 'Hero slide deleted.']);
    }
}

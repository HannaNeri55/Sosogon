<?php

namespace App\Http\Controllers\Api\Business\FoodBeverage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StoreMenuItemRequest;
use App\Http\Requests\Business\UpdateMenuItemRequest;
use App\Models\FoodBeverage;
use App\Models\MenuItem;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuItemController extends Controller
{
    public const MAX_ITEMS = 20;

    public const CAPACITY_MESSAGE = 'Maximum capacity reached: You have registered the limit of 20 menu items. Delete an existing dish to add a new one.';

    private function ownedFoodBeverage(Request $request): FoodBeverage
    {
        return FoodBeverage::where('user_id', $request->user()->id)->firstOrFail();
    }

    private function ownedMenuItem(Request $request, int $itemId): MenuItem
    {
        return $this->ownedFoodBeverage($request)->menuItems()->where('id', $itemId)->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $foodBeverage = $this->ownedFoodBeverage($request);

        return response()->json($foodBeverage->menuItems()->orderBy('created_at')->get());
    }

    public function store(StoreMenuItemRequest $request, ImageOptimizationService $images): JsonResponse
    {
        $foodBeverage = $this->ownedFoodBeverage($request);

        if ($foodBeverage->menuItems()->count() >= self::MAX_ITEMS) {
            return response()->json(['message' => self::CAPACITY_MESSAGE], 422);
        }

        $validated = $request->validated();

        $trusted = $foodBeverage->status === 'approved';

        $item = MenuItem::create([
            'food_beverage_id' => $foodBeverage->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'status' => $trusted ? 'approved' : 'pending',
            'approved_at' => $trusted ? now() : null,
        ]);

        if ($request->hasFile('image')) {
            $paths = $images->store($request->file('image'), "menu-items/{$item->id}");
            $item->update($paths);
        }

        return response()->json([
            'message' => $trusted ? 'Dish added and is now live for tourists.' : 'Dish added. It will be visible to tourists once approved.',
            'menu_item' => $item->fresh(),
        ], 201);
    }

    public function update(UpdateMenuItemRequest $request, int $item, ImageOptimizationService $images): JsonResponse
    {
        $foodBeverage = $this->ownedFoodBeverage($request);
        $item = $foodBeverage->menuItems()->where('id', $item)->firstOrFail();

        $validated = $request->validated();

        $trusted = $foodBeverage->status === 'approved';
        $needsReReview = ! $trusted && $item->status !== 'pending';

        $item->update([
            'name' => $validated['name'] ?? $item->name,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $item->description,
            'price' => $validated['price'] ?? $item->price,
        ]);

        if ($trusted) {
            if ($item->status !== 'approved') {
                $item->update([
                    'status' => 'approved',
                    'admin_notes' => null,
                    'approved_at' => now(),
                    // Auto-approved via the parent establishment's trust, not by a
                    // human review of this specific edit — attribute it to whichever
                    // admin approved the establishment, rather than losing the trail.
                    'approved_by' => $foodBeverage->approved_by,
                ]);
            }
        } elseif ($needsReReview) {
            $item->update([
                'status' => 'pending',
                'admin_notes' => null,
                'approved_at' => null,
                'approved_by' => null,
            ]);
        }

        if ($request->hasFile('image')) {
            $images->delete($item->image_path, $item->thumbnail_path);
            $paths = $images->store($request->file('image'), "menu-items/{$item->id}");
            $item->update($paths);
        }

        return response()->json([
            'message' => $needsReReview
                ? 'Changes saved. This dish needs Tourism Office approval again before it is visible to tourists.'
                : 'Dish updated.',
            'menu_item' => $item->fresh(),
        ]);
    }

    public function destroy(Request $request, int $item, ImageOptimizationService $images): JsonResponse
    {
        $item = $this->ownedMenuItem($request, $item);

        $images->delete($item->image_path, $item->thumbnail_path);

        $item->delete();

        return response()->json(['message' => 'Dish deleted.']);
    }
}

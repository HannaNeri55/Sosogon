<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ReviewsItemChanges;
use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMenuItemController extends Controller
{
    use ReviewsItemChanges;

    public function index(Request $request): JsonResponse
    {
        $query = MenuItem::with(['foodBeverage:id,name,user_id,status,municipality_id', 'foodBeverage.user:id,name', 'foodBeverage.municipality:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Approved dishes with proposed changes awaiting review.
        if ($request->input('changes') === 'pending') {
            $query->where('pending_changes->status', 'pending');
        }

        $perPage = $this->perPage($request, 100, 200);

        $page = $query->orderByDesc('created_at')->paginate($perPage);
        $this->withPendingChanges($page->getCollection());

        return response()->json($page);
    }

    public function approveChanges(Request $request, int $id): JsonResponse
    {
        return $this->approveItemChanges($request, MenuItem::class, $id, function (MenuItem $item, array $pending) {
            $new = $pending['new_images'][0] ?? null;
            if (! $new) {
                return [];
            }

            $old = [[$item->image_path, $item->thumbnail_path]];
            $item->update(['image_path' => $new['image_path'], 'thumbnail_path' => $new['thumbnail_path']]);

            return $old;
        });
    }

    public function rejectChanges(Request $request, int $id): JsonResponse
    {
        return $this->rejectItemChanges($request, MenuItem::class, $id);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $item = MenuItem::pending()->findOrFail($id);

        $item->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'admin_notes' => $request->input('notes'),
        ]);

        return response()->json(['message' => 'Menu item approved.']);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['notes' => 'required|string|max:1000']);

        $item = MenuItem::pending()->findOrFail($id);

        $item->update([
            'status' => 'rejected',
            'admin_notes' => $request->notes,
        ]);

        return response()->json(['message' => 'Menu item rejected.']);
    }
}

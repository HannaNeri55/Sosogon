<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMenuItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MenuItem::with(['foodBeverage:id,name,user_id', 'foodBeverage.user:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min((int) $request->input('per_page', 100), 200);

        return response()->json($query->orderByDesc('created_at')->paginate($perPage));
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

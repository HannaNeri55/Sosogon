<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\RetailShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminRetailShopController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = RetailShop::with(['municipality', 'barangay', 'images', 'user:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min((int) $request->input('per_page', 100), 200);

        return response()->json($query->orderByDesc('created_at')->paginate($perPage));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $shop = RetailShop::pending()->findOrFail($id);

        $shop->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'admin_notes' => $request->input('notes'),
        ]);

        return response()->json(['message' => 'Shop approved.']);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['notes' => 'required|string|max:1000']);

        $shop = RetailShop::pending()->findOrFail($id);

        $shop->update([
            'status' => 'rejected',
            'admin_notes' => $request->notes,
        ]);

        return response()->json(['message' => 'Shop rejected.']);
    }
}

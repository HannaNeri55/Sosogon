<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccommodationRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAccommodationRoomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AccommodationRoom::with(['images', 'accommodation:id,name,user_id', 'accommodation.user:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = min((int) $request->input('per_page', 100), 200);

        return response()->json($query->orderByDesc('created_at')->paginate($perPage));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $room = AccommodationRoom::pending()->findOrFail($id);

        $room->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'admin_notes' => $request->input('notes'),
        ]);

        return response()->json(['message' => 'Room approved.']);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['notes' => 'required|string|max:1000']);

        $room = AccommodationRoom::pending()->findOrFail($id);

        $room->update([
            'status' => 'rejected',
            'admin_notes' => $request->notes,
        ]);

        return response()->json(['message' => 'Room rejected.']);
    }
}

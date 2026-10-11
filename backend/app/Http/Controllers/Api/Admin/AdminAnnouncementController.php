<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 100), 200);

        return response()->json(Announcement::orderByDesc('created_at')->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'is_pinned' => 'boolean',
            'status' => 'required|in:draft,published',
        ]);
        $validated['created_by'] = $request->user()->id;
        $announcement = Announcement::create($validated);

        return response()->json(['message' => 'Created.', 'announcement' => $announcement], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(Announcement::findOrFail($id));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = Announcement::findOrFail($id);
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'is_pinned' => 'boolean',
            'status' => 'sometimes|in:draft,published,archived',
        ]);
        $item->update($validated);

        return response()->json(['message' => 'Updated.', 'announcement' => $item]);
    }

    public function destroy(int $id): JsonResponse
    {
        Announcement::findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}

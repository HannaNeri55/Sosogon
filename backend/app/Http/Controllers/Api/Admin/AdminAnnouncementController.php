<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Support\AdminScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Announcements belong to a municipality, or to the whole province (no municipality). A
 * municipal admin sees and manages their municipality's only; the province-wide ones are the
 * provincial admin's.
 */
class AdminAnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = $this->perPage($request, 100, 200);
        $page = AdminScope::apply(Announcement::query(), $request->user())
            ->with('municipality:id,name')->orderByDesc('created_at')->paginate($perPage);
        AdminScope::markManageable($page->getCollection(), $request->user());

        return response()->json($page);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'is_pinned' => 'boolean',
            'status' => 'required|in:draft,published',
            'municipality_id' => 'nullable|exists:municipalities,id',
        ]);
        $validated['municipality_id'] = AdminScope::defaultMunicipality($request->user(), $validated['municipality_id'] ?? null);
        AdminScope::authorize($request->user(), $validated['municipality_id']);
        $validated['created_by'] = $request->user()->id;
        $announcement = Announcement::create($validated);

        return response()->json(['message' => 'Created.', 'announcement' => $announcement], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $item = Announcement::with('municipality:id,name')->findOrFail($id);
        AdminScope::authorizeRecordView($request->user(), $item);
        AdminScope::markManageable([$item], $request->user());

        return response()->json($item);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $item = Announcement::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $item);
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'is_pinned' => 'boolean',
            'status' => 'sometimes|in:draft,published,archived',
            'municipality_id' => 'sometimes|nullable|exists:municipalities,id',
        ]);
        if (array_key_exists('municipality_id', $validated)) {
            AdminScope::authorize($request->user(), $validated['municipality_id'] === null ? null : (int) $validated['municipality_id']);
        }
        $item->update($validated);

        return response()->json(['message' => 'Updated.', 'announcement' => $item]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $item = Announcement::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $item);
        $item->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}

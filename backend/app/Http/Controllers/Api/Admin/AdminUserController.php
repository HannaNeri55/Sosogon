<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::with('role:id,name');

        if ($request->filled('role')) {
            $query->whereHas('role', fn ($q) => $q->where('name', $request->role));
        }

        if ($request->filled('q')) {
            $search = $request->q;
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        $perPage = min((int) $request->input('per_page', 100), 200);

        return response()->json($query->orderByDesc('created_at')->paginate($perPage));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'status' => 'sometimes|in:active,suspended',
            'name' => 'sometimes|string|max:255',
        ]);

        $user->update($validated);

        return response()->json(['message' => 'User updated.', 'user' => $user->load('role')]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if ($user->isAdmin()) {
            return response()->json(['message' => 'Cannot delete admin user.'], 403);
        }
        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }
}

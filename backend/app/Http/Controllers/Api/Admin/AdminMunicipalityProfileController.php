<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Public\LocationController;
use App\Http\Controllers\Controller;
use App\Models\Municipality;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The About page's profiles of the 14 municipalities and Sorsogon City: contact details,
 * geography, history and a representative photo. They describe the whole province, so only
 * provincial admins manage them (the routes use admin.provincial).
 */
class AdminMunicipalityProfileController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Municipality::with('profileEditor:id,name')->orderBy('name')->get()->map(fn (Municipality $town) => [
            'id' => $town->id,
            'name' => $town->name,
            'lgu_type' => $town->lgu_type,
            'photo' => $town->representativePhoto(),
            // Which of the three sections still have nothing to show.
            'missing' => array_keys(array_filter([
                'contact' => blank($town->contact_number) && blank($town->email) && blank($town->address) && blank($town->other_contacts),
                'geography' => blank($town->geography) && blank($town->boundaries),
                'history' => blank($town->history) && blank($town->name_origin),
            ])),
            'updated_by' => $town->profileEditor?->name,
            'updated_at' => $town->profile_updated_by ? $town->updated_at : null,
        ]));
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->adminProfile(Municipality::findOrFail($id)));
    }

    public function update(Request $request, int $id, ImageOptimizationService $images): JsonResponse
    {
        $town = Municipality::findOrFail($id);

        $validated = $request->validate([
            'contact_number' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'other_contacts' => 'nullable|string|max:2000',
            'geography' => 'nullable|string|max:5000',
            'boundaries' => 'nullable|string|max:2000',
            'name_origin' => 'nullable|string|max:3000',
            'history' => 'nullable|string|max:10000',
            'sources' => 'nullable|string|max:5000',
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
            'remove_image' => 'boolean',
        ]);

        if ($request->hasFile('image') || $request->boolean('remove_image')) {
            $images->delete($town->image_path, $town->thumbnail_path);
            $validated['image_path'] = null;
            $validated['thumbnail_path'] = null;
        }
        if ($request->hasFile('image')) {
            $paths = $images->store($request->file('image'), 'municipalities');
            $validated['image_path'] = $paths['image_path'];
            $validated['thumbnail_path'] = $paths['thumbnail_path'];
        }
        unset($validated['image'], $validated['remove_image']);

        // Empty boxes are saved as "not available", never as blank text.
        $validated = array_map(fn ($value) => is_string($value) && trim($value) === '' ? null : $value, $validated);

        $town->fill($validated + ['profile_updated_by' => $request->user()->id])->save();

        return response()->json(['message' => "{$town->name} updated.", 'profile' => $this->adminProfile($town->fresh())]);
    }

    private function adminProfile(Municipality $town): array
    {
        $town->loadMissing('profileEditor:id,name');

        return LocationController::publicProfile($town) + [
            'sources' => $town->sources,
            'updated_by' => $town->profileEditor?->name,
            'updated_at' => $town->profile_updated_by ? $town->updated_at : null,
        ];
    }
}

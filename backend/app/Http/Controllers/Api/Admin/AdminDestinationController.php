<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Destination;
use App\Models\DestinationImage;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminDestinationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Destination::with([
            'category',
            'municipality',
            'barangay',
            'images',
            'fees',
            'rates',
            'amenities',
            'activities',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('q')) {
            $query->where('name', 'like', "%{$request->q}%");
        }

        $perPage = $this->perPage($request, 100, 200);

        return response()->json($query->orderByDesc('created_at')->paginate($perPage));
    }

    public function store(Request $request, ImageOptimizationService $images): JsonResponse
    {
        if ($duplicate = $this->rejectRepeatedSave($request)) {
            return $duplicate;
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'category_id' => 'required|exists:categories,id',
            'municipality_id' => 'required|exists:municipalities,id',
            'barangay_id' => 'nullable|exists:barangays,id',
            'address' => 'required|string|max:500',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'operating_hours' => 'nullable|string|max:255',
            'entrance_fee' => 'nullable|numeric|min:0',

            'fees' => 'sometimes|array',
            'fees.*.label' => 'required|string|max:255',
            'fees.*.amount' => 'required|numeric|min:0',
            'fees.*.description' => 'nullable|string|max:255',

            'rates' => 'sometimes|array',
            'rates.*.label' => 'required|string|max:255',
            'rates.*.amount' => 'required|numeric|min:0',
            'rates.*.description' => 'nullable|string|max:255',

            'amenities' => 'sometimes|array',
            'amenities.*.name' => 'required|string|max:255',
            ...$this->activityRules(null),

            'contact_number' => 'nullable|string|max:50',
            'website' => 'nullable|string|max:500',
            'status' => 'required|in:draft,published',
            'is_featured' => 'boolean',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
            // Which of the uploaded photos (0-based) is the cover; defaults to the first.
            'cover_new_index' => 'nullable|integer|min:0',
        ]);

        $coverNewIndex = (int) ($validated['cover_new_index'] ?? 0);
        unset($validated['cover_new_index']);

        $details = [
            'fees' => $validated['fees'] ?? [],
            'rates' => $validated['rates'] ?? [],
            'amenities' => $validated['amenities'] ?? [],
        ];

        unset(
            $validated['fees'],
            $validated['rates'],
            $validated['amenities'],
            $validated['activities'],
            $validated['activities_present']
        );


        $destination = DB::transaction(function () use ($validated, $details, $request) {
            $destination = Destination::create([
                ...$validated,
                'created_by' => $request->user()->id,
            ]);
            foreach ($details['fees'] as $index => $fee) {
                $destination->fees()->create([
                    ...$fee,
                    'sort_order' => $index,
                ]);
            }

            foreach ($details['rates'] as $index => $rate) {
                $destination->rates()->create([
                    ...$rate,
                    'sort_order' => $index,
                ]);
            }

            foreach ($details['amenities'] as $amenity) {
                $destination->amenities()->create($amenity);
            }

            return $destination;
        });


        $this->syncActivities($destination, $request, $images);

        // Handle images
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $paths = $images->store($image, "destinations/{$destination->id}");
                DestinationImage::create([
                    'destination_id' => $destination->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $index,
                    'is_primary' => $index === min($coverNewIndex, count($request->file('images')) - 1),
                ]);
            }
        }

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'created_destination',
            'description' => "Created destination: {$destination->name}",
            'model_type' => Destination::class,
            'model_id' => $destination->id,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Destination created.',
            'destination' => $destination->load(['images', 'fees', 'rates', 'amenities', 'activities']),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(
            Destination::with([
                'category',
                'municipality',
                'barangay',
                'images',
                'fees',
                'rates',
                'amenities',
                'activities',
            ])->findOrFail($id)
        );
    }

    public function update(Request $request, int $id, ImageOptimizationService $images): JsonResponse
    {
        $destination = Destination::findOrFail($id);

        if ($duplicate = $this->rejectRepeatedSave($request)) {
            return $duplicate;
        }

        // Photos the admin removed in this edit (only ones that belong to this destination).
        $removeIds = $destination->images()
            ->whereIn('id', array_map('intval', (array) $request->input('remove_image_ids', [])))
            ->pluck('id')
            ->all();

        // Prevent additional uploads from exceeding 100 photos per destination.
        $existingPhotoCount = $destination->images()->count() - count($removeIds);
        $newPhotoCount = count($request->file('images', []));

        if ($existingPhotoCount + $newPhotoCount > 100) {
            return response()->json([
                'message' => 'A destination can have a maximum of 100 photos.',
                'errors' => [
                    'images' => [
                        "This destination already has {$existingPhotoCount} photos. You can only add " .
                            max(0, 100 - $existingPhotoCount) . ' more.',
                    ],
                ],
            ], 422);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'category_id' => 'sometimes|exists:categories,id',
            'municipality_id' => 'sometimes|exists:municipalities,id',
            'barangay_id' => 'sometimes|nullable|exists:barangays,id',
            'address' => 'sometimes|string|max:500',
            'latitude' => 'sometimes|numeric|between:-90,90',
            'longitude' => 'sometimes|numeric|between:-180,180',
            'operating_hours' => 'nullable|string|max:255',
            'entrance_fee' => 'nullable|numeric|min:0',

            'fees' => 'sometimes|array',
            'fees.*.label' => 'required|string|max:255',
            'fees.*.amount' => 'required|numeric|min:0',
            'fees.*.description' => 'nullable|string|max:255',

            'rates' => 'sometimes|array',
            'rates.*.label' => 'required|string|max:255',
            'rates.*.amount' => 'required|numeric|min:0',
            'rates.*.description' => 'nullable|string|max:255',

            'amenities' => 'sometimes|array',
            'amenities.*.name' => 'required|string|max:255',
            ...$this->activityRules($id),

            'contact_number' => 'nullable|string|max:50',
            'website' => 'nullable|string|max:500',
            'status' => 'sometimes|in:draft,published,archived',
            'is_featured' => 'boolean',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
            'remove_image_ids' => 'sometimes|array',
            'remove_image_ids.*' => 'integer',
            // Cover choice: an existing photo's id, or the 0-based index of a new upload.
            'cover_image_id' => 'nullable|integer',
            'cover_new_index' => 'nullable|integer|min:0',
        ]);

        $coverImageId = isset($validated['cover_image_id']) ? (int) $validated['cover_image_id'] : null;
        $coverNewIndex = isset($validated['cover_new_index']) ? (int) $validated['cover_new_index'] : null;
        unset($validated['remove_image_ids'], $validated['cover_image_id'], $validated['cover_new_index']);

        $details = [];

        foreach (['fees', 'rates', 'amenities'] as $key) {
            if ($request->input('details_present') === '1') {
                $details[$key] = $validated[$key] ?? [];
            } elseif (array_key_exists($key, $validated)) {
                $details[$key] = $validated[$key];
            }

            unset($validated[$key]);
        }
        unset($validated['activities'], $validated['activities_present']);


        // Save destination details together in one transaction
        DB::transaction(function () use ($destination, $validated, $details) {
            $destination->update($validated);

            if (array_key_exists('fees', $details)) {
                $destination->fees()->delete();

                foreach ($details['fees'] as $index => $fee) {
                    $destination->fees()->create([
                        ...$fee,
                        'sort_order' => $index,
                    ]);
                }
            }

            if (array_key_exists('rates', $details)) {
                $destination->rates()->delete();

                foreach ($details['rates'] as $index => $rate) {
                    $destination->rates()->create([
                        ...$rate,
                        'sort_order' => $index,
                    ]);
                }
            }

            if (array_key_exists('amenities', $details)) {
                $destination->amenities()->delete();

                foreach ($details['amenities'] as $amenity) {
                    $destination->amenities()->create($amenity);
                }
            }
        });

        $this->syncActivities($destination, $request, $images);

        // Handle new images
        $newImageIds = [];
        if ($request->hasFile('images')) {
            $maxOrder = $destination->images()->max('sort_order') ?? -1;

            foreach ($request->file('images') as $index => $image) {
                $paths = $images->store($image, "destinations/{$destination->id}");

                $newImageIds[] = DestinationImage::create([
                    'destination_id' => $destination->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $maxOrder + $index + 1,
                    'is_primary' => false,
                ])->id;
            }
        }

        // Apply removals and the cover choice together, so the destination
        // never ends up without a cover while it still has photos.
        $removed = DB::transaction(function () use ($destination, $removeIds, $coverImageId, $coverNewIndex, $newImageIds) {
            $removed = $destination->images()->whereIn('id', $removeIds)->get();
            $destination->images()->whereIn('id', $removeIds)->delete();

            $remaining = $destination->images()->pluck('id')->all();
            $coverId = match (true) {
                $coverNewIndex !== null && isset($newImageIds[$coverNewIndex]) => $newImageIds[$coverNewIndex],
                $coverImageId !== null && in_array($coverImageId, $remaining, true) => $coverImageId,
                default => $destination->images()->where('is_primary', true)->value('id')
                    ?? $destination->images()->value('id'),
            };

            if ($coverId) {
                $destination->images()->where('id', '!=', $coverId)->update(['is_primary' => false]);
                $destination->images()->where('id', $coverId)->update(['is_primary' => true]);
            }

            return $removed;
        });

        // Delete files only after the database change has been committed.
        foreach ($removed as $image) {
            $images->delete($image->image_path, $image->thumbnail_path);
        }

        return response()->json([
            'message' => 'Destination updated.',
            'destination' => $destination->fresh(['images', 'fees', 'rates', 'amenities', 'activities']),
        ]);
    }


    public function setCoverImage(int $id, int $imageId): JsonResponse
    {
        $destination = Destination::findOrFail($id);

        // Only allow an image belonging to this destination.
        $image = $destination->images()
            ->where('id', $imageId)
            ->firstOrFail();

        // Ensure only one image is marked as the cover.
        DB::transaction(function () use ($destination, $image) {
            $destination->images()->update(['is_primary' => false]);

            $image->update(['is_primary' => true]);
        });

        return response()->json([
            'message' => 'Cover photo updated successfully.',
            'images' => $destination->fresh()->images,
        ]);
    }


    public function destroyImage(int $id, int $imageId, ImageOptimizationService $images): JsonResponse
    {
        $destination = Destination::findOrFail($id);

        /** @var DestinationImage $image */
        $image = $destination->images()->where('id', $imageId)->firstOrFail();

        $images->delete($image->image_path, $image->thumbnail_path);
        $image->delete();

        return response()->json(['message' => 'Image removed.']);
    }

    public function destroy(int $id, ImageOptimizationService $images): JsonResponse
    {
        $destination = Destination::findOrFail($id);

        // Delete images (and their thumbnails) from storage
        foreach ($destination->images as $img) {
            $images->delete($img->image_path, $img->thumbnail_path);
        }
        foreach ($destination->activities as $activity) {
            $images->delete($activity->image_path, $activity->thumbnail_path);
        }

        $destination->delete();

        return response()->json(['message' => 'Destination deleted.']);
    }

    /**
     * Validation for the optional activities section of the destination form.
     * Existing activity ids must belong to the destination being edited.
     */
    private function activityRules(?int $destinationId): array
    {
        return [
            'activities_present' => 'sometimes|in:0,1',
            'activities' => 'sometimes|array|max:50',
            'activities.*.id' => [
                'nullable',
                'integer',
                Rule::exists('activities', 'id')->where('destination_id', $destinationId ?? 0),
            ],
            'activities.*.name' => 'required|string|max:255',
            'activities.*.description' => 'nullable|string|max:5000',
            'activities.*.type' => 'required|in:indoor,outdoor',
            'activities.*.price' => 'nullable|numeric|min:0|max:99999999.99',
            'activities.*.duration_minutes' => 'nullable|integer|min:1|max:525600',
            'activities.*.capacity' => 'nullable|integer|min:1',
            'activities.*.image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:5120',
            'activities.*.remove_image' => 'nullable|in:0,1',
        ];
    }

    /**
     * Replace the destination's activities with the submitted list: rows with
     * an id are updated in place (keeping their ids and public URLs), rows
     * without one are created, and saved activities left out are deleted.
     * Does nothing unless the form sent activities_present=1.
     */
    private function syncActivities(Destination $destination, Request $request, ImageOptimizationService $images): void
    {
        if ($request->input('activities_present') !== '1') {
            return;
        }

        $submitted = $request->input('activities', []);
        $fields = ['name', 'description', 'type', 'price', 'duration_minutes', 'capacity'];
        $staleFiles = [];

        $saved = DB::transaction(function () use ($destination, $submitted, $fields, &$staleFiles) {
            $keep = collect($submitted)->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();

            foreach ($destination->activities()->whereNotIn('id', $keep)->get() as $old) {
                $staleFiles[] = [$old->image_path, $old->thumbnail_path];
                $old->delete();
            }

            $saved = [];
            foreach ($submitted as $index => $row) {
                $data = collect($row)->only($fields)->map(fn ($v) => $v === '' ? null : $v)->all();

                if (! empty($row['id'])) {
                    $activity = $destination->activities()->findOrFail((int) $row['id']);
                    $activity->update($data);
                } else {
                    $activity = $destination->activities()->create($data);
                }

                if (($row['remove_image'] ?? '0') === '1' && $activity->image_path) {
                    $staleFiles[] = [$activity->image_path, $activity->thumbnail_path];
                    $activity->update(['image_path' => null, 'thumbnail_path' => null]);
                }
                $saved[$index] = $activity;
            }

            return $saved;
        });

        // New or replacement images, stored per activity like the Activities page does.
        foreach ($saved as $index => $activity) {
            $file = $request->file("activities.{$index}.image");
            if (! $file) {
                continue;
            }
            if ($activity->image_path) {
                $staleFiles[] = [$activity->image_path, $activity->thumbnail_path];
            }
            $activity->update($images->store($file, "activities/{$activity->id}"));
        }

        // Old files are removed only after the database changes succeeded.
        foreach ($staleFiles as [$image, $thumbnail]) {
            $images->delete($image, $thumbnail);
        }
    }

    /**
     * The admin form sends a fresh save_token with every save attempt. A second
     * request carrying the same token (a double-click, or a resent request) is
     * refused, so the same photo uploads are never stored twice. Requests
     * without a token behave as before.
     */
    private function rejectRepeatedSave(Request $request): ?JsonResponse
    {
        $token = $request->input('save_token');

        if (! is_string($token) || $token === '' || strlen($token) > 100) {
            return null;
        }

        if (Cache::add('destination-save:'.$token, true, now()->addHour())) {
            return null;
        }

        return response()->json([
            'message' => 'This save was already submitted. Please wait for it to finish.',
        ], 409);
    }
}

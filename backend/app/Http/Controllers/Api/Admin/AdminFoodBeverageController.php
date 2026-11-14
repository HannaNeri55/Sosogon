<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FoodBeverage;
use App\Models\FoodBeverageImage;
use App\Models\FoodBeverageRevision;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Support\AdminScope;
use App\Support\RevisionBase;
use Illuminate\Validation\ValidationException;

class AdminFoodBeverageController extends Controller
{
    public const MIN_GALLERY_IMAGES = 5;
    public const MAX_GALLERY_IMAGES = 100;

    public function index(Request $request): JsonResponse
    {
        $query = AdminScope::apply(FoodBeverage::query(), $request->user())->with([
            'municipality',
            'barangay',
            'images',
            'user:id,name',
            'revisions' => fn ($query) => $query
                ->whereIn('status', [
                    'draft',
                    'submitted',
                    'needs_revision',
                ])
                ->latest('id'),
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('q')) {
            $query->where(
                'name',
                'like',
                "%{$request->q}%"
            );
        }

        $perPage = max(
            1,
            $this->perPage($request, 100, 200)
        );

        return response()->json(
            $query->orderByDesc('created_at')->paginate($perPage)
        );
    }

    public function store(
        Request $request,
        ImageOptimizationService $images
    ): JsonResponse {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'cuisine_type' => 'nullable|string|max:100',
            'municipality_id' => 'required|exists:municipalities,id',
            'barangay_id' => 'nullable|exists:barangays,id',
            'address' => 'required|string|max:500',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'price_range' => 'required|in:budget,moderate,luxury',
            'contact_number' => 'nullable|string',
            'website' => 'nullable|string|max:500',
            'operating_hours' => 'nullable|string',
            'status' => 'required|in:pending,approved',
            'images' => 'nullable|array|max:100',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);
        // A municipal admin adds establishments to their own municipality only.
        AdminScope::authorize($request->user(), (int) $validated['municipality_id']);

        $uploadedFiles = $request->file('images', []);
        $uploadedFiles = is_array($uploadedFiles)
            ? $uploadedFiles
            : [$uploadedFiles];

        if (
            $validated['status'] === 'approved' &&
            count($uploadedFiles) < self::MIN_GALLERY_IMAGES
        ) {
            throw ValidationException::withMessages([
                'images' => 'A new food and beverage listing must have at least 5 gallery photos before publication.',
            ]);
        }

        $isApproved = $validated['status'] === 'approved';

        $validated['publication_review_status'] = $isApproved
            ? 'published'
            : 'draft';

        $validated['approved_at'] = $isApproved
            ? now()
            : null;

        $validated['approved_by'] = $isApproved
            ? $request->user()->id
            : null;

        unset($validated['images']);

        $item = FoodBeverage::create($validated);

        foreach ($uploadedFiles as $index => $image) {
            $paths = $images->store(
                $image,
                "food-beverages/{$item->id}"
            );

            FoodBeverageImage::create([
                'food_beverage_id' => $item->id,
                'image_path' => $paths['image_path'],
                'thumbnail_path' => $paths['thumbnail_path'],
                'sort_order' => $index,
                'is_primary' => $index === 0,
            ]);
        }

        return response()->json([
            'message' => 'Created.',
            'food_beverage' => $item->load('images'),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $item = FoodBeverage::with([
            'municipality',
            'barangay',
            'images',
            'user:id,name',
            'revisions' => fn ($query) => $query
                ->whereIn('status', [
                    'draft',
                    'submitted',
                    'needs_revision',
                ])
                ->latest('id'),
        ])->findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $item);

        return response()->json($item);
    }

    public function update(
        Request $request,
        int $id,
        ImageOptimizationService $images
    ): JsonResponse {
        $item = FoodBeverage::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $item);

        $validated = $request->validate([
            'revision_action' => 'sometimes|required|in:approve,needs_revision',
            'admin_notes' => 'nullable|string|max:5000',
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'cuisine_type' => 'nullable|string',
            'price_range' => 'sometimes|in:budget,moderate,luxury',
            'status' => 'sometimes|in:pending,approved,rejected,archived',
            'contact_number' => 'nullable|string',
            'website' => 'nullable|string|max:500',
            'operating_hours' => 'nullable|string',
            'images' => 'nullable|array|max:100',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        if (isset($validated['revision_action'])) {
            return $this->reviewRevision(
                $request,
                $item,
                $validated
            );
        }

        unset(
            $validated['revision_action'],
            $validated['admin_notes']
        );

        $uploadedFiles = $request->file('images', []);
        $uploadedFiles = is_array($uploadedFiles)
            ? $uploadedFiles
            : [$uploadedFiles];

        $existingCount = $item->images()->count();
        $totalCount = $existingCount + count($uploadedFiles);

        if ($totalCount > self::MAX_GALLERY_IMAGES) {
            throw ValidationException::withMessages([
                'images' => 'A food and beverage gallery cannot exceed 100 photos.',
            ]);
        }

        $requestedStatus = $validated['status']
            ?? $item->status;

        $isNewApproval = (
            $requestedStatus === 'approved' &&
            $item->status !== 'approved'
        );

        if ($isNewApproval) {
            if ($totalCount < self::MIN_GALLERY_IMAGES) {
                throw ValidationException::withMessages([
                    'images' => 'Upload at least 5 gallery photos before approving this food and beverage listing.',
                ]);
            }

            if (
                $item->user_id !== null &&
                $item->publication_review_status !== 'submitted'
            ) {
                throw ValidationException::withMessages([
                    'status' => 'The business owner must submit the completed listing for publication review before approval.',
                ]);
            }

            $validated['approved_at'] = now();
            $validated['approved_by'] = $request->user()->id;
            $validated['publication_review_status'] = 'published';
        } elseif (array_key_exists('status', $validated)) {
            if ($requestedStatus === 'approved') {
                $validated['publication_review_status'] = 'published';
            } elseif ($requestedStatus === 'rejected') {
                $validated['publication_review_status'] = 'needs_revision';
                $validated['approved_at'] = null;
                $validated['approved_by'] = null;
            } elseif ($requestedStatus === 'pending') {
                $validated['approved_at'] = null;
                $validated['approved_by'] = null;

                if ($item->status === 'approved') {
                    $validated['publication_review_status'] = 'draft';
                }
            } elseif ($requestedStatus === 'archived') {
                $validated['approved_at'] = null;
                $validated['approved_by'] = null;
            }
        }

        unset($validated['images']);

        $item->update($validated);

        if (! empty($uploadedFiles)) {
            $maxOrder = $item->images()
                ->max('sort_order') ?? -1;

            $hasCover = $item->images()
                ->where('is_primary', true)
                ->exists();

            foreach ($uploadedFiles as $index => $image) {
                $paths = $images->store(
                    $image,
                    "food-beverages/{$item->id}"
                );

                FoodBeverageImage::create([
                    'food_beverage_id' => $item->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $maxOrder + $index + 1,
                    'is_primary' => ! $hasCover && $index === 0,
                ]);
            }
        }

        return response()->json([
            'message' => 'Updated.',
            'food_beverage' => $item->fresh('images'),
        ]);
    }

    private function reviewRevision(
        Request $request,
        FoodBeverage $item,
        array $validated
    ): JsonResponse {
        AdminScope::authorizeRecord($request->user(), $item);
        if ($item->status !== 'approved') {
            throw ValidationException::withMessages([
                'revision_action' => 'Revision review is only available for published listings.',
            ]);
        }

        $revision = $item->revisions()
            ->where('status', 'submitted')
            ->latest('id')
            ->first();

        if (! $revision) {
            throw ValidationException::withMessages([
                'revision_action' => 'No submitted revision is available for review.',
            ]);
        }

        $action = $validated['revision_action'];

        if ($action === 'needs_revision') {
            $notes = trim($validated['admin_notes'] ?? '');

            if ($notes === '') {
                throw ValidationException::withMessages([
                    'admin_notes' => 'Please explain what the business owner needs to revise.',
                ]);
            }

            $revision->update([
                'status' => 'needs_revision',
                'admin_notes' => $notes,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return response()->json([
                'message' => 'Changes requested. The original listing remains published.',
                'food_beverage' => $item->fresh([
                    'images',
                    'revisions',
                ]),
            ]);
        }

        $data = $revision->proposed_data;
        $gallery = $revision->proposed_gallery;

        if (! is_array($data) || ! is_array($gallery)) {
            throw ValidationException::withMessages([
                'revision_action' => 'The submitted revision is incomplete.',
            ]);
        }

        // Never overwrite values that changed on the live listing since the owner started editing.
        AdminScope::authorizeTransfer($request->user(), $item, $data['municipality_id'] ?? null);
        if ($conflict = RevisionBase::prepareForApproval($revision, $item, $request->user()->id, $data, $gallery)) {
            return $conflict;
        }

        $count = count($gallery);

        if (
            $count < self::MIN_GALLERY_IMAGES ||
            $count > self::MAX_GALLERY_IMAGES
        ) {
            throw ValidationException::withMessages([
                'images' => 'The submitted gallery must contain between 5 and 100 photos.',
            ]);
        }

        $allowedFields = [
            'name',
            'description',
            'website',
            'address',
            'municipality_id',
        ];

        $approvedData = array_intersect_key(
            $data,
            array_flip($allowedFields)
        );

        $validator = validator($approvedData, [
            'name' => 'required|string|max:255',
            'description' => 'required|string|min:50',
            'website' => 'nullable|string|max:500',
            'address' => 'required|string|max:500',
            'municipality_id' => 'required|exists:municipalities,id',
        ]);

        $approvedData = $validator->validate();

        $existingImages = $item->images()
            ->get()
            ->keyBy('id');

        $validatedGallery = [];
        $seenKeys = [];
        $primaryCount = 0;

        foreach ($gallery as $entry) {
            if (
                ! is_array($entry) ||
                ! isset(
                    $entry['key'],
                    $entry['image_path'],
                    $entry['thumbnail_path']
                ) ||
                ! is_string($entry['key']) ||
                ! is_string($entry['image_path']) ||
                ! is_string($entry['thumbnail_path'])
            ) {
                throw ValidationException::withMessages([
                    'images' => 'A gallery entry is invalid.',
                ]);
            }

            $key = $entry['key'];

            if (isset($seenKeys[$key])) {
                throw ValidationException::withMessages([
                    'images' => 'Duplicate gallery entries are not allowed.',
                ]);
            }

            $seenKeys[$key] = true;

            $isPrimary = ($entry['is_primary'] ?? false) === true;

            if ($isPrimary) {
                $primaryCount++;
            }

            if (preg_match('/^existing:([1-9][0-9]*)$/', $key, $matches)) {
                $imageId = (int) $matches[1];
                $existing = $existingImages->get($imageId);

                if (
                    ! $existing ||
                    $existing->image_path !== $entry['image_path'] ||
                    $existing->thumbnail_path !== $entry['thumbnail_path']
                ) {
                    throw ValidationException::withMessages([
                        'images' => 'An existing gallery image does not belong to this restaurant.',
                    ]);
                }

                $validatedGallery[] = [
                    'existing_id' => $imageId,
                    'image_path' => $existing->image_path,
                    'thumbnail_path' => $existing->thumbnail_path,
                    'is_primary' => $isPrimary,
                ];

                continue;
            }

            if (! str_starts_with($key, 'new:')) {
                throw ValidationException::withMessages([
                    'images' => 'An invalid gallery image key was submitted.',
                ]);
            }

            $prefix = "food-beverages/{$item->id}/revisions/{$revision->id}/";

            foreach ([
                $entry['image_path'],
                $entry['thumbnail_path'],
            ] as $path) {
                if (
                    ! str_starts_with($path, $prefix) ||
                    ! preg_match(
                        '/^[a-zA-Z0-9_-]+\.webp$/',
                        substr($path, strlen($prefix))
                    ) ||
                    ! Storage::disk('public')->exists($path)
                ) {
                    throw ValidationException::withMessages([
                        'images' => 'A newly uploaded revision photo is missing or invalid.',
                    ]);
                }
            }

            $validatedGallery[] = [
                'existing_id' => null,
                'image_path' => $entry['image_path'],
                'thumbnail_path' => $entry['thumbnail_path'],
                'is_primary' => $isPrimary,
            ];
        }

        if ($primaryCount !== 1) {
            throw ValidationException::withMessages([
                'images' => 'Exactly one cover photo must be selected.',
            ]);
        }

        DB::transaction(function () use (
            $item,
            $revision,
            $approvedData,
            $validatedGallery,
            $request
        ) {
            $item->update($approvedData);

            $retainedIds = collect($validatedGallery)
                ->pluck('existing_id')
                ->filter(fn ($id) => $id !== null)
                ->all();

            $deleteQuery = $item->images();

            if (! empty($retainedIds)) {
                $deleteQuery->whereNotIn('id', $retainedIds);
            }

            $deleteQuery->delete();

            foreach ($validatedGallery as $order => $entry) {
                if ($entry['existing_id'] !== null) {
                    $item->images()
                        ->whereKey($entry['existing_id'])
                        ->update([
                            'sort_order' => $order,
                            'is_primary' => $entry['is_primary'],
                        ]);
                } else {
                    FoodBeverageImage::create([
                        'food_beverage_id' => $item->id,
                        'image_path' => $entry['image_path'],
                        'thumbnail_path' => $entry['thumbnail_path'],
                        'sort_order' => $order,
                        'is_primary' => $entry['is_primary'],
                    ]);
                }
            }

            $revision->update([
                'status' => 'approved',
                'admin_notes' => null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Revision approved. Published restaurant information has been updated.',
            'food_beverage' => $item->fresh([
                'municipality',
                'barangay',
                'images',
                'menuItems',
                'revisions',
            ]),
        ]);
    }

    public function destroy(
        Request $request,
        int $id,
        ImageOptimizationService $images
    ): JsonResponse {
        $item = FoodBeverage::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $item);

        foreach ($item->images as $img) {
            $images->delete(
                $img->image_path,
                $img->thumbnail_path
            );
        }

        $item->delete();

        return response()->json([
            'message' => 'Deleted.',
        ]);
    }
}

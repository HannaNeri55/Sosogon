<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\AccommodationImage;
use App\Services\ImageOptimizationService;
use App\Support\RevisionBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminAccommodationController extends Controller
{
    public const MIN_GALLERY_IMAGES = 5;

    public const MAX_GALLERY_IMAGES = 100;

    public function index(Request $request): JsonResponse
    {
        $query = Accommodation::with([
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
            'type' => 'required|string|max:100',
            'municipality_id' => 'required|exists:municipalities,id',
            'barangay_id' => 'nullable|exists:barangays,id',
            'address' => 'required|string|max:500',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'price_range' => 'required|in:budget,moderate,luxury',
            'contact_number' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|string|max:500',
            'offering_arrangement' => 'sometimes|nullable|in:units,entire_property,both',
            'classifications' => 'sometimes|nullable|array|max:8',
            'classifications.*' => ['string', Rule::in(Accommodation::CLASSIFICATIONS)],
            'check_in_time' => 'sometimes|nullable|string|max:50',
            'check_out_time' => 'sometimes|nullable|string|max:50',
            'operating_hours' => 'nullable|string|max:255',
            'amenities' => 'nullable|array',
            'amenities.*' => 'string|max:100',
            'status' => 'required|in:pending,approved',
            'images' => 'nullable|array|max:100',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
        ]);

        $uploadedFiles = $request->file('images', []);

        $uploadedFiles = is_array($uploadedFiles)
            ? $uploadedFiles
            : [$uploadedFiles];

        if (
            $validated['status'] === 'approved' &&
            count($uploadedFiles) < self::MIN_GALLERY_IMAGES
        ) {
            throw ValidationException::withMessages([
                'images' => 'A new accommodation must have at least 5 gallery photos before publication.',
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

        $accommodation = Accommodation::create($validated);

        foreach ($uploadedFiles as $index => $image) {
            $paths = $images->store(
                $image,
                "accommodations/{$accommodation->id}"
            );

            AccommodationImage::create([
                'accommodation_id' => $accommodation->id,
                'image_path' => $paths['image_path'],
                'thumbnail_path' => $paths['thumbnail_path'],
                'sort_order' => $index,
                'is_primary' => $index === 0,
            ]);
        }

        return response()->json([
            'message' => 'Accommodation created.',
            'accommodation' => $accommodation->load('images'),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(
            Accommodation::with([
                'municipality',
                'barangay',
                'images',
                'user',
                'revisions' => fn ($query) => $query
                    ->whereIn('status', [
                        'draft',
                        'submitted',
                        'needs_revision',
                    ])
                    ->latest('id'),
            ])->findOrFail($id)
        );
    }

    public function update(
        Request $request,
        int $id,
        ImageOptimizationService $images
    ): JsonResponse {
        $accommodation = Accommodation::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'type' => 'sometimes|string|max:100',
            'municipality_id' => 'sometimes|exists:municipalities,id',
            'address' => 'sometimes|string|max:500',
            'price_range' => 'sometimes|nullable|in:budget,moderate,luxury',
            'status' => 'sometimes|in:pending,approved,rejected,archived',
            'contact_number' => 'sometimes|nullable|string|max:50',
            'email' => 'sometimes|nullable|email|max:255',
            'website' => 'sometimes|nullable|string|max:500',
            'offering_arrangement' => 'sometimes|nullable|in:units,entire_property,both',
            'classifications' => 'sometimes|nullable|array|max:8',
            'classifications.*' => ['string', Rule::in(Accommodation::CLASSIFICATIONS)],
            'check_in_time' => 'sometimes|nullable|string|max:50',
            'check_out_time' => 'sometimes|nullable|string|max:50',
            'operating_hours' => 'sometimes|nullable|string|max:255',
            'amenities' => 'sometimes|nullable|array',
            'amenities.*' => 'string|max:100',
            'images' => 'nullable|array|max:100',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
            'revision_action' => 'sometimes|in:approve,needs_revision',
            'admin_notes' => 'sometimes|nullable|string|max:5000',
        ]);

        /*
         * Published accommodation revisions are reviewed
         * separately from initial publication approval.
         */
        if (isset($validated['revision_action'])) {
            return $this->reviewRevision(
                $accommodation,
                $validated,
                $request
            );
        }

        $uploadedFiles = $request->file('images', []);

        $uploadedFiles = is_array($uploadedFiles)
            ? $uploadedFiles
            : [$uploadedFiles];

        $existingCount = $accommodation->images()->count();
        $totalCount = $existingCount + count($uploadedFiles);

        if ($totalCount > self::MAX_GALLERY_IMAGES) {
            throw ValidationException::withMessages([
                'images' => 'An accommodation gallery cannot exceed 100 photos.',
            ]);
        }

        $requestedStatus = $validated['status']
            ?? $accommodation->status;

        $isNewApproval = (
            $requestedStatus === 'approved' &&
            $accommodation->status !== 'approved'
        );

        if ($isNewApproval) {
            if ($totalCount < self::MIN_GALLERY_IMAGES) {
                throw ValidationException::withMessages([
                    'images' => 'Upload at least 5 gallery photos before approving this accommodation.',
                ]);
            }

            if (
                $accommodation->user_id !== null &&
                $accommodation->publication_review_status !== 'submitted'
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

                if ($accommodation->status === 'approved') {
                    $validated['publication_review_status'] = 'draft';
                }
            } elseif ($requestedStatus === 'archived') {
                $validated['approved_at'] = null;
                $validated['approved_by'] = null;
            }
        }

        unset(
            $validated['images'],
            $validated['revision_action'],
            $validated['admin_notes']
        );

        $accommodation->update($validated);

        if (! empty($uploadedFiles)) {
            $maxOrder = $accommodation->images()
                ->max('sort_order') ?? -1;

            $hasCover = $accommodation->images()
                ->where('is_primary', true)
                ->exists();

            foreach ($uploadedFiles as $index => $image) {
                $paths = $images->store(
                    $image,
                    "accommodations/{$accommodation->id}"
                );

                AccommodationImage::create([
                    'accommodation_id' => $accommodation->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $maxOrder + $index + 1,
                    'is_primary' => ! $hasCover && $index === 0,
                ]);
            }
        }

        return response()->json([
            'message' => 'Accommodation updated.',
            'accommodation' => $accommodation->fresh('images'),
        ]);
    }

    /*
     * Review a submitted revision of an already
     * published accommodation.
     *
     * Request changes:
     * Published accommodation remains unchanged.
     *
     * Approve:
     * Proposed details and gallery become published.
     */
    private function reviewRevision(
        Accommodation $accommodation,
        array $validated,
        Request $request
    ): JsonResponse {
        if ($accommodation->status !== 'approved') {
            throw ValidationException::withMessages([
                'revision_action' => 'Revision review is only available for published accommodations.',
            ]);
        }

        $revision = $accommodation->revisions()
            ->where('status', 'submitted')
            ->latest('id')
            ->first();

        if (! $revision) {
            throw ValidationException::withMessages([
                'revision_action' => 'No submitted revision was found for this accommodation.',
            ]);
        }

        /*
         * Request changes without modifying the
         * currently published accommodation.
         */
        if ($validated['revision_action'] === 'needs_revision') {
            $notes = trim($validated['admin_notes'] ?? '');

            if ($notes === '') {
                throw ValidationException::withMessages([
                    'admin_notes' => 'Please explain the changes required from the business owner.',
                ]);
            }

            $revision->update([
                'status' => 'needs_revision',
                'admin_notes' => $notes,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            return response()->json([
                'message' => 'Revision returned to the owner for changes. Published accommodation is unchanged.',
                'accommodation' => $accommodation->fresh('images'),
                'revision' => $revision->fresh(),
            ]);
        }

        $data = $revision->proposed_data ?? [];
        $gallery = $revision->proposed_gallery ?? [];

        if (! is_array($data)) {
            throw ValidationException::withMessages([
                'revision' => 'The proposed accommodation data is invalid.',
            ]);
        }

        if (! is_array($gallery)) {
            throw ValidationException::withMessages([
                'images' => 'The proposed gallery is invalid.',
            ]);
        }

        // Never overwrite values that changed on the live listing since the owner started editing.
        if ($conflict = RevisionBase::prepareForApproval($revision, $accommodation, $request->user()->id, $data, $gallery)) {
            return $conflict;
        }

        if (
            count($gallery) < self::MIN_GALLERY_IMAGES ||
            count($gallery) > self::MAX_GALLERY_IMAGES
        ) {
            throw ValidationException::withMessages([
                'images' => 'The proposed gallery must contain between 5 and 100 photos.',
            ]);
        }

        /*
         * Only owner-editable fields can be published
         * through the revision workflow.
         */
        $allowedFields = [
            'name',
            'description',
            'website',
            'contact_number',
            'email',
            'operating_hours',
            'price_range',
            'amenities',
            'offering_arrangement',
            'classifications',
            'check_in_time',
            'check_out_time',
            'address',
            'municipality_id',
        ];

        $safeData = array_intersect_key(
            $data,
            array_flip($allowedFields)
        );

        $safeData = validator($safeData, [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'sometimes|required|string',
            'website' => 'nullable|string|max:500',
            'contact_number' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'operating_hours' => 'nullable|string|max:255',
            'price_range' => 'nullable|in:budget,moderate,luxury',
            'amenities' => 'nullable|array',
            'amenities.*' => 'string|max:100',
            'offering_arrangement' => 'nullable|in:units,entire_property,both',
            'classifications' => 'nullable|array',
            'classifications.*' => ['string', Rule::in(Accommodation::CLASSIFICATIONS)],
            'check_in_time' => 'nullable|string|max:50',
            'check_out_time' => 'nullable|string|max:50',
            'address' => 'sometimes|required|string|max:500',
            'municipality_id' => 'sometimes|required|exists:municipalities,id',
        ])->validate();

        /*
         * Retrieve existing published images.
         */
        $existingImages = $accommodation->images()
            ->get()
            ->keyBy('id');

        $retainedIds = [];
        $seenKeys = [];
        $seenPaths = [];
        $primaryCount = 0;

        /*
         * New images are stored in:
         *
         * accommodations/{id}/revisions/{revisionId}/
         *
         * Full image:
         * UUID.webp
         *
         * Thumbnail:
         * thumbnails/UUID.webp
         */
        $expectedDirectory =
            "accommodations/{$accommodation->id}/revisions/{$revision->id}/";

        foreach ($gallery as $entry) {
            if (! is_array($entry)) {
                throw ValidationException::withMessages([
                    'images' => 'The proposed gallery contains an invalid image entry.',
                ]);
            }

            $key = $entry['key'] ?? null;
            $imagePath = $entry['image_path'] ?? null;
            $thumbnailPath = $entry['thumbnail_path'] ?? null;

            if (
                ! is_string($key) ||
                $key === '' ||
                ! is_string($imagePath) ||
                $imagePath === '' ||
                ! is_string($thumbnailPath) ||
                $thumbnailPath === ''
            ) {
                throw ValidationException::withMessages([
                    'images' => 'The proposed gallery contains an invalid image.',
                ]);
            }

            /*
             * Prevent duplicate references and image paths.
             */
            if (
                isset($seenKeys[$key]) ||
                isset($seenPaths[$imagePath])
            ) {
                throw ValidationException::withMessages([
                    'images' => 'The proposed gallery contains a duplicate photo.',
                ]);
            }

            $seenKeys[$key] = true;
            $seenPaths[$imagePath] = true;

            if (! empty($entry['is_primary'])) {
                $primaryCount++;
            }

            /*
             * Existing published photo.
             */
            if (str_starts_with($key, 'existing:')) {
                $idPart = substr(
                    $key,
                    strlen('existing:')
                );

                if (
                    ! ctype_digit($idPart) ||
                    (int) $idPart <= 0
                ) {
                    throw ValidationException::withMessages([
                        'images' => 'The proposed gallery contains an invalid existing image ID.',
                    ]);
                }

                $imageId = (int) $idPart;
                $image = $existingImages->get($imageId);

                if (
                    ! $image ||
                    $image->image_path !== $imagePath ||
                    $image->thumbnail_path !== $thumbnailPath
                ) {
                    throw ValidationException::withMessages([
                        'images' => 'The proposed gallery contains an invalid existing photo.',
                    ]);
                }

                $retainedIds[] = $imageId;

                continue;
            }

            /*
             * Newly uploaded revision photo.
             */
            if (! str_starts_with($key, 'new:')) {
                throw ValidationException::withMessages([
                    'images' => 'The proposed gallery contains an invalid image reference.',
                ]);
            }

            /*
             * Verify original image and thumbnail paths.
             */
            if (
                ! $this->isRevisionImagePath(
                    $imagePath,
                    $expectedDirectory,
                    false
                ) ||
                ! $this->isRevisionImagePath(
                    $thumbnailPath,
                    $expectedDirectory,
                    true
                )
            ) {
                throw ValidationException::withMessages([
                    'images' => 'A proposed new photo has an invalid revision storage path.',
                ]);
            }

            /*
             * Both files must belong to the same image UUID.
             */
            $originalFilename = basename($imagePath);
            $thumbnailFilename = basename($thumbnailPath);

            if ($originalFilename !== $thumbnailFilename) {
                throw ValidationException::withMessages([
                    'images' => 'The original photo and thumbnail do not match.',
                ]);
            }

            /*
             * Verify the actual files exist on the public disk.
             */
            if (
                ! Storage::disk('public')->exists($imagePath) ||
                ! Storage::disk('public')->exists($thumbnailPath)
            ) {
                throw ValidationException::withMessages([
                    'images' => 'A proposed photo or thumbnail is missing from storage.',
                ]);
            }
        }

        /*
         * Exactly one cover photo is required.
         */
        if ($primaryCount !== 1) {
            throw ValidationException::withMessages([
                'images' => 'Select exactly one cover photo.',
            ]);
        }

        /*
         * Apply approved data and gallery in one
         * database transaction.
         */
        DB::transaction(function () use (
            $accommodation,
            $revision,
            $safeData,
            $gallery,
            $retainedIds,
            $request
        ) {
            $accommodation->update($safeData);

            /*
             * Remove published image database records
             * excluded from the approved gallery.
             *
             * Physical files are not deleted here.
             */
            $accommodation->images()
                ->whereNotIn('id', $retainedIds)
                ->delete();

            foreach ($gallery as $order => $entry) {
                $isPrimary = ! empty($entry['is_primary']);

                if (str_starts_with($entry['key'], 'existing:')) {
                    $imageId = (int) substr(
                        $entry['key'],
                        strlen('existing:')
                    );

                    $accommodation->images()
                        ->where('id', $imageId)
                        ->update([
                            'sort_order' => $order,
                            'is_primary' => $isPrimary,
                        ]);
                } else {
                    AccommodationImage::create([
                        'accommodation_id' => $accommodation->id,
                        'image_path' => $entry['image_path'],
                        'thumbnail_path' => $entry['thumbnail_path'],
                        'sort_order' => $order,
                        'is_primary' => $isPrimary,
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
            'message' => 'Revision approved and published successfully.',
            'accommodation' => $accommodation->fresh('images'),
            'revision' => $revision->fresh(),
        ]);
    }

    /*
     * Validate the image path generated by
     * ImageOptimizationService.
     *
     * Original:
     * accommodations/{id}/revisions/{revisionId}/UUID.webp
     *
     * Thumbnail:
     * accommodations/{id}/revisions/{revisionId}/thumbnails/UUID.webp
     */
    private function isRevisionImagePath(
        string $path,
        string $expectedDirectory,
        bool $isThumbnail
    ): bool {
        $normalized = str_replace('\\', '/', trim($path));

        if (
            $normalized === '' ||
            str_contains($normalized, '..') ||
            str_contains($normalized, '?') ||
            str_contains($normalized, '#') ||
            str_contains($normalized, '://') ||
            str_contains($normalized, "\0")
        ) {
            return false;
        }

        /*
         * ImageOptimizationService returns relative paths.
         * Do not accept absolute paths or URL prefixes.
         */
        if (
            str_starts_with($normalized, '/') ||
            str_starts_with($normalized, 'storage/') ||
            str_starts_with($normalized, 'public/')
        ) {
            return false;
        }

        $directory = $expectedDirectory;

        if ($isThumbnail) {
            $directory .= 'thumbnails/';
        }

        if (! str_starts_with($normalized, $directory)) {
            return false;
        }

        $filename = substr(
            $normalized,
            strlen($directory)
        );

        /*
         * Match UUID.webp filenames generated by
         * ImageOptimizationService.
         */
        return (bool) preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\.webp$/i',
            $filename
        );
    }

    public function destroy(
        int $id,
        ImageOptimizationService $images
    ): JsonResponse {
        $accommodation = Accommodation::findOrFail($id);

        foreach ($accommodation->images as $img) {
            $images->delete(
                $img->image_path,
                $img->thumbnail_path
            );
        }

        $accommodation->delete();

        return response()->json([
            'message' => 'Accommodation deleted.',
        ]);
    }
}

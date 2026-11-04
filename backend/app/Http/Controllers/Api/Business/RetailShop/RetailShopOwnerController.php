<?php

namespace App\Http\Controllers\Api\Business\RetailShop;

use App\Http\Controllers\Concerns\AppliesMinorEdits;
use App\Http\Controllers\Concerns\PlansPhotoArrangement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpdateRetailShopRequest;
use App\Models\RetailShop;
use App\Models\RetailShopImage;
use App\Models\RetailShopRevision;
use App\Services\ImageOptimizationService;
use App\Support\RevisionBase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetailShopOwnerController extends Controller
{
    use AppliesMinorEdits, PlansPhotoArrangement;

    public const MIN_GALLERY_IMAGES = 5;
    public const MAX_GALLERY_IMAGES = 100;

    public function show(Request $request): JsonResponse
    {
        return response()->json(
            $this->ownerView($this->ownedShop($request))
        );
    }

    public function update(
        UpdateRetailShopRequest $request,
        ImageOptimizationService $images
    ): JsonResponse {
        $shop = $this->ownedShop($request);
        $validated = $request->validated();

        $uploads = $request->file('images', []);
        $uploads = is_array($uploads) ? $uploads : [$uploads];
        $uploads = array_values(array_filter($uploads));

        $proposed = array_intersect_key($validated, array_flip($this->editableFields()));

        if ($shop->status === 'approved') {
            // Declared + verified spelling/formatting fixes go live directly; anything
            // else (or while a revision is open) is held for review below.
            $blocked = $uploads || $this->hasPhotoArrangement($request) || $this->openRevision($shop);
            if ($this->applyMinorEdit($shop, $proposed, $request, $blocked)) {
                return $this->ownerResponse($shop, 'Minor correction published. It was recorded for the Tourism Office.');
            }

            $revision = $this->editableRevision($shop, $request);
            $gallery = $this->revisionGallery($shop, $revision);
            $byToken = [];
            $cover = null;
            foreach ($gallery as $entry) {
                $token = 'e:'.$this->galleryEntryId($entry);
                $byToken[$token] = $entry;
                $cover = ! empty($entry['is_primary']) ? $token : $cover;
            }
            $plan = $this->planPhotoArrangement(array_keys($byToken), $cover, count($uploads), $request);

            if (count($plan['order']) > self::MAX_GALLERY_IMAGES) {
                throw ValidationException::withMessages([
                    'images' => 'The gallery can contain a maximum of 100 photos.',
                ]);
            }

            $data = $revision->proposed_data
                ?: $this->publishedData($shop);

            foreach ($proposed as $field => $value) {
                $data[$field] = $value;
            }

            $stored = [];

            try {
                foreach ($uploads as $index => $upload) {
                    $paths = $images->store(
                        $upload,
                        "retail-shops/{$shop->id}/revisions/{$revision->id}"
                    );

                    $stored[] = $paths;

                    $byToken["n:{$index}"] = [
                        'key' => 'new:'.count($stored).':'.uniqid('', true),
                        'image_path' => $paths['image_path'],
                        'thumbnail_path' => $paths['thumbnail_path'],
                        'is_primary' => false,
                    ];
                }

                $arranged = array_map(
                    fn ($token) => array_merge($byToken[$token], ['is_primary' => $token === $plan['cover']]),
                    $plan['order']
                );

                $revision->update([
                    'proposed_data' => $data,
                    'proposed_gallery' => $this->ensureCover($arranged),
                ]);
            } catch (\Throwable $exception) {
                foreach ($stored as $paths) {
                    $images->delete(
                        $paths['image_path'],
                        $paths['thumbnail_path']
                    );
                }

                throw $exception;
            }

            // Staged (never published) photos removed from the draft can be deleted now.
            foreach ($plan['removed'] as $token) {
                if (str_starts_with($byToken[$token]['key'], 'new:')) {
                    $images->delete($byToken[$token]['image_path'], $byToken[$token]['thumbnail_path']);
                }
            }

            return $this->ownerResponse(
                $shop,
                'Changes saved to your pending draft. The published listing is unchanged.'.$this->minorEditRejectedNote($request)
            );
        }

        $current = $shop->images()->orderBy('sort_order')->orderBy('id')->get()->keyBy(fn ($image) => 'e:'.$image->id);
        $plan = $this->planPhotoArrangement(
            $current->keys()->all(),
            $current->search(fn ($image) => (bool) $image->is_primary) ?: null,
            count($uploads),
            $request
        );

        if (count($plan['order']) > self::MAX_GALLERY_IMAGES) {
            throw ValidationException::withMessages([
                'images' => 'The gallery can contain a maximum of 100 photos.',
            ]);
        }

        $shop->update($proposed);
        $this->resetInitialReview($shop);

        $stored = [];

        try {
            foreach ($uploads as $index => $upload) {
                $stored["n:{$index}"] = $images->store(
                    $upload,
                    "retail-shops/{$shop->id}"
                );
            }

            // New rows, removals, order and cover succeed or fail together, so the
            // gallery never ends up half-saved or without a cover.
            DB::transaction(function () use ($shop, $plan, $stored, $current) {
                $rows = $current->all();

                foreach ($stored as $token => $paths) {
                    $rows[$token] = RetailShopImage::create([
                        'retail_shop_id' => $shop->id,
                        'image_path' => $paths['image_path'],
                        'thumbnail_path' => $paths['thumbnail_path'],
                        'sort_order' => 0,
                        'is_primary' => false,
                    ]);
                }

                foreach ($plan['removed'] as $token) {
                    $rows[$token]->delete();
                }

                foreach ($plan['order'] as $position => $token) {
                    $rows[$token]->update([
                        'sort_order' => $position,
                        'is_primary' => $token === $plan['cover'],
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            foreach ($stored as $paths) {
                $images->delete($paths['image_path'], $paths['thumbnail_path']);
            }

            throw $exception;
        }

        foreach ($plan['removed'] as $token) {
            $images->delete($current[$token]->image_path, $current[$token]->thumbnail_path);
        }

        return $this->ownerResponse(
            $shop,
            'Shop changes saved.'
        );
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $shop = $this->ownedShop($request);

        $validated = $request->validate([
            'address' => 'required|string|max:500',
            'municipality_id' => 'required|exists:municipalities,id',
        ]);

        if ($shop->status === 'approved') {
            $revision = $this->editableRevision($shop, $request);

            $data = $revision->proposed_data
                ?: $this->publishedData($shop);

            $revision->update([
                'proposed_data' => array_merge($data, $validated),
            ]);

            return $this->ownerResponse(
                $shop,
                'Location saved to your pending draft. Published location is unchanged.'
            );
        }

        $shop->update($validated);
        $this->resetInitialReview($shop);

        return $this->ownerResponse(
            $shop,
            'Location updated.'
        );
    }

    public function destroyImage(
        Request $request,
        int $imageId,
        ImageOptimizationService $images
    ): JsonResponse {
        $shop = $this->ownedShop($request);

        if ($shop->status === 'approved') {
            $revision = $this->editableRevision($shop, $request);
            $gallery = $this->revisionGallery($shop, $revision);

            $index = $this->galleryIndex($gallery, $imageId);

            if ($index === null) {
                abort(404);
            }

            $removed = $gallery[$index];
            array_splice($gallery, $index, 1);

            $revision->update([
                'proposed_gallery' => $this->ensureCover($gallery),
            ]);

            if (str_starts_with($removed['key'], 'new:')) {
                $images->delete(
                    $removed['image_path'],
                    $removed['thumbnail_path']
                );
            }

            return $this->ownerResponse(
                $shop,
                'Photo removed from the pending draft. Published photos are unchanged.'
            );
        }

        $image = $shop->images()
            ->where('id', $imageId)
            ->firstOrFail();

        $wasPrimary = (bool) $image->is_primary;

        $image->delete();

        if (
            $wasPrimary &&
            ($next = $shop->images()->first())
        ) {
            $next->update(['is_primary' => true]);
        }

        $this->resetInitialReview($shop);

        $images->delete(
            $image->image_path,
            $image->thumbnail_path
        );

        return $this->ownerResponse(
            $shop,
            'Image removed.'
        );
    }

    public function setCover(Request $request, int $imageId): JsonResponse
    {
        $shop = $this->ownedShop($request);

        if ($shop->status === 'approved') {
            $revision = $this->editableRevision($shop, $request);
            $gallery = $this->revisionGallery($shop, $revision);

            $index = $this->galleryIndex($gallery, $imageId);

            if ($index === null) {
                abort(404);
            }

            foreach ($gallery as $i => &$entry) {
                $entry['is_primary'] = $i === $index;
            }

            unset($entry);

            $revision->update([
                'proposed_gallery' => $gallery,
            ]);

            return $this->ownerResponse(
                $shop,
                'Draft cover updated. Published cover is unchanged.'
            );
        }

        $image = $shop->images()
            ->where('id', $imageId)
            ->firstOrFail();

        DB::transaction(function () use ($shop, $image) {
            $shop->images()->update(['is_primary' => false]);
            $image->update(['is_primary' => true]);
        });

        $this->resetInitialReview($shop);

        return $this->ownerResponse(
            $shop,
            'Cover photo updated.'
        );
    }

    public function reorderImages(Request $request): JsonResponse
    {
        $shop = $this->ownedShop($request);

        $validated = $request->validate([
            'image_ids' => 'required|array|min:1|max:100',
            'image_ids.*' => 'required|integer|distinct',
        ]);

        $ids = array_map('intval', $validated['image_ids']);

        if ($shop->status === 'approved') {
            $revision = $this->editableRevision($shop, $request);
            $gallery = $this->revisionGallery($shop, $revision);

            $byId = [];

            foreach ($gallery as $entry) {
                $byId[$this->galleryEntryId($entry)] = $entry;
            }

            if (
                count($ids) !== count($gallery) ||
                array_diff($ids, array_keys($byId))
            ) {
                throw ValidationException::withMessages([
                    'image_ids' => 'Provide all and only the draft gallery image IDs.',
                ]);
            }

            $revision->update([
                'proposed_gallery' => array_map(
                    fn ($id) => $byId[$id],
                    $ids
                ),
            ]);

            return $this->ownerResponse(
                $shop,
                'Draft gallery order updated. Published order is unchanged.'
            );
        }

        $existingIds = $shop->images()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        $submittedIds = collect($ids)
            ->sort()
            ->values()
            ->all();

        if ($existingIds !== $submittedIds) {
            throw ValidationException::withMessages([
                'image_ids' => 'Provide all and only the image IDs belonging to this shop.',
            ]);
        }

        DB::transaction(function () use ($shop, $ids) {
            foreach ($ids as $order => $id) {
                $shop->images()
                    ->where('id', $id)
                    ->update(['sort_order' => $order]);
            }
        });

        $this->resetInitialReview($shop);

        return $this->ownerResponse(
            $shop,
            'Gallery order updated.'
        );
    }

    public function submitForReview(Request $request): JsonResponse
    {
        $shop = $this->ownedShop($request);

        if ($shop->status === 'approved') {
            $revision = $this->editableRevision($shop, $request);
            $gallery = $this->revisionGallery($shop, $revision);

            $this->validateGalleryCount(count($gallery));

            $revision->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'admin_notes' => null,
            ]);

            return $this->ownerResponse(
                $shop,
                'Changes submitted for Tourism Office review. The approved listing remains published.'
            );
        }

        if ($shop->publication_review_status === 'submitted') {
            return response()->json([
                'message' => 'This listing has already been submitted for review.',
            ], 409);
        }

        $count = $shop->images()->count();
        $this->validateGalleryCount($count);

        $shop->update([
            'status' => 'pending',
            'publication_review_status' => 'submitted',
            'admin_notes' => null,
            'approved_at' => null,
            'approved_by' => null,
        ]);

        return $this->ownerResponse(
            $shop,
            'Listing submitted for Tourism Office review.'
        );
    }

    private function editableFields(): array
    {
        return [
            'name',
            'description',
        ];
    }

    private function publishedData(RetailShop $shop): array
    {
        return $shop->only(
            array_merge(
                $this->editableFields(),
                ['address', 'municipality_id']
            )
        );
    }

    private function ownedShop(Request $request): RetailShop
    {
        return RetailShop::where(
            'user_id',
            $request->user()->id
        )->firstOrFail();
    }

    private function editableRevision(
        RetailShop $shop,
        Request $request
    ): RetailShopRevision {
        $revision = $shop->revisions()
            ->whereIn('status', [
                'draft',
                'needs_revision',
                'submitted',
            ])
            ->latest('id')
            ->first();

        if ($revision && $revision->status === 'submitted') {
            throw ValidationException::withMessages([
                'revision' => 'Your changes are under review. Wait for an admin decision before editing again.',
            ]);
        }

        if (! $revision) {
            $revision = $shop->revisions()->create([
                'submitted_by' => $request->user()->id,
                'proposed_data' => $this->publishedData($shop),
                'proposed_gallery' => $this->publishedGallery($shop),
                'status' => 'draft',
            ]);
            // What was published when editing started; approval uses it to avoid
            // overwriting anything that changed on the live listing since.
            $revision->forceFill([
                'base_snapshot' => RevisionBase::snapshot($shop, array_keys($this->publishedData($shop))),
            ])->save();
        }

        return $revision;
    }

    private function openRevision(RetailShop $shop): bool
    {
        return $shop->revisions()->whereIn('status', ['draft', 'needs_revision', 'submitted'])->exists();
    }

    private function publishedGallery(RetailShop $shop): array
    {
        return $shop->images()
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($image) => [
                'key' => 'existing:'.$image->id,
                'image_path' => $image->image_path,
                'thumbnail_path' => $image->thumbnail_path,
                'is_primary' => (bool) $image->is_primary,
            ])
            ->all();
    }

    private function revisionGallery(
        RetailShop $shop,
        RetailShopRevision $revision
    ): array {
        return $revision->proposed_gallery
            ?? $this->publishedGallery($shop);
    }

    private function galleryEntryId(array $entry): int
    {
        if (str_starts_with($entry['key'], 'existing:')) {
            return (int) substr(
                $entry['key'],
                strlen('existing:')
            );
        }

        return -((int) sprintf('%u', crc32($entry['key'])) + 1);
    }

    private function galleryIndex(array $gallery, int $imageId): ?int
    {
        foreach ($gallery as $index => $entry) {
            if ($this->galleryEntryId($entry) === $imageId) {
                return $index;
            }
        }

        return null;
    }

    private function ensureCover(array $gallery): array
    {
        $found = false;

        foreach ($gallery as &$entry) {
            if (! $found && ! empty($entry['is_primary'])) {
                $found = true;
            } else {
                $entry['is_primary'] = false;
            }
        }

        unset($entry);

        if (! $found && count($gallery)) {
            $gallery[0]['is_primary'] = true;
        }

        return array_values($gallery);
    }

    private function validateGalleryCount(int $count): void
    {
        if (
            $count < self::MIN_GALLERY_IMAGES ||
            $count > self::MAX_GALLERY_IMAGES
        ) {
            throw ValidationException::withMessages([
                'images' => 'Upload between 5 and 100 gallery photos before submitting for review.',
            ]);
        }
    }

    private function resetInitialReview(RetailShop $shop): void
    {
        if ($shop->publication_review_status === 'submitted') {
            $shop->update([
                'publication_review_status' => 'draft',
            ]);
        }
    }

    private function ownerView(RetailShop $shop): RetailShop
    {
        $shop->load([
            'municipality',
            'barangay',
            'images',
            'products.images',
        ]);
        // The owner sees proposed changes to their own approved products.
        $shop->products->each->makeVisible('pending_changes');

        if ($shop->status !== 'approved') {
            return $shop;
        }

        $revision = $shop->revisions()
            ->whereIn('status', [
                'draft',
                'submitted',
                'needs_revision',
            ])
            ->latest('id')
            ->first();

        if (! $revision) {
            return $shop;
        }

        $shop->setAttribute('owner_revision', [
            'id' => $revision->id,
            'status' => $revision->status,
            'admin_notes' => $revision->admin_notes,
            'proposed_data' => $revision->proposed_data,
            'proposed_gallery' => array_map(
                function ($entry) {
                    return array_merge($entry, [
                        'id' => $this->galleryEntryId($entry),
                    ]);
                },
                $this->revisionGallery($shop, $revision)
            ),
        ]);

        return $shop;
    }

    private function ownerResponse(
        RetailShop $shop,
        string $message
    ): JsonResponse {
        $view = $this->ownerView($shop->fresh());
        $revision = $view->getAttribute('owner_revision');

        $count = $revision
            ? count($revision['proposed_gallery'])
            : $view->images->count();

        return response()->json([
            'message' => $message,
            'gallery_count' => $count,
            'gallery_minimum' => self::MIN_GALLERY_IMAGES,
            'gallery_maximum' => self::MAX_GALLERY_IMAGES,
            'shop' => $view,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\RetailShop;
use App\Models\RetailShopImage;
use App\Models\RetailShopRevision;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\AdminScope;
use App\Support\RevisionBase;
use Illuminate\Validation\ValidationException;

class AdminRetailShopController extends Controller
{
    public const MIN_GALLERY_IMAGES = 5;
    public const MAX_GALLERY_IMAGES = 100;

    public function index(Request $request): JsonResponse
    {
        $query = AdminScope::apply(RetailShop::query(), $request->user())->with([
            'municipality',
            'barangay',
            'images',
            'user:id,name',
            'revisions' => fn ($query) => $query
                ->where('status', 'submitted')
                ->latest('id'),
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('publication_review_status')) {
            $query->where(
                'publication_review_status',
                $request->input('publication_review_status')
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

    public function show(Request $request, int $id): JsonResponse
    {
        $shop = RetailShop::with([
            'municipality',
            'barangay',
            'images',
            'user:id,name',
            'products.images',
            'revisions' => fn ($query) => $query
                ->whereIn('status', [
                    'draft',
                    'submitted',
                    'needs_revision',
                ])
                ->latest('id'),
        ])->findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $shop);

        return response()->json([
            'data' => $shop,
        ]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $shop = RetailShop::pending()->findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $shop);

        if ($shop->publication_review_status !== 'submitted') {
            throw ValidationException::withMessages([
                'publication_review_status' => 'The business owner must submit the completed shop listing for publication review before approval.',
            ]);
        }

        $imageCount = $shop->images()->count();

        if (
            $imageCount < self::MIN_GALLERY_IMAGES ||
            $imageCount > self::MAX_GALLERY_IMAGES
        ) {
            throw ValidationException::withMessages([
                'images' => 'A retail shop must have between 5 and 100 gallery photos before publication.',
            ]);
        }

        $shop->update([
            'status' => 'approved',
            'publication_review_status' => 'published',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'admin_notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'message' => 'Shop approved and published.',
            'gallery_count' => $imageCount,
            'shop' => $shop->fresh([
                'municipality',
                'barangay',
                'images',
                'products.images',
            ]),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $shop = RetailShop::pending()->findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $shop);

        if ($shop->publication_review_status !== 'submitted') {
            throw ValidationException::withMessages([
                'publication_review_status' => 'Only submitted shop listings can be rejected during publication review.',
            ]);
        }

        $shop->update([
            'status' => 'rejected',
            'publication_review_status' => 'needs_revision',
            'admin_notes' => $validated['notes'],
            'approved_at' => null,
            'approved_by' => null,
        ]);

        return response()->json([
            'message' => 'Shop rejected. The business owner may revise and resubmit the listing.',
            'shop' => $shop->fresh([
                'municipality',
                'barangay',
                'images',
                'products.images',
            ]),
        ]);
    }

    public function approveRevision(
        Request $request,
        int $id,
        ImageOptimizationService $images
    ): JsonResponse {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $shop = RetailShop::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $shop);

        if ($shop->status !== 'approved') {
            throw ValidationException::withMessages([
                'revision' => 'Only published shops can have revisions approved.',
            ]);
        }

        $revision = $shop->revisions()
            ->where('status', 'submitted')
            ->latest('id')
            ->firstOrFail();

        $gallery = $revision->proposed_gallery ?? [];
        $proposedData = $revision->proposed_data ?? [];

        // Never overwrite values that changed on the live listing since the owner started editing.
        AdminScope::authorizeTransfer($request->user(), $shop, $proposedData['municipality_id'] ?? null);
        if ($conflict = RevisionBase::prepareForApproval($revision, $shop, $request->user()->id, $proposedData, $gallery)) {
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

        $existingImages = $shop->images()
            ->get()
            ->keyBy('id');

        $retainedIds = [];

        foreach ($gallery as $entry) {
            $key = $entry['key'] ?? '';

            if (str_starts_with($key, 'existing:')) {
                $imageId = (int) substr(
                    $key,
                    strlen('existing:')
                );

                if (! $existingImages->has($imageId)) {
                    throw ValidationException::withMessages([
                        'images' => 'A proposed photo no longer exists in this shop.',
                    ]);
                }

                $retainedIds[] = $imageId;
            } elseif (! str_starts_with($key, 'new:')) {
                throw ValidationException::withMessages([
                    'images' => 'The proposed gallery contains an invalid photo reference.',
                ]);
            }
        }

        $removedImages = $existingImages
            ->except($retainedIds)
            ->values();

        DB::transaction(function () use (
            $shop,
            $revision,
            $gallery,
            $existingImages,
            $retainedIds,
            $validated,
            $request,
            $proposedData
        ) {

            $allowedFields = [
                'name',
                'description',
                'address',
                'municipality_id',
            ];

            $updates = array_intersect_key(
                $proposedData,
                array_flip($allowedFields)
            );

            if (! empty($updates)) {
                $shop->update($updates);
            }

            $shop->images()
                ->whereNotIn('id', $retainedIds)
                ->delete();

            foreach ($gallery as $order => $entry) {
                $key = $entry['key'];

                if (str_starts_with($key, 'existing:')) {
                    $imageId = (int) substr(
                        $key,
                        strlen('existing:')
                    );

                    $existingImages[$imageId]->update([
                        'sort_order' => $order,
                        'is_primary' => (bool) ($entry['is_primary'] ?? false),
                    ]);
                } else {
                    RetailShopImage::create([
                        'retail_shop_id' => $shop->id,
                        'image_path' => $entry['image_path'],
                        'thumbnail_path' => $entry['thumbnail_path'] ?? null,
                        'sort_order' => $order,
                        'is_primary' => (bool) ($entry['is_primary'] ?? false),
                    ]);
                }
            }

            $revision->update([
                'status' => 'approved',
                'admin_notes' => $validated['notes'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $shop->update([
                'status' => 'approved',
                'publication_review_status' => 'published',
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
            ]);
        });

        foreach ($removedImages as $image) {
            $images->delete(
                $image->image_path,
                $image->thumbnail_path
            );
        }

        return response()->json([
            'message' => 'Shop revision approved. The published listing has been updated.',
            'shop' => $shop->fresh([
                'municipality',
                'barangay',
                'images',
                'products.images',
            ]),
        ]);
    }

    public function requestRevisionChanges(
        Request $request,
        int $id
    ): JsonResponse {
        $validated = $request->validate([
            'notes' => 'required|string|max:1000',
        ]);

        $shop = RetailShop::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $shop);

        if ($shop->status !== 'approved') {
            throw ValidationException::withMessages([
                'revision' => 'Only published shops can have revision feedback.',
            ]);
        }

        $revision = $shop->revisions()
            ->where('status', 'submitted')
            ->latest('id')
            ->firstOrFail();

        $revision->update([
            'status' => 'needs_revision',
            'admin_notes' => $validated['notes'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Changes requested. The published shop remains unchanged.',
            'revision' => $revision->fresh(),
            'shop' => $shop->fresh([
                'municipality',
                'barangay',
                'images',
                'products.images',
            ]),
        ]);
    }
}

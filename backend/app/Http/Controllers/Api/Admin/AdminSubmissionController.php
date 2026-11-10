<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Mail\ListingWarningMail;
use App\Mail\SubmissionStatusMail;
use App\Models\Accommodation;
use App\Models\ActivityLog;
use App\Models\BusinessSubmission;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\RetailShop;
use App\Services\ImageOptimizationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminSubmissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BusinessSubmission::with(['user:id,name,email', 'municipality', 'barangay']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = $this->perPage($request, 100, 200);

        $submissions = $query->orderByDesc('created_at')->paginate($perPage);
        $this->attachReviewStats($submissions->getCollection());

        return response()->json($submissions);
    }

    /** Adds reviews_count, negative_reviews_count and average_rating to approved submissions' live listings. */
    private function attachReviewStats(Collection $submissions): void
    {
        $submissions
            ->filter(fn ($s) => $s->status === 'approved' && $s->listing_id)
            ->groupBy('type')
            ->each(function (Collection $group, string $type) {
                $stats = Rating::where('rateable_type', $this->listingModel($type))
                    ->whereIn('rateable_id', $group->pluck('listing_id'))
                    ->groupBy('rateable_id')
                    ->selectRaw('rateable_id, COUNT(*) as total, SUM(CASE WHEN score <= 2 THEN 1 ELSE 0 END) as negative, AVG(score) as average')
                    ->get()
                    ->keyBy('rateable_id');

                foreach ($group as $submission) {
                    $row = $stats->get($submission->listing_id);
                    $submission->setAttribute('reviews_count', (int) ($row->total ?? 0));
                    $submission->setAttribute('negative_reviews_count', (int) ($row->negative ?? 0));
                    $submission->setAttribute('average_rating', $row ? round((float) $row->average, 1) : null);
                }
            });
    }

    /** @return class-string<Accommodation|FoodBeverage|RetailShop> */
    private function listingModel(string $type): string
    {
        return match ($type) {
            'accommodation' => Accommodation::class,
            'food_beverage' => FoodBeverage::class,
            'retail_product' => RetailShop::class,
            default => abort(422, "Unsupported submission type: {$type}"),
        };
    }

    public function downloadPermit(int $id): StreamedResponse
    {
        $submission = BusinessSubmission::findOrFail($id);

        abort_unless($submission->business_permit_path, 404);

        return Storage::disk('local')->response($submission->business_permit_path);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $submission = DB::transaction(function () use ($request, $id) {
            $submission = BusinessSubmission::where('id', $id)->lockForUpdate()->findOrFail($id);

            if ($submission->status !== 'pending') {
                abort(409, 'This submission has already been reviewed.');
            }

            $submission->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'admin_notes' => $request->input('notes'),
            ]);

            $listingData = [
                'user_id' => $submission->user_id,
                'name' => $submission->business_name,
                'description' => $submission->description,
                'municipality_id' => $submission->municipality_id,
                'barangay_id' => $submission->barangay_id,
                'address' => $submission->address,
                'latitude' => $submission->latitude,
                'longitude' => $submission->longitude,
                'contact_number' => $submission->contact_number,
                'operating_hours' => $submission->operating_hours,
            ];

            $modelClass = $this->listingModel($submission->type);

            $extra = match ($submission->type) {
                'accommodation' => ['type' => 'Accommodation', 'price_range' => 'moderate'],
                'food_beverage' => ['price_range' => 'moderate'],
                'retail_product' => [],
                default => abort(422, "Unsupported submission type: {$submission->type}"),
            };

            $existingListing = $submission->listing_id
                ? $modelClass::find($submission->listing_id)
                : null;

            if ($existingListing) {
                // Preserve the current publication status of existing listings.
                $existingListing->update(array_merge($listingData, $extra));

                $listing = $existingListing;
            } else {
                // Business verification does not automatically publish a listing.
                $listing = $modelClass::create(array_merge(
                    $listingData,
                    $extra,
                    [
                        'status' => 'pending',
                        'approved_at' => null,
                        'approved_by' => null,
                    ]
                ));

                // forceFill allows this field to be saved before the
                // listing models' fillable arrays are updated.
                $listing->forceFill([
                    'publication_review_status' => 'draft',
                    'is_sample' => $submission->is_sample,
                ])->save();

                $submission->update(['listing_id' => $listing->id]);
            }

            if ($submission->image_path) {
                $imageTable = match ($submission->type) {
                    'accommodation' => 'accommodation_images',
                    'food_beverage' => 'food_beverage_images',
                    'retail_product' => 'retail_shop_images',
                    default => abort(422, "Unsupported submission type: {$submission->type}"),
                };

                $foreignKey = match ($submission->type) {
                    'accommodation' => 'accommodation_id',
                    'food_beverage' => 'food_beverage_id',
                    'retail_product' => 'retail_shop_id',
                    default => abort(422, "Unsupported submission type: {$submission->type}"),
                };

                $alreadyHasImage = DB::table($imageTable)
                    ->where($foreignKey, $listing->id)->exists();

                if (! $alreadyHasImage) {
                    DB::table($imageTable)->insert([
                        $foreignKey => $listing->id,
                        'image_path' => $submission->image_path,
                        'thumbnail_path' => $submission->image_path,
                        'is_primary' => true,
                        'sort_order' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            ActivityLog::create([
                'user_id' => $request->user()->id,
                'action' => 'approved_submission',
                'description' => "Approved business submission: {$submission->business_name}",
                'model_type' => BusinessSubmission::class,
                'model_id' => $submission->id,
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);

            return $submission;
        });

        $this->notifySubmissionStatus($submission);

        return response()->json([
            'message' => 'Business verified. Listing is ready for owner completion and publication review.',
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['notes' => 'required|string|max:1000']);

        $submission = DB::transaction(function () use ($request, $id) {
            $submission = BusinessSubmission::where('id', $id)->lockForUpdate()->findOrFail($id);

            if ($submission->status !== 'pending') {
                abort(409, 'This submission has already been reviewed.');
            }

            $submission->update([
                'status' => 'rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'admin_notes' => $request->notes,
            ]);

            return $submission;
        });

        $this->notifySubmissionStatus($submission);

        return response()->json(['message' => 'Submission rejected.']);
    }

    public function warn(Request $request, int $id): JsonResponse
    {
        $request->validate(['notes' => 'nullable|string|max:1000']);

        $submission = BusinessSubmission::findOrFail($id);

        if ($submission->status !== 'approved') {
            abort(409, 'Only approved businesses can be warned.');
        }

        $submission->update([
            'warned_at' => now(),
            'warning_notes' => $request->input('notes'),
        ]);

        $this->logAction(
            $request,
            'warned_submission',
            "Warned business owner about negative reviews: {$submission->business_name}",
            $submission
        );

        $submission->loadMissing('user');

        if ($submission->user?->email) {
            try {
                Mail::to($submission->user->email)->send(new ListingWarningMail($submission));
            } catch (\Throwable $e) {
                Log::error('Failed to send listing warning email', [
                    'submission_id' => $submission->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Warning sent to the business owner.',
            'submission' => $submission,
        ]);
    }

    public function clearWarning(Request $request, int $id): JsonResponse
    {
        $submission = BusinessSubmission::findOrFail($id);

        $submission->update(['warned_at' => null, 'warning_notes' => null]);

        $this->logAction(
            $request,
            'cleared_submission_warning',
            "Cleared negative-review warning: {$submission->business_name}",
            $submission
        );

        return response()->json(['message' => 'Warning cleared.']);
    }

    public function remove(Request $request, int $id, ImageOptimizationService $images): JsonResponse
    {
        $request->validate(['notes' => 'required|string|max:1000']);

        $submission = DB::transaction(function () use ($request, $id, $images) {
            $submission = BusinessSubmission::where('id', $id)->lockForUpdate()->findOrFail($id);

            if ($submission->status !== 'approved') {
                abort(409, 'Only approved businesses can be removed.');
            }

            $listing = $submission->listing_id
                ? $this->listingModel($submission->type)::find($submission->listing_id)
                : null;

            if ($listing) {
                $this->deleteListing($listing, $images, $submission->image_path);
            }

            $submission->update([
                'status' => 'removed',
                'listing_id' => null,
                'admin_notes' => $request->notes,
                'warned_at' => null,
                'warning_notes' => null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $this->logAction(
                $request,
                'removed_submission',
                "Removed business from the system: {$submission->business_name}",
                $submission
            );

            return $submission;
        });

        $this->notifySubmissionStatus($submission);

        return response()->json(['message' => 'Business removed from the system.']);
    }

    /**
     * Deletes a listing with its reviews and stored files.
     * Child rows cascade in the database, but stored files
     * and polymorphic ratings must be cleaned up separately.
     */
    private function deleteListing(
        Model $listing,
        ImageOptimizationService $images,
        ?string $keepPath
    ): void {
        $deleteFiles = function (iterable $rows) use ($images, $keepPath) {
            foreach ($rows as $row) {
                if ($row->image_path !== $keepPath) {
                    $images->delete(
                        $row->image_path,
                        $row->thumbnail_path === $keepPath
                            ? null
                            : $row->thumbnail_path
                    );
                }
            }
        };

        $deleteFiles($listing->images);

        if ($listing instanceof Accommodation) {
            $listing->rooms->each(fn ($room) => $deleteFiles($room->images));
        } elseif ($listing instanceof FoodBeverage) {
            $deleteFiles($listing->menuItems);
        } elseif ($listing instanceof RetailShop) {
            foreach ($listing->products as $product) {
                $deleteFiles($product->images);
                $product->ratings()->delete();
            }
        }

        $listing->ratings()->delete();
        $listing->delete();
    }

    private function logAction(
        Request $request,
        string $action,
        string $description,
        BusinessSubmission $submission
    ): void {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'description' => $description,
            'model_type' => BusinessSubmission::class,
            'model_id' => $submission->id,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    private function notifySubmissionStatus(BusinessSubmission $submission): void
    {
        $submission->loadMissing('user');

        if (! $submission->user?->email) {
            return;
        }

        try {
            Mail::to($submission->user->email)->send(new SubmissionStatusMail($submission));
        } catch (\Throwable $e) {
            Log::error('Failed to send submission status email', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

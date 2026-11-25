<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\ActivityLog;
use App\Models\Destination;
use App\Models\DestinationImage;
use App\Models\ExperienceTag;
use App\Services\ImageOptimizationService;
use App\Support\AdminScope;
use App\Support\InfoValue;
use Database\Seeders\ExperienceTagSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminDestinationController extends Controller
{
    private const MESSAGES = [
        'dining_details.required_with' => "Say what's served here, or untick the meals.",
    ];

    public function index(Request $request): JsonResponse
    {
        $query = AdminScope::apply(Destination::query(), $request->user())->with([
            'category',
            'municipality',
            'barangay',
            'images',
            'fees',
            'rates',
            'amenities',
            'activities',
            'experienceTags',
            'experiencesReviewer:id,name',
            'accommodation:id,name',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('q')) {
            $query->where('name', 'like', "%{$request->q}%");
        }

        $perPage = $this->perPage($request, 100, 200);

        $page = $query->orderByDesc('created_at')->paginate($perPage);
        AdminScope::markManageable($page->getCollection(), $request->user());
        $page->getCollection()->each(fn (Destination $destination) => $this->withReviewFields($destination));

        return response()->json($page);
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
            ...$this->experienceRules(),

            'contact_number' => 'nullable|string|max:50',
            'website' => 'nullable|string|max:500',
            'status' => 'required|in:draft,published',
            'is_featured' => 'boolean',
            'tourism_priority' => 'nullable|in:'.implode(',', Destination::PRIORITIES),
            'setting' => 'nullable|in:'.implode(',', Destination::SETTINGS),
            // The same place's accommodation listing (a resort listed both ways).
            'accommodation_id' => 'nullable|integer',
            // Food sold at the place itself: what tourists read, and the meals the trip planner serves there.
            'dining_details' => 'nullable|string|max:1000|required_with:meals_offered',
            'meals_offered' => 'sometimes|array',
            'meals_offered.*' => 'in:'.implode(',', Destination::MEALS),
            'meals_present' => 'sometimes|boolean',
            'outside_food_allowed' => 'nullable|boolean',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
            // Which of the uploaded photos (0-based) is the cover; defaults to the first.
            'cover_new_index' => 'nullable|integer|min:0',
        ], self::MESSAGES);
        // A municipal admin adds destinations to their own municipality only.
        AdminScope::authorize($request->user(), (int) $validated['municipality_id']);
        AdminScope::guardProvincialFields($request->user(), $validated, null);
        $this->checkAccommodationLink($validated['accommodation_id'] ?? null, (int) $validated['municipality_id'], null);
        $validated = $this->withMeals($validated);
        $experiences = $this->experienceInput($request, $validated, null);

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
            $validated['activities_present'],
            $validated['experiences_present'],
            $validated['experience_tags'],
            $validated['experiences_reviewed']
        );


        $destination = DB::transaction(function () use ($validated, $details, $request, $experiences) {
            $destination = Destination::create([
                ...$validated,
                'created_by' => $request->user()->id,
            ]);
            $this->saveExperiences($destination, $experiences, $request);
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
            'destination' => $this->withReviewFields($destination->load(['images', 'fees', 'rates', 'amenities', 'activities', 'experienceTags', 'experiencesReviewer:id,name'])),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $destination = Destination::with([
            'category',
            'municipality',
            'barangay',
            'images',
            'fees',
            'rates',
            'amenities',
            'activities',
            'experienceTags',
            'experiencesReviewer:id,name',
        ])->findOrFail($id);
        AdminScope::authorizeRecordView($request->user(), $destination);
        AdminScope::markManageable([$destination], $request->user());

        return response()->json($this->withReviewFields($destination));
    }

    public function update(Request $request, int $id, ImageOptimizationService $images): JsonResponse
    {
        $destination = Destination::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $destination);

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
            ...$this->experienceRules(),

            'contact_number' => 'nullable|string|max:50',
            'website' => 'nullable|string|max:500',
            'status' => 'sometimes|in:draft,published,archived',
            'is_featured' => 'boolean',
            'tourism_priority' => 'nullable|in:'.implode(',', Destination::PRIORITIES),
            'setting' => 'nullable|in:'.implode(',', Destination::SETTINGS),
            // The same place's accommodation listing (a resort listed both ways).
            'accommodation_id' => 'nullable|integer',
            // Food sold at the place itself: what tourists read, and the meals the trip planner serves there.
            'dining_details' => 'nullable|string|max:1000|required_with:meals_offered',
            'meals_offered' => 'sometimes|array',
            'meals_offered.*' => 'in:'.implode(',', Destination::MEALS),
            'meals_present' => 'sometimes|boolean',
            'outside_food_allowed' => 'nullable|boolean',
            'images' => 'nullable|array|max:10',
            'images.*' => 'image|mimes:jpeg,jpg,png,webp|max:5120',
            'remove_image_ids' => 'sometimes|array',
            'remove_image_ids.*' => 'integer',
            // Cover choice: an existing photo's id, or the 0-based index of a new upload.
            'cover_image_id' => 'nullable|integer',
            'cover_new_index' => 'nullable|integer|min:0',
        ], self::MESSAGES);
        // Moving a destination to another municipality is a provincial decision.
        AdminScope::authorizeTransfer($request->user(), $destination, $validated['municipality_id'] ?? null);
        AdminScope::guardProvincialFields($request->user(), $validated, $destination);
        $validated = $this->withMeals($validated);
        if (array_key_exists('accommodation_id', $validated)) {
            $this->checkAccommodationLink($validated['accommodation_id'], (int) ($validated['municipality_id'] ?? $destination->municipality_id), $destination->id);
        } elseif (isset($validated['municipality_id']) && $destination->accommodation_id && (int) $validated['municipality_id'] !== (int) $destination->municipality_id) {
            // Moved to another municipality: its old town's accommodation is no longer the same place.
            $validated['accommodation_id'] = null;
        }
        $experiences = $this->experienceInput($request, $validated, $destination);

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
        unset($validated['activities'], $validated['activities_present'], $validated['experiences_present'], $validated['experience_tags'], $validated['experiences_reviewed']);


        // Save destination details together in one transaction
        DB::transaction(function () use ($destination, $validated, $details, $experiences, $request) {
            $pricesBefore = $this->priceRows($destination);
            $destination->update($validated);
            $this->saveExperiences($destination, $experiences, $request);

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

            // Fees and rates are saved anew each time: only a real change is a "Last Updated".
            if ($this->priceRows($destination) !== $pricesBefore) {
                $destination->markInfoUpdated();
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
            'destination' => $this->withReviewFields($destination->fresh(['images', 'fees', 'rates', 'amenities', 'activities', 'experienceTags', 'experiencesReviewer:id,name'])),
        ]);
    }

    /**
     * Featured and Tourism priority only (the admin.provincial route): the provincial
     * administrator's ranking of places across the province, for any municipality's destination,
     * including one managed by its own municipal tourism office.
     */
    /**
     * The meals served on site as sent: the form says meals_present even when every box is
     * unticked (an empty list isn't sent), and then none are served there.
     */
    private function withMeals(array $validated): array
    {
        if (! empty($validated['meals_present'])) {
            $validated['meals_offered'] = array_values(array_intersect(Destination::MEALS, $validated['meals_offered'] ?? []));
        }
        unset($validated['meals_present']);

        return $validated;
    }

    /**
     * The approved accommodations a destination may be linked to (the same place listed for its
     * stays), in the admin's municipalities, with the destination each is already linked to.
     */
    public function accommodationOptions(Request $request): JsonResponse
    {
        $towns = AdminScope::municipalityIds($request->user());
        $options = Accommodation::approved()
            ->when($towns !== null, fn ($q) => $q->whereIn('municipality_id', $towns))
            ->with('dayUseDestination:id,name,accommodation_id')
            ->orderBy('name')
            ->get(['id', 'name', 'municipality_id'])
            ->map(fn (Accommodation $stay) => [
                'id' => $stay->id,
                'name' => $stay->name,
                'municipality_id' => $stay->municipality_id,
                'linked_destination_id' => $stay->dayUseDestination?->id,
            ]);

        return response()->json(['data' => $options]);
    }

    /**
     * A destination's accommodation link must name an approved accommodation in the same
     * municipality that no other destination is linked to.
     */
    private function checkAccommodationLink(?int $accommodationId, int $municipalityId, ?int $destinationId): void
    {
        if ($accommodationId === null) {
            return;
        }
        $stay = Accommodation::approved()->find($accommodationId);
        $other = $stay ? Destination::where('accommodation_id', $stay->id)->when($destinationId, fn ($q) => $q->whereKeyNot($destinationId))->first() : null;
        $message = match (true) {
            ! $stay => 'Choose an approved accommodation on SOSOGON.',
            (int) $stay->municipality_id !== $municipalityId => "{$stay->name} is in another municipality. Link an accommodation in the same municipality as this destination.",
            $other !== null => "{$stay->name} is already linked to {$other->name}.",
            default => null,
        };
        if ($message !== null) {
            throw ValidationException::withMessages(['accommodation_id' => $message]);
        }
    }

    public function updateRanking(Request $request, int $id): JsonResponse
    {
        $destination = Destination::findOrFail($id);
        $validated = $request->validate([
            'is_featured' => 'sometimes|boolean',
            'tourism_priority' => 'sometimes|nullable|in:'.implode(',', Destination::PRIORITIES),
        ]);
        $destination->update($validated);

        return response()->json(['message' => 'Destination ranking updated.', 'destination' => $destination->fresh('municipality')]);
    }

    public function setCoverImage(Request $request, int $id, int $imageId): JsonResponse
    {
        $destination = Destination::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $destination);

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


    public function destroyImage(Request $request, int $id, int $imageId, ImageOptimizationService $images): JsonResponse
    {
        $destination = Destination::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $destination);

        /** @var DestinationImage $image */
        $image = $destination->images()->where('id', $imageId)->firstOrFail();

        $images->delete($image->image_path, $image->thumbnail_path);
        $image->delete();

        return response()->json(['message' => 'Image removed.']);
    }

    public function destroy(Request $request, int $id, ImageOptimizationService $images): JsonResponse
    {
        $destination = Destination::findOrFail($id);
        AdminScope::authorizeRecord($request->user(), $destination);

        // Delete images (and their thumbnails) from storage
        foreach ($destination->images as $img) {
            $images->delete($img->image_path, $img->thumbnail_path);
        }
        foreach ($destination->activities as $activity) {
            $images->delete($activity->image_path, $activity->thumbnail_path);
        }

        // Gone for good: its stops leave the trips that included it, and its reviews go with it.
        DB::transaction(function () use ($destination) {
            DB::table('itinerary_items')->where('destination_id', $destination->id)->delete();
            $destination->ratings()->delete();
            $destination->delete();
        });

        return response()->json(['message' => 'Destination deleted.']);
    }

    /** The experience vocabulary for the destination form: every tag with its definition, in order. */
    public function experienceTags(): JsonResponse
    {
        return response()->json(ExperienceTag::ordered()->get(['slug', 'name', 'group', 'description']));
    }

    /**
     * Validation for the destination's experience tags and review mark. Tags are slugs from the
     * vocabulary; experiences_present=1 (sent by the form) means the list is complete, so an
     * empty list clears them.
     */
    private function experienceRules(): array
    {
        return [
            'experiences_present' => 'sometimes|in:0,1',
            'experience_tags' => 'sometimes|array|max:'.count(ExperienceTagSeeder::TAGS),
            'experience_tags.*' => 'string|distinct|exists:experience_tags,slug',
            'experiences_reviewed' => 'sometimes|boolean',
        ];
    }

    /**
     * What this save does to the experience tags, checked before anything is written: null when
     * the request doesn't touch them; otherwise the tag slugs the destination will have and
     * whether it will be reviewed. A destination can only be reviewed with at least one tag, and
     * adding tags never marks it reviewed by itself.
     *
     * @return array{tags: array<int, string>, reviewed: bool}|null
     */
    private function experienceInput(Request $request, array $validated, ?Destination $destination): ?array
    {
        $listed = $request->input('experiences_present') === '1' || array_key_exists('experience_tags', $validated);
        if (! $listed && ! array_key_exists('experiences_reviewed', $validated)) {
            return null;
        }

        $current = $destination?->experienceTags()->pluck('slug')->all() ?? [];
        $tags = $listed ? array_values(array_unique($validated['experience_tags'] ?? [])) : $current;
        $reviewed = array_key_exists('experiences_reviewed', $validated)
            ? (bool) $validated['experiences_reviewed']
            : (bool) $destination?->experiencesReviewed();

        if ($reviewed && $tags === []) {
            throw ValidationException::withMessages([
                'experiences_reviewed' => $destination?->experiencesReviewed()
                    ? 'A reviewed destination needs at least one experience. Choose one, or mark it Not reviewed first.'
                    : 'Choose at least one experience before marking this destination reviewed.',
            ]);
        }

        return ['tags' => $tags, 'reviewed' => $reviewed];
    }

    /**
     * Applies experienceInput(): the destination's tags become exactly the chosen ones, and the
     * review mark records who confirmed them and when: on becoming reviewed, or when a reviewed
     * destination's tags change. Not reviewed clears the mark and keeps the tags. Status is never touched.
     */
    private function saveExperiences(Destination $destination, ?array $input, Request $request): void
    {
        if ($input === null) {
            return;
        }

        $before = $destination->experienceTags()->pluck('slug')->sort()->values()->all();
        $after = collect($input['tags'])->sort()->values()->all();
        $destination->experienceTags()->sync(ExperienceTag::whereIn('slug', $input['tags'])->pluck('id')->all());

        $wasReviewed = $destination->experiencesReviewed();
        if (! $input['reviewed']) {
            if ($wasReviewed) {
                $destination->forceFill(['experiences_reviewed_at' => null, 'experiences_reviewed_by' => null])->save();
                $this->logReview($destination, $request, 'unreviewed_destination_experiences', 'Marked experiences not reviewed');
            }

            return;
        }
        if (! $wasReviewed || $before !== $after) {
            $destination->forceFill(['experiences_reviewed_at' => now(), 'experiences_reviewed_by' => $request->user()->id])->save();
            $this->logReview($destination, $request, 'reviewed_destination_experiences', 'Reviewed experiences ('.implode(', ', $after).')');
        }
    }

    private function logReview(Destination $destination, Request $request, string $action, string $what): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'description' => "{$what}: {$destination->name}",
            'model_type' => Destination::class,
            'model_id' => $destination->id,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);
    }

    /** Admin responses include the review fields the public API leaves out. */
    private function withReviewFields(Destination $destination): Destination
    {
        return $destination->makeVisible(['experiences_reviewed_at', 'experiences_reviewed_by']);
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

    /** The destination's fees and rates as visitors see them, to tell a real change from a re-save. */
    private function priceRows(Destination $destination): array
    {
        $rows = fn ($relation) => $destination->{$relation}()->orderBy('sort_order')->orderBy('id')->get(['label', 'amount', 'description'])
            ->map(fn ($row) => array_map([InfoValue::class, 'normalize'], [$row->label, $row->amount, $row->description]))->all();

        return ['fees' => $rows('fees'), 'rates' => $rows('rates')];
    }
}

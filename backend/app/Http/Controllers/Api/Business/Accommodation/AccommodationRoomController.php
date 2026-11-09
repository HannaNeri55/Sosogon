<?php

namespace App\Http\Controllers\Api\Business\Accommodation;

use App\Http\Controllers\Concerns\StagesItemChanges;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\StoreAccommodationRoomRequest;
use App\Http\Requests\Business\UpdateAccommodationRoomRequest;
use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\AccommodationRoomImage;
use App\Services\ImageOptimizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccommodationRoomController extends Controller
{
    use StagesItemChanges;

    public const MAX_ROOMS = 30;

    public const CAPACITY_MESSAGE = 'Maximum reached: a property can list up to 30 accommodation options. Group similar rooms into one option, or delete one to add another.';

    /** Owner-editable fields of an option. */
    private const FIELDS = ['name', 'room_number', 'unit_type', 'unit_count', 'category', 'price', 'price_max', 'price_note', 'pricing_mode', 'description', 'notes', 'max_guests', 'bedrooms', 'bed_configuration', 'size_sqm', 'breakfast_included', 'room_features'];

    /** The price state a request leaves an option in: what was stated, else whether it has a price. */
    private function pricingMode(array $values, ?AccommodationRoom $room = null): string
    {
        $price = array_key_exists('price', $values) ? $values['price'] : $room?->price;

        return $values['pricing_mode'] ?? (((float) $price) > 0 ? 'indicative' : ($room?->pricing_mode === 'varies' ? 'varies' : 'contact'));
    }

    /** A property describes its entire-property rental once. */
    private function assertOneEntireProperty(Accommodation $accommodation, ?int $exceptId, ?string $unitType): void
    {
        if ($unitType === 'entire_property' && $accommodation->rooms()->where('unit_type', 'entire_property')->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists()) {
            throw ValidationException::withMessages(['unit_type' => 'This property already describes its entire-property rental. Edit that one instead.']);
        }
    }

    private function ownedAccommodation(Request $request): Accommodation
    {
        return Accommodation::where('user_id', $request->user()->id)->firstOrFail();
    }

    private function ownedRoom(Request $request, int $roomId): AccommodationRoom
    {
        return $this->ownedAccommodation($request)->rooms()->where('id', $roomId)->firstOrFail();
    }

    public function index(Request $request): JsonResponse
    {
        $accommodation = $this->ownedAccommodation($request);

        return response()->json(
            $accommodation->rooms()
                ->with('images')
                ->orderBy('created_at')
                ->get()
                ->each->makeVisible('pending_changes')
        );
    }

    public function store(StoreAccommodationRoomRequest $request, ImageOptimizationService $images): JsonResponse
    {
        $accommodation = $this->ownedAccommodation($request);

        if ($accommodation->rooms()->count() >= self::MAX_ROOMS) {
            return response()->json(['message' => self::CAPACITY_MESSAGE], 422);
        }

        $validated = $request->validated();
        $this->assertOneEntireProperty($accommodation, null, $validated['unit_type'] ?? null);
        $name = $validated['name'] ?? $validated['room_number'];

        // Every new option is reviewed before tourists see it, even on a published listing.
        $room = AccommodationRoom::create(array_intersect_key($validated, array_flip(self::FIELDS)) + [
            'accommodation_id' => $accommodation->id,
            'status' => 'pending',
            'approved_at' => null,
        ]);
        $room->update([
            'name' => $name,
            'unit_type' => $validated['unit_type'] ?? 'room',
            'pricing_mode' => $this->pricingMode($validated),
            'room_features' => $validated['room_features'] ?? [],
            'sort_order' => ($accommodation->rooms()->max('sort_order') ?? 0) + 1,
        ]);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $index => $image) {
                $paths = $images->store($image, "accommodation-rooms/{$room->id}");

                AccommodationRoomImage::create([
                    'room_id' => $room->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $index,
                    'is_primary' => $index === 0,
                ]);
            }
        }

        return response()->json([
            'message' => 'Option added. It will be visible to tourists once approved.',
            'room' => $room->fresh('images'),
        ], 201);
    }

    public function update(UpdateAccommodationRoomRequest $request, int $room, ImageOptimizationService $images): JsonResponse
    {
        $room = $this->ownedRoom($request, $room);

        $validated = $request->validated();
        $this->assertOneEntireProperty($room->accommodation, $room->id, $validated['unit_type'] ?? null);
        $values = array_intersect_key($validated, array_flip(self::FIELDS));
        // name is what's shown; room_number only mirrors it.
        if (isset($values['name'])) {
            unset($values['room_number']);
        }
        if (array_intersect_key($values, array_flip(['price', 'pricing_mode']))) {
            $values['pricing_mode'] = $this->pricingMode($values, $room);
        }

        if ($room->status === 'approved' && $room->accommodation->status === 'approved') {
            // The approved option stays public; changes wait for review (minor corrections excepted).
            $staged = [];
            foreach ($request->file('images', []) as $image) {
                $staged[] = $images->store($image, "accommodation-rooms/{$room->id}/pending");
            }
            $result = $this->stageItemChanges($room, $values, $request, $staged);

            return response()->json([
                'message' => $this->stagedMessage($result, 'option', $request),
                'room' => $room->fresh(['images', 'accommodation'])->makeVisible('pending_changes'),
            ]);
        }

        // Not public yet (the room or its listing isn't approved): edit directly; anything already reviewed goes back to review.
        $needsReReview = $room->status !== 'pending';

        $room->update($values);

        if ($needsReReview) {
            $room->update([
                'status' => 'pending',
                'admin_notes' => null,
                'approved_at' => null,
                'approved_by' => null,
            ]);
        }

        if ($request->hasFile('images')) {
            $maxOrder = $room->images()->max('sort_order') ?? -1;

            foreach ($request->file('images') as $index => $image) {
                $paths = $images->store($image, "accommodation-rooms/{$room->id}");

                AccommodationRoomImage::create([
                    'room_id' => $room->id,
                    'image_path' => $paths['image_path'],
                    'thumbnail_path' => $paths['thumbnail_path'],
                    'sort_order' => $maxOrder + $index + 1,
                    'is_primary' => false,
                ]);
            }
        }

        return response()->json([
            'message' => $needsReReview
                ? 'Changes saved. This option needs Tourism Office approval again before it is visible to tourists.'
                : 'Option updated.',
            'room' => $room->fresh(['images', 'accommodation']),
        ]);
    }

    public function destroy(Request $request, int $room, ImageOptimizationService $images): JsonResponse
    {
        $room = $this->ownedRoom($request, $room);

        foreach ($room->images as $image) {
            $images->delete($image->image_path, $image->thumbnail_path);
        }

        $room->delete();

        return response()->json(['message' => 'Option deleted.']);
    }

    public function destroyImage(Request $request, int $room, int $imageId, ImageOptimizationService $images): JsonResponse
    {
        $room = $this->ownedRoom($request, $room);

        $image = $room->images()->where('id', $imageId)->firstOrFail();

        if ($room->status === 'approved' && $room->accommodation->status === 'approved') {
            // Removing a photo from an approved room is a reviewed change; the photo stays public until then.
            $this->stageItemChanges($room, [], $request, [], [$image->id]);

            return response()->json(['message' => 'Photo removal saved for Tourism Office review.']);
        }

        $images->delete($image->image_path, $image->thumbnail_path);
        $image->delete();

        return response()->json(['message' => 'Image removed.']);
    }
}

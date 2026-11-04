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

class AccommodationRoomController extends Controller
{
    use StagesItemChanges;

    public const MAX_ROOMS = 10;

    public const CAPACITY_MESSAGE = 'Maximum capacity reached: You have registered the limit of 10 rooms. Delete an existing room to add a new one.';

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

        // Every new room is reviewed before tourists see it, even on a published listing.
        $room = AccommodationRoom::create([
            'accommodation_id' => $accommodation->id,
            'room_number' => $validated['room_number'],
            'category' => $validated['category'],
            'price' => $validated['price'] ?? null,
            'description' => $validated['description'] ?? null,
            'room_features' => $validated['room_features'] ?? [],
            'status' => 'pending',
            'approved_at' => null,
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
            'message' => 'Room added. It will be visible to tourists once approved.',
            'room' => $room->load('images'),
        ], 201);
    }

    public function update(UpdateAccommodationRoomRequest $request, int $room, ImageOptimizationService $images): JsonResponse
    {
        $room = $this->ownedRoom($request, $room);

        $validated = $request->validated();

        if ($room->status === 'approved' && $room->accommodation->status === 'approved') {
            // The approved room stays public; changes wait for review (minor corrections excepted).
            $values = array_intersect_key($validated, array_flip(['room_number', 'category', 'price', 'description', 'room_features']));
            $staged = [];
            foreach ($request->file('images', []) as $image) {
                $staged[] = $images->store($image, "accommodation-rooms/{$room->id}/pending");
            }
            $result = $this->stageItemChanges($room, $values, $request, $staged);

            return response()->json([
                'message' => $this->stagedMessage($result, 'room', $request),
                'room' => $room->fresh(['images', 'accommodation'])->makeVisible('pending_changes'),
            ]);
        }

        // Not public yet (the room or its listing isn't approved): edit directly; anything already reviewed goes back to review.
        $needsReReview = $room->status !== 'pending';

        $room->update([
            'room_number' => $validated['room_number'] ?? $room->room_number,
            'category' => $validated['category'] ?? $room->category,
            'price' => array_key_exists('price', $validated)
                ? $validated['price']
                : $room->price,
            'description' => array_key_exists('description', $validated)
                ? $validated['description']
                : $room->description,
            'room_features' => array_key_exists('room_features', $validated)
                ? $validated['room_features']
                : $room->room_features,
        ]);

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
                ? 'Changes saved. This room needs Tourism Office approval again before it is visible to tourists.'
                : 'Room updated.',
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

        return response()->json(['message' => 'Room deleted.']);
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

    public function toggleOccupancy(Request $request, int $room): JsonResponse
    {
        $room = $this->ownedRoom($request, $room);

        $validated = $request->validate([
            'occupancy_status' => 'required|in:occupied,unoccupied',
        ]);

        $room->update([
            'occupancy_status' => $validated['occupancy_status'],
        ]);

        return response()->json([
            'message' => 'Room status updated.',
            'room' => $room,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\ReviewsItemChanges;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\UpdateAccommodationRoomRequest;
use App\Models\AccommodationRoom;
use App\Models\AccommodationRoomImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAccommodationRoomController extends Controller
{
    use ReviewsItemChanges;

    private const FIELDS = ['name', 'room_number', 'unit_type', 'unit_count', 'category', 'price', 'price_max', 'price_note', 'pricing_mode', 'description', 'notes', 'max_guests', 'bedrooms', 'bed_configuration', 'size_sqm', 'breakfast_included', 'room_features'];

    public function index(Request $request): JsonResponse
    {
        $query = AccommodationRoom::with(['images', 'accommodation:id,name,user_id,status,municipality_id', 'accommodation.user:id,name', 'accommodation.municipality:id,name']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Approved rooms with proposed changes awaiting review.
        if ($request->input('changes') === 'pending') {
            $query->where('pending_changes->status', 'pending');
        }

        $perPage = $this->perPage($request, 100, 200);

        $page = $query->orderByDesc('created_at')->paginate($perPage);
        $this->withPendingChanges($page->getCollection());

        return response()->json($page);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $room = AccommodationRoom::pending()->findOrFail($id);

        $room->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'admin_notes' => $request->input('notes'),
        ]);

        return response()->json(['message' => 'Room approved.']);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['notes' => 'required|string|max:1000']);

        $room = AccommodationRoom::pending()->findOrFail($id);

        $room->update([
            'status' => 'rejected',
            'admin_notes' => $request->notes,
        ]);

        return response()->json(['message' => 'Room rejected.']);
    }

    /** A direct correction by the Tourism Office: applies at once and keeps the approval status. */
    public function update(UpdateAccommodationRoomRequest $request, int $id): JsonResponse
    {
        $room = AccommodationRoom::findOrFail($id);
        $values = array_intersect_key($request->validated(), array_flip(self::FIELDS));
        if (isset($values['name'])) {
            unset($values['room_number']);
        }
        if (array_intersect_key($values, array_flip(['price', 'pricing_mode'])) && ! isset($values['pricing_mode'])) {
            $values['pricing_mode'] = ((float) ($values['price'] ?? $room->price)) > 0 ? 'indicative' : 'contact';
        }
        $room->update($values);

        return response()->json(['message' => 'Option updated.', 'room' => $room->fresh('images')]);
    }

    public function approveChanges(Request $request, int $id): JsonResponse
    {
        return $this->approveItemChanges($request, AccommodationRoom::class, $id, function (AccommodationRoom $room, array $pending) {
            $delete = [];
            foreach ($room->images()->whereIn('id', $pending['remove_image_ids'] ?? [])->get() as $image) {
                $delete[] = [$image->image_path, $image->thumbnail_path];
                $image->delete();
            }

            $order = ($room->images()->max('sort_order') ?? -1) + 1;
            foreach ($pending['new_images'] ?? [] as $image) {
                AccommodationRoomImage::create([
                    'room_id' => $room->id,
                    'image_path' => $image['image_path'],
                    'thumbnail_path' => $image['thumbnail_path'],
                    'sort_order' => $order++,
                    'is_primary' => false,
                ]);
            }

            // Keep exactly one cover while photos remain.
            if (! $room->images()->where('is_primary', true)->exists() && ($first = $room->images()->orderBy('sort_order')->first())) {
                $first->update(['is_primary' => true]);
            }

            return $delete;
        });
    }

    public function rejectChanges(Request $request, int $id): JsonResponse
    {
        return $this->rejectItemChanges($request, AccommodationRoom::class, $id);
    }
}

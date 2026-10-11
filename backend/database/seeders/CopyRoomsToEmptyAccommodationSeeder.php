<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\AccommodationRoomImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copies the room lineup of "Accomodation" (the accommodation owner account's
 * listing, set up 2026-10-04 with Standard/Deluxe/Family Room/Suite rooms) onto
 * every accommodation that has no rooms of its own yet. The original source,
 * Woodlands Condotel, was lost with the old database. AccommodationRoomController::
 * MAX_ROOMS is 10; the source's 6 rooms fit every target with room to spare.
 */
class CopyRoomsToEmptyAccommodationSeeder extends Seeder
{
    private const SOURCE_NAME = 'Accomodation';

    public function run(): void
    {
        $source = Accommodation::where('name', self::SOURCE_NAME)->first();

        if (! $source) {
            $this->command->error(self::SOURCE_NAME.' not found — nothing to copy.');

            return;
        }

        $sourceRooms = AccommodationRoom::where('accommodation_id', $source->id)->orderBy('id')->get();

        if ($sourceRooms->isEmpty()) {
            $this->command->error(self::SOURCE_NAME.' has no rooms to copy.');

            return;
        }

        $targets = Accommodation::where('id', '!=', $source->id)
            ->whereDoesntHave('rooms')
            ->get();

        foreach ($targets as $target) {
            $trusted = $target->status === 'approved';

            foreach ($sourceRooms as $room) {
                $newRoom = AccommodationRoom::create([
                    'accommodation_id' => $target->id,
                    'room_number' => $room->room_number,
                    'category' => $room->category,
                    'price' => $room->price,
                    'description' => $room->description,
                    'occupancy_status' => 'unoccupied',
                    'status' => $trusted ? 'approved' : 'pending',
                    'approved_at' => $trusted ? now() : null,
                ]);

                $this->copyImages($room, $newRoom->id);
            }
        }
    }

    private function copyImages(AccommodationRoom $room, int $newRoomId): void
    {
        $disk = Storage::disk('public');

        foreach ($room->images as $image) {
            if (! $image->image_path || ! $disk->exists($image->image_path)) {
                continue;
            }

            $filename = Str::uuid()->toString().'.webp';
            $destImagePath = "accommodation-rooms/{$newRoomId}/{$filename}";
            $disk->copy($image->image_path, $destImagePath);

            // image_path and thumbnail_path are both required columns on this table —
            // fall back to the full image if there's no separate thumbnail to copy.
            $destThumbPath = $destImagePath;
            if ($image->thumbnail_path && $disk->exists($image->thumbnail_path)) {
                $destThumbPath = "accommodation-rooms/{$newRoomId}/thumbnails/{$filename}";
                $disk->copy($image->thumbnail_path, $destThumbPath);
            }

            AccommodationRoomImage::create([
                'room_id' => $newRoomId,
                'image_path' => $destImagePath,
                'thumbnail_path' => $destThumbPath,
                'is_primary' => $image->is_primary,
                'sort_order' => $image->sort_order,
            ]);
        }
    }
}

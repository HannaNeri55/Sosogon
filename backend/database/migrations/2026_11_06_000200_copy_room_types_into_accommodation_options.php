<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Describes existing rooms in the new fields and copies display-only room types into options, for
 * stays with no rooms (where they are all a tourist sees). Only adds: no existing value changes,
 * no price, amenity or text is invented, and no file moves (photo rows point at the same files).
 * accommodation_room_types stays in place. Stays with rooms keep them; their room types would
 * only duplicate them.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('accommodations')->whereIn('type', ['Hotel', 'Resort', 'Inn', 'Homestay', 'Villa', 'Cottage', 'Guesthouse'])->whereNull('classifications')->get(['id', 'type']) as $stay) {
            DB::table('accommodations')->where('id', $stay->id)->update(['classifications' => json_encode([$stay->type])]);
        }

        foreach (DB::table('accommodation_rooms')->whereNull('legacy_room_type_id')->get(['id', 'room_number', 'price']) as $room) {
            DB::table('accommodation_rooms')->where('id', $room->id)->update([
                'name' => $room->room_number,
                'unit_type' => 'room',
                'pricing_mode' => (float) $room->price > 0 ? 'indicative' : 'contact',
            ]);
        }

        $withRooms = DB::table('accommodation_rooms')->whereNull('legacy_room_type_id')->pluck('accommodation_id')->unique();
        $types = DB::table('accommodation_room_types')->whereNotIn('accommodation_id', $withRooms)->orderBy('id')->get();
        foreach ($types as $type) {
            if (DB::table('accommodation_rooms')->where('legacy_room_type_id', $type->id)->exists()) {
                continue;
            }
            $priced = (float) $type->price_min > 0;
            $roomId = DB::table('accommodation_rooms')->insertGetId([
                'accommodation_id' => $type->accommodation_id,
                'room_number' => $type->name,
                'name' => $type->name,
                'unit_type' => $this->unitType($type->name),
                'category' => $this->category($type->name),
                'description' => $type->description,
                'max_guests' => $type->max_guests,
                'bed_configuration' => $type->bed_configuration,
                'room_features' => $type->amenities,
                'price' => $priced ? $type->price_min : null,
                'price_max' => $priced && (float) $type->price_max > (float) $type->price_min ? $type->price_max : null,
                'price_note' => $type->price_note,
                'pricing_mode' => $priced ? 'indicative' : 'contact',
                'breakfast_included' => $type->breakfast_included,
                'source_url' => $type->source_url,
                'sort_order' => $type->sort_order,
                'legacy_room_type_id' => $type->id,
                'status' => 'approved',
                'approved_at' => $type->created_at,
                'created_at' => $type->created_at,
                'updated_at' => $type->updated_at,
            ]);
            if ($type->image_path) {
                DB::table('accommodation_room_images')->insert([
                    'room_id' => $roomId,
                    'image_path' => $type->image_path,
                    'thumbnail_path' => $type->thumbnail_path ?? $type->image_path,
                    'image_credit' => $type->image_credit,
                    'image_source_url' => $type->image_source_url,
                    'is_primary' => true,
                    'sort_order' => 0,
                    'created_at' => $type->created_at,
                    'updated_at' => $type->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accommodation_rooms', 'legacy_room_type_id')) {
            // Only the copied rows and their photo rows go; the files stay, the room types still use them.
            $ids = DB::table('accommodation_rooms')->whereNotNull('legacy_room_type_id')->pluck('id');
            DB::table('accommodation_room_images')->whereIn('room_id', $ids)->delete();
            DB::table('accommodation_rooms')->whereIn('id', $ids)->delete();
        }
    }

    /** Only a clear word in the name decides; anything else stays a room for the owner to refine. */
    private function unitType(string $name): string
    {
        return match (true) {
            (bool) preg_match('/\bvilla\b/i', $name) => 'villa',
            (bool) preg_match('/\b(cottage|cabana|casita)\b/i', $name) => 'cottage',
            default => 'room',
        };
    }

    /** The room category named outright, which the planner reads for capacity; null when none is. */
    private function category(string $name): ?string
    {
        return match (true) {
            (bool) preg_match('/\bfamily\b/i', $name) => 'Family Room',
            (bool) preg_match('/\bsuite\b/i', $name) => 'Suite',
            (bool) preg_match('/\bdeluxe\b/i', $name) => 'Deluxe',
            (bool) preg_match('/\bstandard\b/i', $name) => 'Standard',
            default => null,
        };
    }
};

<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Existing stays moving to accommodation options: rooms keep their IDs and values, display-only room
 * types are copied for stays with no rooms, and nothing is invented, changed or lost, in Bulan or elsewhere.
 */
class AccommodationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    private array $stays = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require database_path('migrations/2026_11_06_000200_copy_room_types_into_accommodation_options.php');
        $owner = User::create(['role_id' => Role::create(['name' => 'business_owner', 'description' => 'Owner'])->id, 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('x')])->id;
        $town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['rooms' => 'Accommodation', 'types' => 'Accommodation', 'hotel' => 'Hotel', 'empty' => 'Accommodation'] as $key => $type) {
            $this->stays[$key] = DB::table('accommodations')->insertGetId([
                'user_id' => $owner, 'name' => "Stay {$key}", 'description' => "Stay {$key} as written by its owner.", 'type' => $type, 'municipality_id' => $town, 'address' => 'Zone 8',
                'latitude' => 12.666256, 'longitude' => 123.878623, 'price_range' => 'moderate', 'contact_number' => '0917', 'status' => 'approved', 'amenities' => '["Parking"]', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->room = DB::table('accommodation_rooms')->insertGetId([
            'accommodation_id' => $this->stays['rooms'], 'room_number' => 'Room 101', 'category' => 'Standard', 'price' => 1200, 'room_features' => '["Television"]', 'occupancy_status' => 'occupied', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->unpriced = DB::table('accommodation_rooms')->insertGetId([
            'accommodation_id' => $this->stays['rooms'], 'room_number' => 'Room 102', 'category' => 'Deluxe', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $type = fn (int $stay, array $fields) => DB::table('accommodation_room_types')->insertGetId($fields + ['accommodation_id' => $stay, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->duplicateType = $type($this->stays['rooms'], ['name' => 'Standard Room', 'price_min' => 1200]);
        $this->pricedType = $type($this->stays['types'], ['name' => 'Deluxe Room', 'description' => 'As published.', 'bed_configuration' => '1 queen bed', 'max_guests' => 2, 'amenities' => '["Balcony"]', 'price_min' => 3129, 'price_max' => 3129, 'price_note' => 'Published rate, undated', 'breakfast_included' => true, 'source_url' => 'https://example.com/rates',
            'image_path' => 'accommodation-room-types/1/d.webp', 'thumbnail_path' => 'accommodation-room-types/1/thumbnails/d.webp', 'image_credit' => 'Representative photo: Cy / Wikimedia Commons, CC0', 'image_source_url' => 'https://commons.wikimedia.org/wiki/File:D.jpg']);
        $this->villaType = $type($this->stays['types'], ['name' => 'Private Villa', 'sort_order' => 1]);
        $this->cottageType = $type($this->stays['types'], ['name' => 'Balay Galak', 'sort_order' => 2]);

        $this->before = $this->snapshot();
        $this->migration->up();
    }

    private int $room;

    private int $unpriced;

    private int $duplicateType;

    private int $pricedType;

    private int $villaType;

    private int $cottageType;

    private array $before;

    /** Every table these rows live in, on the columns that existed before the migration. */
    private function snapshot(): array
    {
        $stay = ['id', 'user_id', 'name', 'description', 'type', 'municipality_id', 'address', 'latitude', 'longitude', 'price_range', 'contact_number', 'status', 'amenities'];
        $room = ['id', 'accommodation_id', 'room_number', 'category', 'price', 'room_features', 'occupancy_status', 'status'];

        return [
            'stays' => DB::table('accommodations')->orderBy('id')->get($stay)->toArray(),
            'rooms' => DB::table('accommodation_rooms')->whereNull('legacy_room_type_id')->orderBy('id')->get($room)->toArray(),
            'types' => DB::table('accommodation_room_types')->orderBy('id')->get()->toArray(),
        ];
    }

    private function copyOf(int $typeId): ?object
    {
        return DB::table('accommodation_rooms')->where('legacy_room_type_id', $typeId)->first();
    }

    public function test_existing_records_keep_every_value_and_id(): void
    {
        $this->assertEquals($this->before, $this->snapshot());
    }

    public function test_existing_rooms_are_described_without_inventing_anything(): void
    {
        $priced = DB::table('accommodation_rooms')->find($this->room);
        $this->assertSame('Room 101', $priced->name);
        $this->assertSame('room', $priced->unit_type);
        $this->assertSame('indicative', $priced->pricing_mode);
        $this->assertNull($priced->max_guests, 'Capacity is not assumed.');
        $this->assertNull($priced->unit_count);

        $unpriced = DB::table('accommodation_rooms')->find($this->unpriced);
        $this->assertSame('contact', $unpriced->pricing_mode);
        $this->assertNull($unpriced->price);
    }

    public function test_room_types_become_options_only_for_stays_with_no_rooms(): void
    {
        $this->assertNull($this->copyOf($this->duplicateType), 'A stay with rooms keeps them; its room types would duplicate them.');

        $copy = $this->copyOf($this->pricedType);
        $this->assertSame($this->stays['types'], (int) $copy->accommodation_id);
        $this->assertSame('Deluxe Room', $copy->name);
        $this->assertSame('Deluxe Room', $copy->room_number);
        $this->assertSame('Deluxe', $copy->category);
        $this->assertSame('As published.', $copy->description);
        $this->assertSame('1 queen bed', $copy->bed_configuration);
        $this->assertSame(2, (int) $copy->max_guests);
        $this->assertSame('["Balcony"]', $copy->room_features);
        $this->assertEquals(3129, $copy->price);
        $this->assertSame('Published rate, undated', $copy->price_note);
        $this->assertSame('approved', $copy->status, 'What tourists already see stays visible.');

        $this->assertSame('villa', $this->copyOf($this->villaType)->unit_type);
        $vague = $this->copyOf($this->cottageType);
        $this->assertSame('room', $vague->unit_type, 'Only a clear word decides the unit type.');
        $this->assertNull($vague->price);
        $this->assertSame('contact', $vague->pricing_mode);
        $this->assertNull($vague->category);
    }

    public function test_photos_and_credits_carry_over_pointing_at_the_same_files(): void
    {
        $photo = DB::table('accommodation_room_images')->where('room_id', $this->copyOf($this->pricedType)->id)->sole();

        $this->assertSame('accommodation-room-types/1/d.webp', $photo->image_path);
        $this->assertSame('Representative photo: Cy / Wikimedia Commons, CC0', $photo->image_credit);
        $this->assertSame('https://commons.wikimedia.org/wiki/File:D.jpg', $photo->image_source_url);
        $this->assertEquals(1, $photo->is_primary);
    }

    public function test_classifications_come_only_from_a_stated_type(): void
    {
        $this->assertSame('["Hotel"]', DB::table('accommodations')->where('id', $this->stays['hotel'])->value('classifications'));
        $this->assertNull(DB::table('accommodations')->where('id', $this->stays['types'])->value('classifications'), 'Plain "Accommodation" stays unclassified.');
        $this->assertNull(DB::table('accommodations')->where('id', $this->stays['hotel'])->value('offering_arrangement'), 'The arrangement is never guessed.');
    }

    public function test_running_again_adds_nothing_and_reversing_removes_only_the_copies(): void
    {
        $count = DB::table('accommodation_rooms')->count();
        $this->migration->up();
        $this->assertSame($count, DB::table('accommodation_rooms')->count());

        $this->migration->down();
        $this->assertSame(2, DB::table('accommodation_rooms')->count());
        $this->assertSame(0, DB::table('accommodation_room_images')->count());
        $this->assertSame(4, DB::table('accommodation_room_types')->count());
        $this->assertEquals($this->before, $this->snapshot());
    }

    public function test_existing_stays_keep_working_on_the_public_page(): void
    {
        $page = $this->getJson("/api/v1/accommodations/{$this->stays['types']}")->assertOk()->json();
        $this->assertSame(['Deluxe Room', 'Private Villa', 'Balay Galak'], array_column($page['rooms'], 'name'));

        $this->getJson("/api/v1/accommodations/{$this->stays['empty']}")->assertOk()->assertJsonPath('rooms', []);
    }
}

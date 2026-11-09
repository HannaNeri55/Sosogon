<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\AmenitySuggestion;
use App\Models\Role;
use App\Models\User;
use App\Support\AmenityList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Suggested and custom amenities: custom ones stay on their own record until an admin promotes the name. */
class AmenityManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private Accommodation $stay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['role_id' => Role::create(['name' => 'admin', 'description' => 'Admin'])->id, 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x'), 'status' => 'active']);
        $this->owner = User::create(['role_id' => Role::create(['name' => 'business_owner', 'description' => 'Owner'])->id, 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('x'), 'status' => 'active', 'business_type' => 'accommodation']);
        $town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $this->stay = Accommodation::create([
            'user_id' => $this->owner->id, 'name' => 'Test Resort', 'description' => 'A test resort with plenty of room to describe.', 'type' => 'Resort',
            'municipality_id' => $town, 'address' => 'Test St.', 'latitude' => 12.67, 'longitude' => 123.88, 'price_range' => 'moderate', 'status' => 'pending',
        ]);
    }

    public function test_suggestions_are_offered_by_scope(): void
    {
        $property = $this->getJson('/api/v1/amenity-suggestions?scope=property')->assertOk()->json();
        $unit = $this->getJson('/api/v1/amenity-suggestions?scope=unit')->assertOk()->json();

        $this->assertContains('Swimming Pool', $property);
        $this->assertNotContains('Mini Bar', $property);
        $this->assertContains('Mini Bar', $unit);
        $this->assertContains('Air Conditioning', $property, 'Some apply to both.');
        $this->assertContains('Air Conditioning', $unit);
    }

    public function test_owner_adds_a_custom_amenity_that_stays_on_that_record_only(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/business/accommodation/rooms', ['name' => 'Villa 1', 'room_features' => ['Karaoke Deck']])->assertCreated();
        $this->actingAs($this->owner)->postJson('/api/v1/business/accommodation/rooms', ['name' => 'Villa 2', 'room_features' => ['Kitchen']])->assertCreated();

        $rooms = $this->stay->rooms()->get()->keyBy('name');
        $this->assertSame(['Karaoke Deck'], $rooms['Villa 1']->room_features);
        $this->assertSame(['Kitchen'], $rooms['Villa 2']->room_features);
        $this->assertNull($this->stay->fresh()->amenities, 'Unit amenities never reach the property.');
        $this->assertFalse(AmenitySuggestion::where('name', 'Karaoke Deck')->exists(), 'Not shared automatically.');
    }

    public function test_owner_edits_and_deletes_custom_amenities(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/business/accommodation/rooms', ['name' => 'Villa 1', 'room_features' => ['Karaoke Deck', 'Hamak']])->assertCreated();
        $room = $this->stay->rooms()->sole();

        $this->actingAs($this->owner)->putJson("/api/v1/business/accommodation/rooms/{$room->id}", ['room_features' => ['Hammock']])->assertOk();
        $this->assertSame(['Hammock'], $room->fresh()->room_features);

        $this->actingAs($this->owner)->putJson("/api/v1/business/accommodation/rooms/{$room->id}", ['room_features' => []])->assertOk();
        $this->assertSame([], $room->fresh()->room_features);
    }

    public function test_duplicates_markup_and_blanks_are_removed(): void
    {
        $this->assertSame(['Free Wi-Fi', 'Spa'], AmenityList::clean([' Free  Wi-Fi ', 'free wi-fi', '<b>Spa</b>', '   ', 'SPA']));

        $this->actingAs($this->owner)->postJson('/api/v1/business/accommodation/rooms', ['name' => 'Villa', 'room_features' => ['KTV', 'ktv', ' KTV ']])->assertCreated();
        $this->assertSame(['KTV'], $this->stay->rooms()->sole()->room_features);

        $this->actingAs($this->owner)->postJson('/api/v1/business/accommodation/rooms', ['name' => 'Long', 'room_features' => [str_repeat('x', 101)]])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson('/api/v1/business/accommodation/rooms', ['name' => 'Many', 'room_features' => array_map(fn ($i) => "Thing {$i}", range(1, 31))])->assertUnprocessable();
    }

    public function test_admin_reviews_custom_amenities_and_promotes_one_without_changing_listings(): void
    {
        $this->stay->update(['amenities' => ['Swimming Pool', 'Sunset Deck']]);
        AccommodationRoom::create(['accommodation_id' => $this->stay->id, 'name' => 'Villa', 'room_features' => ['Sunset Deck', 'Kitchen'], 'status' => 'approved']);

        $entries = $this->actingAs($this->admin)->getJson('/api/v1/admin/amenities/custom')->assertOk()->json();
        $this->assertCount(1, $entries, 'Suggested names are not custom.');
        $this->assertSame('Sunset Deck', $entries[0]['name']);
        $this->assertSame(1, $entries[0]['property_uses']);
        $this->assertSame(1, $entries[0]['unit_uses']);
        $this->assertSame(['Test Resort'], $entries[0]['listings']);

        $this->actingAs($this->admin)->postJson('/api/v1/admin/amenity-suggestions', ['name' => 'Sunset Deck', 'scope' => 'both'])->assertCreated();
        $this->assertContains('Sunset Deck', $this->getJson('/api/v1/amenity-suggestions?scope=unit')->json());
        $this->assertSame([], $this->actingAs($this->admin)->getJson('/api/v1/admin/amenities/custom')->json());
        $this->assertSame(['Swimming Pool', 'Sunset Deck'], $this->stay->fresh()->amenities);

        $this->actingAs($this->admin)->postJson('/api/v1/admin/amenity-suggestions', ['name' => 'sunset deck', 'scope' => 'both'])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson('/api/v1/admin/amenity-suggestions', ['name' => 'Nope', 'scope' => 'both'])->assertForbidden();
        $this->actingAs($this->owner)->getJson('/api/v1/admin/amenities/custom')->assertForbidden();
    }
}

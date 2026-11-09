<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A stay's accommodation options: any arrangement, with their own details, amenities and optional prices. */
class AccommodationOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private Accommodation $stay;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = User::create(['role_id' => Role::create(['name' => 'admin', 'description' => 'Admin'])->id, 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x'), 'status' => 'active']);
        $this->owner = User::create(['role_id' => Role::create(['name' => 'business_owner', 'description' => 'Owner'])->id, 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('x'), 'status' => 'active', 'business_type' => 'accommodation']);
        $town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $this->stay = Accommodation::create([
            'user_id' => $this->owner->id, 'name' => "Cristina's Resort", 'description' => 'A test resort with plenty of room to describe.', 'type' => 'Resort',
            'municipality_id' => $town, 'address' => 'Test St.', 'latitude' => 12.67, 'longitude' => 123.88, 'price_range' => 'moderate', 'status' => 'approved', 'approved_at' => now(),
        ]);
    }

    private function add(array $fields)
    {
        return $this->actingAs($this->owner)->postJson('/api/v1/business/accommodation/rooms', $fields);
    }

    private function approved(array $fields): AccommodationRoom
    {
        return AccommodationRoom::create($fields + ['accommodation_id' => $this->stay->id, 'status' => 'approved']);
    }

    private function publicStay(): array
    {
        return $this->getJson("/api/v1/accommodations/{$this->stay->id}")->assertOk()->json();
    }

    public function test_a_property_with_only_one_cottage_needs_just_a_name(): void
    {
        $this->add(['name' => 'Beach Cottage', 'unit_type' => 'cottage'])->assertCreated();

        $cottage = $this->stay->rooms()->sole();
        $this->assertSame('Beach Cottage', $cottage->name);
        $this->assertSame('Beach Cottage', $cottage->room_number, 'The legacy label mirrors the name.');
        $this->assertSame('cottage', $cottage->unit_type);
        $this->assertNull($cottage->price);
        $this->assertSame('contact', $cottage->pricing_mode);
        $this->assertSame('pending', $cottage->status, 'New options are still reviewed first.');
    }

    public function test_two_villas_keep_their_own_amenities_photos_and_details(): void
    {
        $this->add(['name' => 'Villa 1', 'unit_type' => 'villa', 'bedrooms' => 2, 'max_guests' => 6, 'room_features' => ['Private Kitchen', 'Living Area']])->assertCreated();
        $this->actingAs($this->owner)->post('/api/v1/business/accommodation/rooms', [
            'name' => 'Villa 2', 'unit_type' => 'villa', 'room_features' => ['Private Pool', 'KTV'], 'images' => [UploadedFile::fake()->image('v2.jpg')],
        ], ['Accept' => 'application/json'])->assertCreated();

        [$one, $two] = $this->stay->rooms()->with('images')->get()->all();
        $this->assertSame(['Private Kitchen', 'Living Area'], $one->room_features);
        $this->assertSame(['Private Pool', 'KTV'], $two->room_features);
        $this->assertSame(2, $one->bedrooms);
        $this->assertCount(0, $one->images);
        $this->assertCount(1, $two->images);
        $this->assertSame(1, Accommodation::count(), 'One property, however many villas.');
    }

    public function test_a_hotel_groups_similar_rooms_into_room_types_with_different_amenities(): void
    {
        $this->add(['name' => 'Superior Room', 'category' => 'Superior', 'unit_count' => 10, 'max_guests' => 2, 'bed_configuration' => '1 queen bed', 'room_features' => ['Air Conditioning']])->assertCreated();
        $this->add(['name' => 'Deluxe Room', 'category' => 'Deluxe', 'unit_count' => 5, 'room_features' => ['Air Conditioning', 'Mini Bar']])->assertCreated();

        $this->assertSame(2, $this->stay->rooms()->count());
        $this->assertSame(15, (int) $this->stay->rooms()->sum('unit_count'));
        $this->assertSame(['Air Conditioning', 'Mini Bar'], $this->stay->rooms()->where('name', 'Deluxe Room')->first()->room_features);
    }

    public function test_a_resort_offering_only_its_entire_property_needs_no_options(): void
    {
        $this->stay->update(['offering_arrangement' => 'entire_property', 'amenities' => ['Swimming Pool']]);

        $page = $this->publicStay();
        $this->assertSame('entire_property', $page['offering_arrangement']);
        $this->assertSame([], $page['rooms']);
        $this->assertSame(['Swimming Pool'], $page['amenities']);
    }

    public function test_a_resort_can_offer_units_and_its_entire_property_described_once(): void
    {
        $this->stay->update(['offering_arrangement' => 'both']);
        $this->approved(['name' => 'Villa 1', 'unit_type' => 'villa']);
        $this->add(['name' => 'Entire property', 'unit_type' => 'entire_property', 'max_guests' => 30])->assertCreated();
        $this->add(['name' => 'Whole place again', 'unit_type' => 'entire_property'])->assertUnprocessable()->assertJsonValidationErrors('unit_type');

        $this->assertSame(1, $this->stay->rooms()->where('unit_type', 'entire_property')->count());
    }

    public function test_property_and_unit_amenities_stay_separate(): void
    {
        $this->stay->update(['amenities' => ['Swimming Pool', 'Parking']]);
        $this->approved(['name' => 'Villa 1', 'room_features' => ['Private Kitchen']]);

        $page = $this->publicStay();
        $this->assertSame(['Swimming Pool', 'Parking'], $page['amenities']);
        $this->assertSame(['Private Kitchen'], $page['rooms'][0]['room_features']);
    }

    public function test_prices_are_optional_and_zero_means_not_provided(): void
    {
        $this->add(['name' => 'Priced', 'price' => 1500])->assertCreated();
        $this->add(['name' => 'Zero', 'price' => 0])->assertCreated();
        $this->add(['name' => 'Varies', 'pricing_mode' => 'varies', 'price_note' => 'Depends on the number of guests'])->assertCreated();
        $this->add(['name' => 'Ask', 'pricing_mode' => 'contact', 'price' => 900])->assertCreated();

        $by = $this->stay->rooms()->get()->keyBy('name');
        $this->assertSame('indicative', $by['Priced']->pricing_mode);
        $this->assertNull($by['Zero']->price);
        $this->assertSame('contact', $by['Zero']->pricing_mode);
        $this->assertSame('varies', $by['Varies']->pricing_mode);
        $this->assertNull($by['Ask']->price, 'Contact the property means no price is kept.');
    }

    public function test_editing_an_approved_option_stays_held_for_review_including_new_fields(): void
    {
        $villa = $this->approved(['name' => 'Villa 1', 'unit_type' => 'villa']);

        $this->actingAs($this->owner)->putJson("/api/v1/business/accommodation/rooms/{$villa->id}", ['max_guests' => 8, 'bedrooms' => 3, 'room_features' => ['KTV']])->assertOk();

        $villa->refresh();
        $this->assertNull($villa->max_guests, 'Live values wait for approval.');
        $this->assertSame(8, $villa->pending_changes['values']['max_guests']);
        $this->assertSame(3, $villa->pending_changes['values']['bedrooms']);
    }

    public function test_admin_approves_held_changes_and_can_correct_an_option_directly(): void
    {
        $villa = $this->approved(['name' => 'Villa 1']);
        $this->actingAs($this->owner)->putJson("/api/v1/business/accommodation/rooms/{$villa->id}", ['name' => 'Garden Villa', 'max_guests' => 8])->assertOk();

        $hash = md5(json_encode($villa->fresh()->pending_changes));
        $this->actingAs($this->admin)->putJson("/api/v1/admin/accommodation-rooms/{$villa->id}/approve-changes", ['changes_hash' => $hash])->assertOk();
        $villa->refresh();
        $this->assertSame('Garden Villa', $villa->name);
        $this->assertSame('Garden Villa', $villa->room_number);
        $this->assertSame(8, $villa->max_guests);

        $this->actingAs($this->admin)->putJson("/api/v1/admin/accommodation-rooms/{$villa->id}", ['max_guests' => 6, 'room_features' => ['KTV', 'ktv']])->assertOk();
        $villa->refresh();
        $this->assertSame(6, $villa->max_guests);
        $this->assertSame(['KTV'], $villa->room_features);
        $this->assertSame('approved', $villa->status);

        $this->actingAs($this->owner)->putJson("/api/v1/admin/accommodation-rooms/{$villa->id}", ['max_guests' => 99])->assertForbidden();
    }

    public function test_admin_review_rejects_and_approves_new_options_as_before(): void
    {
        $this->add(['name' => 'New Cottage', 'unit_type' => 'cottage'])->assertCreated();
        $cottage = $this->stay->rooms()->sole();
        $this->assertSame([], $this->publicStay()['rooms'], 'Hidden until approved.');

        $this->actingAs($this->admin)->putJson("/api/v1/admin/accommodation-rooms/{$cottage->id}/approve")->assertOk();
        $this->assertSame('New Cottage', $this->publicStay()['rooms'][0]['name']);
    }

    public function test_the_property_fields_go_through_the_revision_workflow(): void
    {
        $this->actingAs($this->owner)->putJson('/api/v1/business/accommodation', [
            'offering_arrangement' => 'both', 'classifications' => ['Resort', 'Villa'], 'check_in_time' => '2:00 PM', 'amenities' => ['Parking', 'parking', '<b>Spa</b>'],
        ])->assertOk();

        $this->stay->refresh();
        $this->assertNull($this->stay->offering_arrangement, 'A published stay keeps its live values until reviewed.');
        $proposed = $this->stay->revisions()->latest('id')->first()->proposed_data;
        $this->assertSame('both', $proposed['offering_arrangement']);
        $this->assertSame(['Resort', 'Villa'], $proposed['classifications']);
        $this->assertSame(['Parking', 'Spa'], $proposed['amenities'], 'Cleaned and without duplicates.');

        $this->actingAs($this->owner)->putJson('/api/v1/business/accommodation', ['offering_arrangement' => 'nonsense'])->assertUnprocessable();
    }

    public function test_the_public_type_filter_matches_classifications_and_the_page_hides_internal_fields(): void
    {
        $this->stay->update(['type' => 'Accommodation', 'classifications' => ['Beach Resort']]);
        $this->approved(['name' => 'Villa 1', 'occupancy_status' => 'occupied', 'source_url' => 'https://example.com', 'price' => 1200, 'pricing_mode' => 'indicative']);

        $this->getJson('/api/v1/accommodations?type=Beach%20Resort')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/accommodations?type=Hotel')->assertOk()->assertJsonCount(0, 'data');

        $room = $this->publicStay()['rooms'][0];
        $this->assertSame('Villa 1', $room['name']);
        $this->assertEquals(1200, $room['price']);
        $this->assertArrayNotHasKey('occupancy_status', $room);
        $this->assertArrayNotHasKey('source_url', $room);
    }

    public function test_a_resort_and_hotel_is_one_listing_found_under_both_types(): void
    {
        $this->stay->update(['type' => 'Resort', 'classifications' => ['Resort', 'Hotel']]);

        foreach (['Resort', 'Hotel'] as $type) {
            $this->getJson('/api/v1/accommodations?type='.$type)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->stay->id);
        }
        $this->getJson('/api/v1/accommodations?type=Inn')->assertOk()->assertJsonCount(0, 'data');

        // The admin edit takes the same categories as owners: a combined "Resort & Hotel" string would match neither filter.
        $this->actingAs($this->admin)->putJson("/api/v1/admin/accommodations/{$this->stay->id}", ['classifications' => ['Resort & Hotel']])->assertUnprocessable();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/accommodations/{$this->stay->id}", ['classifications' => ['Hotel', 'Resort']])->assertOk();
        $this->assertSame(['Hotel', 'Resort'], $this->stay->fresh()->classifications);
        $this->assertSame(1, Accommodation::count(), 'Still one record, not one per category.');

        // Unticking Resort takes it out of the Resort filter, although Resort was its main type.
        $this->actingAs($this->admin)->putJson("/api/v1/admin/accommodations/{$this->stay->id}", ['classifications' => ['Hotel']])->assertOk();
        $this->assertSame('Hotel', $this->stay->fresh()->type);
        $this->getJson('/api/v1/accommodations?type=Resort')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/accommodations?type=Hotel')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_stay_with_no_options_still_has_a_complete_page(): void
    {
        $page = $this->publicStay();

        $this->assertSame("Cristina's Resort", $page['name']);
        $this->assertSame([], $page['rooms']);
    }
}

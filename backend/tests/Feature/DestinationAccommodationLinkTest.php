<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A destination that is also an accommodation (a resort visited for the day and stayed at, like
 * Isabel's Resort): the Tourism Office links the two in Manage Destinations; the public pages
 * point from one to the other, and Data Checks finds a resort listed both ways but not linked.
 */
class DestinationAccommodationLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $provincial;

    private User $owner;

    private int $bulan;

    private int $irosin;

    private Destination $resort;

    private Accommodation $rooms;

    protected function setUp(): void
    {
        parent::setUp();
        $adminRole = Role::create(['name' => 'admin', 'description' => 'Admin'])->id;
        $ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Owner'])->id;
        $this->bulan = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $this->irosin = DB::table('municipalities')->insertGetId(['name' => 'Irosin', 'created_at' => now(), 'updated_at' => now()]);
        $this->provincial = User::create(['role_id' => $adminRole, 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $this->provincial->forceFill(['admin_scope' => User::ADMIN_SCOPE_PROVINCIAL])->save();
        AdminScope::forgetOwnOffices();
        $this->owner = User::create(['role_id' => $ownerRole, 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $category = DB::table('categories')->insertGetId(['name' => 'Resort', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);

        $place = ['address' => 'Sta. Remedios', 'latitude' => 12.60, 'longitude' => 123.95];
        $this->resort = Destination::create($place + ['name' => "Isabel's Resort", 'description' => 'A pool resort.', 'category_id' => $category, 'municipality_id' => $this->bulan,
            'operating_hours' => '7:00 AM - 3:00 PM', 'created_by' => $this->provincial->id, 'status' => 'published']);
        $this->rooms = $this->stay("Isabel's Resort", $this->bulan);
    }

    private function stay(string $name, int $town, string $status = 'approved'): Accommodation
    {
        return Accommodation::create(['name' => $name, 'description' => 'Rooms.', 'type' => 'Resort', 'price_range' => 'moderate', 'municipality_id' => $town,
            'address' => 'Sta. Remedios', 'latitude' => 12.60, 'longitude' => 123.95, 'user_id' => $this->owner->id, 'status' => $status]);
    }

    private function link(?int $accommodationId)
    {
        return $this->actingAs($this->provincial)->putJson("/api/v1/admin/destinations/{$this->resort->id}", ['accommodation_id' => $accommodationId]);
    }

    public function test_the_tourism_office_links_a_destination_to_its_accommodation(): void
    {
        $options = $this->actingAs($this->provincial)->getJson('/api/v1/admin/destination-accommodation-options')->assertOk()->json('data');
        $this->assertSame([$this->rooms->id], array_column($options, 'id'));

        $this->link($this->rooms->id)->assertOk();
        $this->assertSame($this->rooms->id, (int) $this->resort->fresh()->accommodation_id);
        $this->assertSame($this->resort->id, $this->actingAs($this->provincial)->getJson('/api/v1/admin/destination-accommodation-options')->json('data.0.linked_destination_id'));

        $this->link(null)->assertOk();
        $this->assertNull($this->resort->fresh()->accommodation_id);
    }

    public function test_only_an_approved_free_accommodation_in_the_same_town_can_be_linked(): void
    {
        $elsewhere = $this->stay('Irosin Inn', $this->irosin);
        $pending = $this->stay('New Inn', $this->bulan, 'pending');
        $this->link($elsewhere->id)->assertUnprocessable()->assertJsonValidationErrors('accommodation_id');
        $this->link($pending->id)->assertUnprocessable()->assertJsonValidationErrors('accommodation_id');

        $other = Destination::create($this->resort->only(['description', 'category_id', 'municipality_id', 'address', 'latitude', 'longitude', 'created_by', 'status']) + ['name' => 'Another Place']);
        $other->forceFill(['accommodation_id' => $this->rooms->id])->save();
        $this->link($this->rooms->id)->assertUnprocessable()->assertJsonFragment(['accommodation_id' => ["Isabel's Resort is already linked to Another Place."]]);
    }

    public function test_the_public_pages_point_to_each_other_and_a_removed_stay_clears_the_link(): void
    {
        $this->resort->forceFill(['accommodation_id' => $this->rooms->id])->save();

        $this->getJson("/api/v1/destinations/{$this->resort->id}")->assertOk()
            ->assertJsonPath('destination.accommodation.id', $this->rooms->id)
            ->assertJsonPath('destination.accommodation.name', "Isabel's Resort");
        $this->getJson("/api/v1/accommodations/{$this->rooms->id}")->assertOk()
            ->assertJsonPath('day_use_destination.id', $this->resort->id);

        // A stay that isn't approved is not linked to publicly.
        $this->rooms->forceFill(['status' => 'pending'])->save();
        $this->getJson("/api/v1/destinations/{$this->resort->id}")->assertOk()->assertJsonPath('destination.accommodation', null);

        $this->rooms->delete();
        $this->assertNull($this->resort->fresh()->accommodation_id);
    }

    public function test_data_checks_find_a_resort_listed_both_ways_but_not_linked(): void
    {
        $codes = fn () => collect($this->actingAs($this->provincial)->getJson('/api/v1/admin/data-checks')->json('issues'))
            ->where('id', $this->resort->id)->where('type', 'destination')->pluck('code')->all();

        $this->assertContains('unlinked_twin', $codes());
        $this->resort->forceFill(['accommodation_id' => $this->rooms->id])->save();
        $this->assertNotContains('unlinked_twin', $codes());
    }
}

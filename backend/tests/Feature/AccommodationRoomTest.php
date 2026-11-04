<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccommodationRoomTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Accommodation $accommodation;

    protected function setUp(): void
    {
        parent::setUp();

        $roleId = Role::create(['name' => 'business_owner', 'description' => 'Business Establishment Owner'])->id;

        $municipalityId = DB::table('municipalities')->insertGetId([
            'name' => 'Bulan', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $barangayId = DB::table('barangays')->insertGetId([
            'municipality_id' => $municipalityId, 'name' => 'Zone 1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->owner = User::create([
            'role_id' => $roleId,
            'name' => 'Room Owner',
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
            'business_type' => 'accommodation',
        ]);

        $this->accommodation = Accommodation::create([
            'user_id' => $this->owner->id,
            'name' => 'Test Inn',
            'description' => 'A cozy pilot-area inn used for automated testing purposes only.',
            'type' => 'Accommodation',
            'municipality_id' => $municipalityId,
            'barangay_id' => $barangayId,
            'address' => '123 Test St.',
            'latitude' => 12.6744,
            'longitude' => 123.8756,
            'price_range' => 'moderate',
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    public function test_owner_can_add_rooms_up_to_the_limit(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->actingAs($this->owner)
                ->postJson('/api/v1/business/accommodation/rooms', ['room_number' => "Room {$i}", 'category' => 'Standard']);
            $response->assertCreated();
        }

        $this->assertSame(10, $this->accommodation->rooms()->count());
    }

    public function test_eleventh_room_is_rejected_with_the_capacity_message(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            AccommodationRoom::create(['accommodation_id' => $this->accommodation->id, 'room_number' => "Room {$i}"]);
        }

        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/business/accommodation/rooms', ['room_number' => 'One Too Many', 'category' => 'Standard']);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Maximum capacity reached: You have registered the limit of 10 rooms. Delete an existing room to add a new one.']);

        $this->assertSame(10, $this->accommodation->rooms()->count());
    }

    public function test_deleting_a_room_frees_a_slot(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            AccommodationRoom::create(['accommodation_id' => $this->accommodation->id, 'room_number' => "Room {$i}"]);
        }
        $toDelete = $this->accommodation->rooms()->first();

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/business/accommodation/rooms/{$toDelete->id}")
            ->assertOk();

        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/business/accommodation/rooms', ['room_number' => 'New Room', 'category' => 'Standard']);

        $response->assertCreated();
        $this->assertSame(10, $this->accommodation->rooms()->count());
    }

    public function test_toggling_occupancy_does_not_affect_approval_status(): void
    {
        $room = AccommodationRoom::create([
            'accommodation_id' => $this->accommodation->id,
            'room_number' => 'Deluxe Suite',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->actingAs($this->owner)
            ->patchJson("/api/v1/business/accommodation/rooms/{$room->id}/occupancy", ['occupancy_status' => 'occupied'])
            ->assertOk();

        $room->refresh();
        $this->assertSame('occupied', $room->occupancy_status);
        $this->assertSame('approved', $room->status);
    }

    public function test_editing_an_approved_room_keeps_it_live_and_holds_the_change_for_review(): void
    {
        $room = AccommodationRoom::create([
            'accommodation_id' => $this->accommodation->id,
            'room_number' => 'Deluxe Suite',
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/business/accommodation/rooms/{$room->id}", ['room_number' => 'Deluxe Suite (Renovated)'])
            ->assertOk();

        // Option 5b: the approved room stays public unchanged; the edit waits for review.
        $room->refresh();
        $this->assertSame('approved', $room->status);
        $this->assertNotNull($room->approved_at);
        $this->assertSame('Deluxe Suite', $room->room_number);
        $this->assertSame('pending', $room->pending_changes['status']);
        $this->assertSame('Deluxe Suite (Renovated)', $room->pending_changes['values']['room_number']);
    }

    public function test_editing_a_room_under_an_untrusted_owner_resets_it_to_pending(): void
    {
        $this->accommodation->update(['status' => 'pending']);

        $room = AccommodationRoom::create([
            'accommodation_id' => $this->accommodation->id,
            'room_number' => 'Deluxe Suite',
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $this->owner->id,
        ]);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/business/accommodation/rooms/{$room->id}", ['room_number' => 'Deluxe Suite (Renovated)'])
            ->assertOk();

        $room->refresh();
        $this->assertSame('pending', $room->status);
        $this->assertNull($room->approved_at);
    }

    public function test_new_rooms_stay_pending_even_when_the_accommodation_is_approved(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/business/accommodation/rooms', ['room_number' => 'Needs Review Room', 'category' => 'Standard']);

        // Option 5b: every new room is reviewed before tourists see it.
        $response->assertCreated();
        $this->assertSame('pending', $response->json('room.status'));
        $this->assertNull($response->json('room.approved_at'));
        $this->assertFalse(collect($this->getJson("/api/v1/accommodations/{$this->accommodation->id}")->json('rooms'))->pluck('room_number')->contains('Needs Review Room'));
    }

    public function test_new_rooms_stay_pending_when_the_accommodation_is_not_yet_approved(): void
    {
        $this->accommodation->update(['status' => 'pending']);

        $response = $this->actingAs($this->owner)
            ->postJson('/api/v1/business/accommodation/rooms', ['room_number' => 'Needs Review Room', 'category' => 'Standard']);

        $response->assertCreated();
        $this->assertSame('pending', $response->json('room.status'));
        $this->assertNull($response->json('room.approved_at'));
    }

    public function test_only_approved_rooms_are_visible_on_the_public_endpoint(): void
    {
        AccommodationRoom::create(['accommodation_id' => $this->accommodation->id, 'room_number' => 'Approved Room', 'status' => 'approved']);
        AccommodationRoom::create(['accommodation_id' => $this->accommodation->id, 'room_number' => 'Pending Room', 'status' => 'pending']);

        $response = $this->getJson("/api/v1/accommodations/{$this->accommodation->id}");

        $response->assertOk();
        $names = collect($response->json('rooms'))->pluck('room_number');
        $this->assertTrue($names->contains('Approved Room'));
        $this->assertFalse($names->contains('Pending Room'));
    }
}

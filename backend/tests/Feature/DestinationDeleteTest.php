<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Rating;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Deleting a destination in Manage Destinations removes it for good, even when trips include it. */
class DestinationDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_destination_in_saved_trips_is_deleted_with_its_stops_and_reviews(): void
    {
        $admin = User::create(['role_id' => Role::create(['name' => 'admin', 'description' => 'Admin'])->id, 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $admin->forceFill(['admin_scope' => User::ADMIN_SCOPE_PROVINCIAL])->save();
        AdminScope::forgetOwnOffices();
        $town = DB::table('municipalities')->insertGetId(['name' => 'Barcelona', 'created_at' => now(), 'updated_at' => now()]);
        $category = DB::table('categories')->insertGetId(['name' => 'Historical', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $place = fn (string $name) => Destination::create(['name' => $name, 'description' => 'A place.', 'category_id' => $category, 'municipality_id' => $town,
            'address' => 'Barcelona', 'latitude' => 12.86, 'longitude' => 124.14, 'created_by' => $admin->id, 'status' => 'published']);
        $school = $place('Colegio Digital Barcelona');
        $church = $place('Barcelona Church');

        $trip = DB::table('itineraries')->insertGetId(['user_id' => $admin->id, 'title' => 'Barcelona day', 'num_days' => 1, 'interests' => '["culture"]',
            'travel_pace' => 'moderate', 'budget' => 'budget', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$church, $school] as $i => $stop) {
            DB::table('itinerary_items')->insert(['itinerary_id' => $trip, 'destination_id' => $stop->id, 'day_number' => 1, 'sequence' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        Rating::create(['user_id' => $admin->id, 'rateable_type' => Destination::class, 'rateable_id' => $school->id, 'score' => 5, 'comment' => 'Nice.']);

        $this->actingAs($admin)->deleteJson("/api/v1/admin/destinations/{$school->id}")->assertOk();

        $this->assertNull(Destination::find($school->id));
        $this->assertSame(0, Rating::where('rateable_type', Destination::class)->where('rateable_id', $school->id)->count());
        $this->assertSame([$church->id], DB::table('itinerary_items')->where('itinerary_id', $trip)->pluck('destination_id')->all(), 'The trip keeps its other stops.');
    }
}

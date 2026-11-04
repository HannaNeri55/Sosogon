<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Admin correction of a listing's exact map location, and the coordinates the public page returns. */
class AdminListingLocationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tourist;

    private Accommodation $inn;

    protected function setUp(): void
    {
        parent::setUp();
        $roles = collect(['admin', 'tourist'])->mapWithKeys(fn ($n) => [$n => Role::create(['name' => $n, 'description' => $n])->id]);
        $this->admin = User::create(['role_id' => $roles['admin'], 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x')]);
        $this->tourist = User::create(['role_id' => $roles['tourist'], 'name' => 'Tourist', 'email' => 't@example.com', 'password' => bcrypt('x')]);
        $town = DB::table('municipalities')->insertGetId(['name' => 'Gubat', 'created_at' => now(), 'updated_at' => now()]);
        $this->inn = Accommodation::create([
            'name' => 'Seaside Inn', 'description' => 'Test inn for location checks only.', 'type' => 'Inn',
            'municipality_id' => $town, 'address' => 'Gubat', 'latitude' => 12.456, 'longitude' => 124.546,
            'price_range' => 'budget', 'status' => 'approved',
        ]);
    }

    public function test_admin_sets_exact_coordinates_and_the_public_page_returns_them(): void
    {
        $this->actingAs($this->admin)->putJson("/api/v1/admin/listing-locations/accommodation/{$this->inn->id}", [
            'address' => 'Purok 3, Barangay Buenavista, Gubat, Sorsogon',
            'latitude' => 12.9201234,
            'longitude' => 124.1225678,
        ])->assertOk()->assertJsonPath('message', 'Location saved.');

        $this->getJson("/api/v1/accommodations/{$this->inn->id}")->assertOk()
            ->assertJsonPath('address', 'Purok 3, Barangay Buenavista, Gubat, Sorsogon');
        $fresh = $this->inn->fresh();
        $this->assertEqualsWithDelta(12.9201234, (float) $fresh->latitude, 0.000001);
        $this->assertEqualsWithDelta(124.1225678, (float) $fresh->longitude, 0.000001);
        $this->assertTrue(ActivityLog::where('action', 'updated_listing_location')->where('model_id', $this->inn->id)->exists());
    }

    public function test_coordinates_are_validated_and_only_admins_may_change_them(): void
    {
        $url = "/api/v1/admin/listing-locations/accommodation/{$this->inn->id}";
        $this->actingAs($this->admin)->putJson($url, ['latitude' => 95, 'longitude' => 124])->assertUnprocessable()->assertJsonValidationErrors('latitude');
        $this->actingAs($this->admin)->putJson($url, ['latitude' => 12.9, 'longitude' => 190])->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->actingAs($this->admin)->putJson($url, ['latitude' => 12.9])->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->actingAs($this->tourist)->putJson($url, ['latitude' => 12.9, 'longitude' => 124.1])->assertForbidden();
        $this->actingAs($this->admin)->putJson('/api/v1/admin/listing-locations/hotel/1', ['latitude' => 12.9, 'longitude' => 124.1])->assertNotFound();

        $this->assertEquals(12.456, (float) $this->inn->fresh()->latitude);
    }

    public function test_admin_can_list_locations_by_type(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/admin/listing-locations/accommodation?q=Seaside')->assertOk()
            ->assertJsonPath('data.0.name', 'Seaside Inn')
            ->assertJsonPath('data.0.municipality.name', 'Gubat');
    }
}

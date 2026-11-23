<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Food at this place" in Manage Destinations: what is served at the destination itself and the
 * meals the trip planner plans there instead of a restaurant stop (meals_offered).
 */
class DestinationOnSiteFoodTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Destination $camp;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => 'admin', 'description' => 'Admin'])->id;
        $town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $category = DB::table('categories')->insertGetId(['name' => 'Mountain', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $this->admin = User::create(['role_id' => $role, 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $this->admin->forceFill(['admin_scope' => User::ADMIN_SCOPE_PROVINCIAL])->save();
        AdminScope::forgetOwnOffices();
        $this->camp = Destination::create(['name' => 'Camp Hiraya', 'description' => 'A mountain camp.', 'category_id' => $category, 'municipality_id' => $town,
            'address' => 'Bulan', 'latitude' => 12.6, 'longitude' => 123.9, 'created_by' => $this->admin->id, 'status' => 'published']);
    }

    private function save(array $fields)
    {
        return $this->actingAs($this->admin)->putJson("/api/v1/admin/destinations/{$this->camp->id}", $fields + ['meals_present' => true]);
    }

    public function test_an_admin_sets_and_clears_the_food_served_at_a_destination(): void
    {
        $this->save(['dining_details' => 'A kainan serves rice meals and grilled food.', 'meals_offered' => ['dinner', 'lunch']])->assertOk();
        $camp = $this->camp->fresh();
        $this->assertSame('A kainan serves rice meals and grilled food.', $camp->dining_details);
        $this->assertSame(['lunch', 'dinner'], $camp->meals_offered, 'Kept in day order.');

        // Every box unticked: nothing is served there any more (the text stays for tourists).
        $this->save(['dining_details' => 'No food is sold here; bring snacks.'])->assertOk();
        $this->assertSame([], $this->camp->fresh()->meals_offered);

        // Saving the rest of the form without the food section leaves it as it was.
        $this->save(['meals_offered' => ['lunch'], 'dining_details' => 'Canteen.'])->assertOk();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/destinations/{$this->camp->id}", ['name' => 'Camp Hiraya'])->assertOk();
        $this->assertSame(['lunch'], $this->camp->fresh()->meals_offered);
    }

    public function test_ticked_meals_need_a_description_and_only_known_meals_are_accepted(): void
    {
        $this->save(['dining_details' => null, 'meals_offered' => ['lunch']])->assertUnprocessable()
            ->assertJsonFragment(['dining_details' => ["Say what's served here, or untick the meals."]]);
        $this->save(['dining_details' => 'Canteen.', 'meals_offered' => ['brunch']])->assertUnprocessable()->assertJsonValidationErrors('meals_offered.0');
    }

    public function test_an_admin_marks_that_visitors_may_bring_their_own_food(): void
    {
        $this->save(['outside_food_allowed' => '1'])->assertOk();
        $this->assertTrue($this->camp->fresh()->outside_food_allowed);
        $this->getJson("/api/v1/destinations/{$this->camp->id}")->assertOk()->assertJsonPath('destination.outside_food_allowed', true);

        $this->save(['outside_food_allowed' => '0'])->assertOk();
        $this->assertFalse($this->camp->fresh()->outside_food_allowed);
    }
}

<?php

namespace Tests\Feature;

use App\Models\FoodBeverage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BusinessTypeAccessTest extends TestCase
{
    use RefreshDatabase;

    private int $municipalityId;

    private int $barangayId;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'business_owner', 'description' => 'Business Establishment Owner']);

        $this->municipalityId = DB::table('municipalities')->insertGetId([
            'name' => 'Bulan', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->barangayId = DB::table('barangays')->insertGetId([
            'municipality_id' => $this->municipalityId, 'name' => 'Zone 1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeOwner(?string $businessType): User
    {
        $roleId = Role::where('name', 'business_owner')->value('id');

        return User::create([
            'role_id' => $roleId,
            'name' => 'Owner',
            'email' => uniqid().'@example.com',
            'password' => bcrypt('password'),
            'business_type' => $businessType,
        ]);
    }

    public function test_owner_can_access_the_dashboard_matching_their_business_type(): void
    {
        $owner = $this->makeOwner('food_beverage');
        FoodBeverage::create([
            'user_id' => $owner->id,
            'name' => 'Test Eatery',
            'description' => 'A test establishment.',
            'municipality_id' => $this->municipalityId,
            'barangay_id' => $this->barangayId,
            'address' => '123 Test St.',
            'latitude' => 12.6744,
            'longitude' => 123.8756,
            'price_range' => 'moderate',
            'status' => 'approved',
        ]);

        $this->actingAs($owner)
            ->getJson('/api/v1/business/food-beverage')
            ->assertOk();
    }

    public function test_owner_is_blocked_from_a_dashboard_of_a_different_business_type(): void
    {
        $owner = $this->makeOwner('food_beverage');

        $this->actingAs($owner)
            ->getJson('/api/v1/business/accommodation')
            ->assertStatus(403);

        $this->actingAs($owner)
            ->getJson('/api/v1/business/retail-shop')
            ->assertStatus(403);
    }

    public function test_owner_with_no_business_type_is_blocked_from_all_three_dashboards(): void
    {
        $owner = $this->makeOwner(null);

        $this->actingAs($owner)->getJson('/api/v1/business/accommodation')->assertStatus(403);
        $this->actingAs($owner)->getJson('/api/v1/business/food-beverage')->assertStatus(403);
        $this->actingAs($owner)->getJson('/api/v1/business/retail-shop')->assertStatus(403);
    }

    public function test_submitting_a_listing_of_a_different_type_than_the_locked_type_is_rejected(): void
    {
        $owner = $this->makeOwner('accommodation');
        $file = \Illuminate\Http\UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf');

        $response = $this->actingAs($owner)->post('/api/v1/business/submissions', [
            'business_name' => 'Some Eatery',
            'type' => 'food_beverage',
            'description' => str_repeat('A test description. ', 5),
            'address' => '123 Test St.',
            'municipality_id' => $this->municipalityId,
            'barangay_id' => $this->barangayId,
            'latitude' => 12.6744,
            'longitude' => 123.8756,
            'contact_number' => '09171234567',
            'business_permit' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'You are registered as a accommodation business and can only submit listings of that type.']);
    }

    public function test_first_submission_locks_in_the_business_type_for_a_legacy_owner(): void
    {
        $owner = $this->makeOwner(null);

        $file = \Illuminate\Http\UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf');

        $response = $this->actingAs($owner)->post('/api/v1/business/submissions', [
            'business_name' => 'New Shop',
            'type' => 'retail_product',
            'description' => str_repeat('A test description. ', 5),
            'address' => '123 Test St.',
            'municipality_id' => $this->municipalityId,
            'barangay_id' => $this->barangayId,
            'latitude' => 12.6744,
            'longitude' => 123.8756,
            'contact_number' => '09171234567',
            'business_permit' => $file,
        ]);

        $response->assertCreated();
        $this->assertSame('retail_product', $owner->fresh()->business_type);

        $this->actingAs($owner)
            ->getJson('/api/v1/business/retail-shop')
            ->assertStatus(404); // no shop exists yet, but the type guard now lets the request through
    }
}

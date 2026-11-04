<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Distance-ranked nearby businesses on the destination detail endpoint. */
class DestinationNearbyTest extends TestCase
{
    use RefreshDatabase;

    private int $town;

    private int $otherTown;

    private int $category;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->town = $this->municipality('Barcelona');
        $this->otherTown = $this->municipality('Gubat');
        $this->category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $this->adminId = User::create([
            'role_id' => Role::create(['name' => 'admin', 'description' => 'Admin'])->id,
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
        ])->id;
    }

    private function municipality(string $name): int
    {
        $id = DB::table('municipalities')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('barangays')->insert(['municipality_id' => $id, 'name' => 'Zone 1', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    /** Fields for a listing $north km north of the destination (0.009° latitude ≈ 1 km). */
    private function fields(string $name, ?float $north, ?int $town = null, ?array $coords = null): array
    {
        $town ??= $this->town;

        return [
            'name' => $name,
            'description' => "{$name} used for automated testing purposes only.",
            'municipality_id' => $town,
            'barangay_id' => DB::table('barangays')->where('municipality_id', $town)->value('id'),
            'address' => 'Test St.',
            'latitude' => $coords[0] ?? ($north === null ? null : 12.870 + $north * 0.009),
            'longitude' => $coords[1] ?? ($north === null ? null : 124.140),
        ];
    }

    private function destination(array $coords = [12.870, 124.140]): Destination
    {
        return Destination::create([
            'name' => 'Test Spot', 'description' => 'Destination used for automated testing.',
            'category_id' => $this->category, 'municipality_id' => $this->town,
            'barangay_id' => DB::table('barangays')->where('municipality_id', $this->town)->value('id'),
            'address' => 'Test St.', 'latitude' => $coords[0], 'longitude' => $coords[1],
            'created_by' => $this->adminId, 'status' => 'published',
        ]);
    }

    private function inn(string $name, ?float $north, array $extra = [], ?int $town = null, ?array $coords = null): Accommodation
    {
        return Accommodation::create($extra + $this->fields($name, $north, $town, $coords) + ['type' => 'Inn', 'price_range' => 'budget', 'status' => 'approved']);
    }

    private function show(Destination $destination)
    {
        return $this->getJson("/api/v1/destinations/{$destination->id}")->assertOk();
    }

    public function test_businesses_are_ranked_by_distance_across_municipalities_within_the_radius(): void
    {
        $spot = $this->destination();
        $this->inn('Same Town Far Inn', 4);
        $this->inn('Other Town Near Inn', 0.3, town: $this->otherTown);
        $this->inn('Middle Inn', 2);
        $this->inn('Outside Radius Inn', 12);

        $response = $this->show($spot);

        $this->assertSame(['Other Town Near Inn', 'Middle Inn', 'Same Town Far Inn'], array_column($response->json('nearby_accommodations'), 'name'));
        $this->assertEqualsWithDelta(0.3, $response->json('nearby_accommodations.0.distance_km'), 0.01);
        $this->assertEquals(10, $response->json('nearby_radius_km'));
        $this->assertTrue($response->json('has_coordinates'));
    }

    public function test_each_section_only_contains_its_own_category(): void
    {
        $spot = $this->destination();
        $this->inn('Seaside Inn', 1);
        FoodBeverage::create($this->fields('Kainan', 1.5) + ['price_range' => 'budget', 'status' => 'approved']);
        RetailShop::create($this->fields('Pasalubong Center', 2) + ['status' => 'approved']);

        $response = $this->show($spot);

        $this->assertSame(['Seaside Inn'], array_column($response->json('nearby_accommodations'), 'name'));
        $this->assertSame(['Kainan'], array_column($response->json('nearby_food_beverages'), 'name'));
        $this->assertSame(['Pasalubong Center'], array_column($response->json('nearby_retail_shops'), 'name'));
    }

    public function test_hidden_and_unlocated_businesses_are_excluded_and_results_are_capped(): void
    {
        $spot = $this->destination();
        $this->inn('Pending Inn', 0.1, ['status' => 'pending']);
        // Only retail_shops allows missing coordinates; the 0,0 placeholder can occur anywhere.
        RetailShop::create($this->fields('No Coordinates Shop', null) + ['status' => 'approved']);
        $this->inn('Null Island Inn', null, coords: [0, 0]);
        RetailShop::create($this->fields('Pending Shop', 0.2) + ['status' => 'pending']);
        foreach (range(1, 7) as $i) {
            $this->inn("Inn {$i}", $i);
        }

        $response = $this->show($spot);

        $this->assertSame(['Inn 1', 'Inn 2', 'Inn 3', 'Inn 4', 'Inn 5'], array_column($response->json('nearby_accommodations'), 'name'));
        $this->assertSame([], $response->json('nearby_retail_shops'));
    }

    public function test_destination_without_coordinates_gets_no_guessed_results(): void
    {
        $spot = $this->destination([0, 0]);
        $this->inn('Same Town Inn', 1);

        $response = $this->show($spot);

        $this->assertFalse($response->json('has_coordinates'));
        $this->assertSame([], $response->json('nearby_accommodations'));
    }

    public function test_new_or_moved_businesses_are_picked_up_automatically(): void
    {
        $spot = $this->destination();
        $old = $this->inn('Old Inn', 3);
        $this->assertSame(['Old Inn'], array_column($this->show($spot)->json('nearby_accommodations'), 'name'));

        $this->inn('Brand New Inn', 0.5);
        $old->update(['latitude' => 12.870 + 20 * 0.009]);

        $this->assertSame(['Brand New Inn'], array_column($this->show($spot)->json('nearby_accommodations'), 'name'));
    }
}

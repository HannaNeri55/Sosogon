<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\FoodBeverage;
use App\Models\RetailShop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccommodationNearbyTest extends TestCase
{
    use RefreshDatabase;

    private int $town;

    private int $otherTown;

    protected function setUp(): void
    {
        parent::setUp();

        $this->town = $this->municipality('Bulan');
        $this->otherTown = $this->municipality('Matnog');
    }

    private function municipality(string $name): int
    {
        $id = DB::table('municipalities')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('barangays')->insert(['municipality_id' => $id, 'name' => 'Zone 1', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function fields(string $name, float $lat, float $lng, ?int $town = null): array
    {
        $town ??= $this->town;

        return [
            'name' => $name,
            'description' => "{$name} used for automated testing.",
            'municipality_id' => $town,
            'barangay_id' => DB::table('barangays')->where('municipality_id', $town)->value('id'),
            'address' => 'Test St.',
            'latitude' => $lat,
            'longitude' => $lng,
        ];
    }

    public function test_detail_lists_the_closest_approved_food_places_and_shops_from_any_town(): void
    {
        $stay = Accommodation::create($this->fields('Test Inn', 12.670, 123.870) + ['type' => 'Inn', 'price_range' => 'budget', 'status' => 'approved']);

        // Eight food places, 0.1 to 0.8 km north of the inn; the nearest is in another town.
        foreach (range(1, 8) as $i) {
            FoodBeverage::create($this->fields("Eatery {$i}", 12.670 + $i * 0.0009, 123.870, $i === 1 ? $this->otherTown : null)
                + ['price_range' => 'budget', 'status' => 'approved']);
        }
        FoodBeverage::create($this->fields('Pending Eatery', 12.6701, 123.870) + ['price_range' => 'budget', 'status' => 'pending']);
        RetailShop::create($this->fields('Far Shop', 12.700, 123.870) + ['status' => 'approved']);
        RetailShop::create($this->fields('Near Shop', 12.671, 123.870) + ['status' => 'approved']);

        $response = $this->getJson("/api/v1/accommodations/{$stay->id}")->assertOk();

        $this->assertSame('Test Inn', $response->json('name'), 'The accommodation fields stay at the top level.');
        $this->assertSame(
            ['Eatery 1', 'Eatery 2', 'Eatery 3', 'Eatery 4', 'Eatery 5', 'Eatery 6'],
            array_column($response->json('nearby_food_beverages'), 'name')
        );
        $this->assertEquals(0.1, $response->json('nearby_food_beverages.0.distance_km'));
        $this->assertSame(['Near Shop', 'Far Shop'], array_column($response->json('nearby_retail_shops'), 'name'));
    }
}

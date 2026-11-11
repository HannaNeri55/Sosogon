<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A stay that is a resort with its own pool or beach: the plan doesn't send the tourist to
 * another resort, with a faked AI reply that picks two resorts in one town:
 * - Seatown: a beach resort (Beach category, named "Resort"), a hill resort (Resort category),
 *   a park and a church, a resort stay with a pool, a hotel, and restaurants.
 */
class ItineraryResortStayTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private int $town;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'test-model',
            'services.gemini.fallback_models' => [],
            'services.google_maps.api_key' => '',
        ]);

        $roleId = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        $this->tourist = User::create([
            'role_id' => $roleId, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password'),
        ]);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Seatown', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1 => 'Beach', 4 => 'Historical', 6 => 'Park', 9 => 'Resort'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->ids = [
            'beachResort' => $this->destination('Sunny Beach Resort', 1, 12.676, 123.880, 'Swimming'),
            'hillResort' => $this->destination('Hilltop Resort', 9, 12.672, 123.884, 'Pool day'),
            'park' => $this->destination('Town Park', 6, 12.668, 123.878, 'Strolling'),
            'church' => $this->destination('Old Church', 4, 12.670, 123.876, 'Heritage tour', 'indoor'),
            'poolResort' => Accommodation::create($this->listing('Palm Resort', 12.671, 123.881) + [
                'type' => 'Resort', 'price_range' => 'moderate', 'status' => 'approved', 'amenities' => ['Free Wi-Fi', 'Swimming Pool'],
            ])->id,
            'hotel' => Accommodation::create($this->listing('Town Hotel', 12.669, 123.879) + [
                'type' => 'Hotel', 'price_range' => 'moderate', 'status' => 'approved', 'amenities' => ['Free Wi-Fi'],
            ])->id,
        ];
        foreach (range(0, 5) as $n) {
            FoodBeverage::create($this->listing("Eatery {$n}", 12.666 + $n * 0.002, 123.879) + [
                'cuisine_type' => 'Filipino', 'price_range' => 'moderate', 'status' => 'approved', 'operating_hours' => '6:00 AM - 10:00 PM',
            ]);
        }

        $reply = ['title' => 'Seatown', 'days' => [
            ['day_number' => 1, 'destinations' => [['destination_id' => $this->ids['hillResort'], 'reason' => 'Pools.']]],
            ['day_number' => 2, 'destinations' => [
                ['destination_id' => $this->ids['beachResort'], 'reason' => 'Beach.'],
                ['destination_id' => $this->ids['park'], 'reason' => 'Park.'],
            ]],
        ]];
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($reply)]]]]],
        ])]);
    }

    private function listing(string $name, float $lat, float $lng): array
    {
        return ['name' => $name, 'description' => "{$name} is a test listing.", 'municipality_id' => $this->town, 'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng];
    }

    private function destination(string $name, int $category, float $lat, float $lng, string $activity, string $type = 'outdoor'): int
    {
        $id = Destination::create($this->listing($name, $lat, $lng) + [
            'category_id' => $category, 'operating_hours' => '6:00 AM - 6:00 PM', 'entrance_fee' => 20, 'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        DB::table('activities')->insert(['destination_id' => $id, 'name' => $activity, 'type' => $type, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function generate(array $overrides = []): Itinerary
    {
        $start = now()->addDays(3);
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $overrides + [
            'municipality_ids' => [$this->town],
            'num_days' => 2,
            'interests' => ['relaxation', 'culture'],
            'travel_pace' => 'moderate',
            'budget' => 'moderate',
            'travelers' => 2,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
            'accommodation_needed' => true,
        ])->assertCreated();

        return Itinerary::with('items')->latest('id')->firstOrFail();
    }

    private function visited(Itinerary $itinerary): array
    {
        return $itinerary->items->pluck('destination_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function test_a_resort_stay_with_a_pool_gets_no_other_resort_as_a_destination(): void
    {
        $itinerary = $this->generate(['accommodation_id' => $this->ids['poolResort']]);
        $visited = $this->visited($itinerary);

        $this->assertNotContains($this->ids['hillResort'], $visited, 'A Resort-category destination is dropped.');
        $this->assertNotContains($this->ids['beachResort'], $visited, 'A destination named as a resort is dropped too.');
        $this->assertContains($this->ids['park'], $visited, 'Other places the AI picked stay.');
        $this->assertContains($this->ids['church'], $visited, 'Every day still has a place to visit.');
        foreach ($itinerary->items as $item) {
            $this->assertStringNotContainsString('Hilltop Resort', (string) $item->reason, 'No reason refers to a resort that was taken out.');
            $this->assertStringNotContainsString('Sunny Beach Resort', (string) $item->reason);
        }
    }

    public function test_a_dropped_resort_is_replaced_by_an_unused_place_in_the_town(): void
    {
        // More places than the days hold, so one is still unused when a resort is dropped.
        $spares = [
            $this->destination('Old Fort', 4, 12.673, 123.882, 'Heritage walk', 'indoor'),
            $this->destination('Bay Walk', 6, 12.674, 123.879, 'Strolling'),
            $this->destination('Plaza Garden', 6, 12.667, 123.883, 'Strolling'),
        ];

        $itinerary = $this->generate(['accommodation_id' => $this->ids['poolResort']]);
        $visited = $this->visited($itinerary);

        $this->assertNotContains($this->ids['hillResort'], $visited);
        $this->assertNotContains($this->ids['beachResort'], $visited);
        $replacement = $itinerary->items->first(fn ($i) => str_contains((string) $i->reason, 'Palm Resort already has its own pool or beach'));
        $this->assertNotNull($replacement, 'A dropped resort is replaced, and the reason says why.');
        $this->assertContains((int) $replacement->destination_id, [...$spares, $this->ids['church']]);
    }

    public function test_a_resort_the_tourist_asked_for_is_kept(): void
    {
        $itinerary = $this->generate(['accommodation_id' => $this->ids['poolResort'], 'preferred_destinations' => [$this->ids['hillResort']]]);

        $this->assertContains($this->ids['hillResort'], $this->visited($itinerary));
        $this->assertNotContains($this->ids['beachResort'], $this->visited($itinerary));
    }

    public function test_a_hotel_stay_still_gets_resort_destinations(): void
    {
        $visited = $this->visited($this->generate(['accommodation_id' => $this->ids['hotel']]));

        $this->assertContains($this->ids['hillResort'], $visited);
        $this->assertContains($this->ids['beachResort'], $visited);
    }
}

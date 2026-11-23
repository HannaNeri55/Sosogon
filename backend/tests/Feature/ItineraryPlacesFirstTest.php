<?php

namespace Tests\Feature;

use App\Contracts\ItineraryAI;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Role;
use App\Models\User;
use App\Services\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A trip about places, not restaurants: meals support the day. Without the Food interest a day
 * has one restaurant stop at most (dinner is at a stay with its own restaurant), a place that
 * serves dinner hosts it before its evening activity, and an empty afternoon gets another place
 * to visit, or says it's free time, never another meal.
 */
class ItineraryPlacesFirstTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private int $town;

    private array $ids = [];

    private object $ai;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_maps.api_key' => '', 'services.itinerary.daily_limit' => 1000]);
        Http::preventStrayRequests();
        $this->ai = new class implements ItineraryAI
        {
            public array $days = [];

            public function generate(string $prompt): string
            {
                return json_encode(['title' => 'Harbortown', 'days' => $this->days]);
            }
        };
        $this->app->instance(ItineraryAI::class, $this->ai);
        $this->app->forgetInstance(ItineraryService::class);

        $role = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        $this->tourist = User::create(['role_id' => $role, 'name' => 'Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password')]);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Harbortown', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1 => 'Beach', 2 => 'Mountain', 4 => 'Historical', 7 => 'Eco-Tourism'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->ids['museum'] = $this->place('Harbor Museum', 4, 12.670, 123.870, [['Museum tour', 90, 'Morning']]);
        $this->ids['park'] = $this->place('Mangrove Park', 7, 12.672, 123.874, [['Boardwalk stroll', 60, null]]);
        $this->ids['camp'] = $this->place('Hill Camp', 2, 12.676, 123.878, [['Bonfire', 60, 'Evening'], ['Picnic', 45, null]],
            ['dining_details' => 'A kainan inside the camp serves rice meals and grilled food.', 'meals_offered' => ['lunch', 'dinner']]);
        $this->ids['beach'] = $this->place('Picnic Cove', 1, 12.674, 123.876, [['Swimming', 150, null]], ['outside_food_allowed' => true]);
        $this->ids['hotel'] = Accommodation::create($this->listing('Harbor Hotel', 12.671, 123.872) + [
            'type' => 'Hotel', 'price_range' => 'moderate', 'status' => 'approved', 'amenities' => ['Restaurant', 'Free Wi-Fi'],
            'dining_details' => 'The hotel restaurant serves Filipino dishes.',
        ])->id;
        foreach (range(1, 4) as $n) {
            FoodBeverage::create($this->listing("Carinderia {$n}", 12.669 + $n * 0.001, 123.871) + [
                'cuisine_type' => 'Filipino', 'price_range' => 'moderate', 'status' => 'approved', 'operating_hours' => '6:00 AM - 10:00 PM',
            ]);
        }
    }

    private function listing(string $name, float $lat, float $lng): array
    {
        return ['name' => $name, 'description' => "{$name} is a test listing.", 'municipality_id' => $this->town, 'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng];
    }

    private function place(string $name, int $category, float $lat, float $lng, array $activities, array $fields = []): int
    {
        $id = Destination::create($this->listing($name, $lat, $lng) + $fields + [
            'category_id' => $category, 'operating_hours' => '6:00 AM - 10:00 PM', 'entrance_fee' => 20, 'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        foreach ($activities as [$activity, $minutes, $best]) {
            DB::table('activities')->insert(['destination_id' => $id, 'name' => $activity, 'duration_minutes' => $minutes, 'best_time_to_visit' => $best,
                'type' => 'outdoor', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    private function day(int $n, string ...$keys): array
    {
        return ['day_number' => $n, 'destinations' => array_map(fn ($k) => ['destination_id' => $this->ids[$k], 'reason' => 'A pick.'], $keys)];
    }

    private function generate(array $interests, array $overrides = []): Itinerary
    {
        $start = now()->addDays(3);
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $overrides + [
            'municipality_ids' => [$this->town], 'num_days' => 2, 'interests' => $interests, 'travel_pace' => 'moderate', 'budget' => 'moderate',
            'travelers' => 2, 'accommodation_id' => $this->ids['hotel'], 'transport_preference' => 'private_car',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDay()->toDateString(),
        ])->assertCreated();

        return Itinerary::with('items')->latest('id')->firstOrFail();
    }

    private function restaurantStops(Itinerary $itinerary, int $day)
    {
        return $itinerary->items->where('day_number', $day)->where('item_type', ItineraryItem::TYPE_FOOD_BEVERAGE);
    }

    public function test_a_day_has_one_restaurant_stop_and_dinner_at_the_hotels_restaurant(): void
    {
        $this->ai->days = [$this->day(1, 'museum', 'park'), $this->day(2, 'park')];
        $itinerary = $this->generate(['culture', 'nature']);

        $this->assertCount(1, $this->restaurantStops($itinerary, 1), 'Lunch only.');
        $dinner = $itinerary->items->first(fn ($i) => $i->day_number === 1 && $i->notes === 'Dinner at Harbor Hotel.');
        $this->assertNotNull($dinner);
        $this->assertStringContainsString('has its own restaurant, so dinner is there', $dinner->preference_note);

        // A tourist who picked Food gets the restaurant dinner.
        $foodie = $this->generate(['culture', 'nature', 'food']);
        $this->assertTrue($this->restaurantStops($foodie, 1)->contains(fn ($i) => str_starts_with((string) $i->notes, 'Dinner')));
    }

    public function test_a_place_that_serves_dinner_hosts_it_before_its_bonfire(): void
    {
        $this->ai->days = [$this->day(1, 'museum', 'camp'), $this->day(2, 'park')];
        $itinerary = $this->generate(['culture', 'nature']);

        $camp = $itinerary->items->firstWhere('destination_id', $this->ids['camp']);
        $this->assertNotNull($camp, 'The camp is in the trip, not left out.');
        $names = collect($camp->activities)->pluck('name')->all();
        $this->assertSame(['Dinner at the on-site dining area', 'Bonfire'], array_values(array_intersect($names, ['Dinner at the on-site dining area', 'Bonfire'])));
        $this->assertFalse($this->restaurantStops($itinerary, 1)->contains(fn ($i) => str_starts_with((string) $i->notes, 'Dinner')), 'No restaurant dinner first.');
    }

    public function test_an_empty_afternoon_gets_another_place_to_visit(): void
    {
        // One morning stop: the afternoon would be empty until dinner.
        $this->ai->days = [$this->day(1, 'museum'), $this->day(2, 'camp')];
        $itinerary = $this->generate(['culture', 'nature']);
        $this->assertContains($this->ids['park'], $itinerary->items->where('day_number', 1)->pluck('destination_id')->map(fn ($id) => (int) $id)->all());

        // A relaxed pace keeps its rest.
        $relaxed = $this->generate(['culture', 'nature'], ['travel_pace' => 'relaxed']);
        $this->assertNotContains($this->ids['park'], $relaxed->items->where('day_number', 1)->pluck('destination_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_where_visitors_may_bring_food_lunch_is_a_picnic_there_with_a_restaurant_offered(): void
    {
        // Arriving mid-morning, the swim runs through lunchtime.
        $this->ai->days = [$this->day(1, 'beach'), $this->day(2, 'museum')];
        $itinerary = $this->generate(['beaches', 'culture']);

        $beach = $itinerary->items->firstWhere('destination_id', $this->ids['beach']);
        $lunch = collect($beach->activities)->firstWhere('name', 'Lunch — your packed lunch');
        $this->assertNotNull($lunch, 'A picnic lunch at the beach.');
        $this->assertStringContainsString('Visitors may bring their own food here', $lunch['description']);
        $this->assertMatchesRegularExpression('/Rather not bring food\? Carinderia \d is about \d+ min away\./', $beach->preference_note);
        $this->assertFalse($this->restaurantStops($itinerary, 1)->contains(fn ($i) => str_starts_with((string) $i->notes, 'Lunch')), 'No trip to a restaurant for lunch.');

        // The same beach without it: lunch is at the nearby restaurant, as before.
        Destination::whereKey($this->ids['beach'])->update(['outside_food_allowed' => null]);
        $plain = $this->generate(['beaches', 'culture']);
        $this->assertTrue($this->restaurantStops($plain, 1)->contains(fn ($i) => str_starts_with((string) $i->notes, 'Lunch')));
    }
}

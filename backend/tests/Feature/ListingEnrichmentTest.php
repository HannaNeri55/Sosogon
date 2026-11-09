<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\Role;
use App\Models\User;
use App\Services\ItineraryService;
use Database\Seeders\ListingEnrichmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The enrichment seeder (structured dining, activities, amenities, room types, meals served,
 * product categories) and how the planner uses the new details.
 */
class ListingEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private int $town;

    private array $prompts = [];

    private array $reply = [];

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
        $this->tourist = User::create(['role_id' => $roleId, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password')]);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Testville', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('categories')->insert(['id' => 1, 'name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function place(array $fields): array
    {
        return $fields + ['description' => 'A test listing.', 'municipality_id' => $this->town, 'address' => 'Test St.', 'latitude' => 12.67, 'longitude' => 123.88, 'created_at' => now(), 'updated_at' => now()];
    }

    /** Listings with the IDs and names the seeder's data expects, plus one renamed listing. */
    private function seedListings(): void
    {
        DB::table('destinations')->insert($this->place(['id' => 15, 'name' => 'Rompeolas', 'category_id' => 1, 'created_by' => $this->tourist->id, 'status' => 'published']));
        DB::table('destinations')->insert($this->place(['id' => 2, 'name' => 'Not Camp Hiraya', 'category_id' => 1, 'created_by' => $this->tourist->id, 'status' => 'published']));
        DB::table('accommodations')->insert($this->place(['id' => 22, 'name' => 'Elysia Beach Resort', 'type' => 'Resort', 'price_range' => 'moderate', 'status' => 'approved']));
        DB::table('accommodations')->insert($this->place(['id' => 19, 'name' => "Vivi's Hotel and Resto Bar", 'type' => 'Accommodation', 'price_range' => 'moderate', 'status' => 'approved']));
        DB::table('accommodation_rooms')->insert(['accommodation_id' => 19, 'room_number' => '1', 'category' => 'Standard', 'price' => 1200, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('food_beverages')->insert($this->place(['id' => 13, 'name' => 'Lola Feling Restaurant', 'cuisine_type' => 'Filipino', 'price_range' => 'budget', 'status' => 'approved', 'operating_hours' => 'Daily, 6:30 AM – 8:00 PM']));
        DB::table('food_beverages')->insert($this->place(['id' => 2, 'name' => 'Balay Buhay sa Uma', 'price_range' => 'moderate', 'status' => 'approved', 'operating_hours' => 'Daytime']));
        DB::table('retail_shops')->insert($this->place(['id' => 7, 'name' => 'PiliNuts Pasalubong Center', 'status' => 'approved']));
    }

    public function test_the_seeder_enriches_matching_listings_and_can_run_again_without_duplicates(): void
    {
        $this->seedListings();
        $this->seed(ListingEnrichmentSeeder::class);
        $counts = fn () => [DB::table('activities')->count(), DB::table('destination_amenities')->count(), DB::table('accommodation_room_types')->count()];
        $first = $counts();
        $this->seed(ListingEnrichmentSeeder::class);
        $this->assertSame($first, $counts(), 'A second run adds nothing.');

        $rompeolas = Destination::find(15);
        $this->assertStringContainsString('Rompeolas Food Court', $rompeolas->dining_details);
        $this->assertSame(['breakfast', 'lunch', 'merienda', 'dinner'], $rompeolas->meals_offered);
        $this->assertContains('Bay walk', $rompeolas->activities()->pluck('name')->all());
        // Coordinates, status and owner are never touched.
        $this->assertEquals([12.67, 123.88, 'published', $this->tourist->id], [(float) $rompeolas->latitude, (float) $rompeolas->longitude, $rompeolas->status, $rompeolas->created_by]);

        // A listing whose name no longer matches is left alone.
        $this->assertNull(Destination::find(2)->dining_details);
        $this->assertSame(0, DB::table('activities')->where('destination_id', 2)->count());

        // Room types: a cited published rate, and a range from the stay's own priced rooms.
        $elysia = Accommodation::with('roomTypes')->find(22);
        $this->assertSame(['Deluxe Room', 'Super Deluxe Room'], $elysia->roomTypes->pluck('name')->all());
        $this->assertEquals(3129, (float) $elysia->roomTypes->first()->price_min);
        $this->assertStringContainsString('undated', $elysia->roomTypes->first()->price_note);
        $vivis = Accommodation::with('roomTypes')->find(19)->roomTypes->first();
        $this->assertEquals([1200.0, 1200.0], [(float) $vivis->price_min, (float) $vivis->price_max]);
        $this->assertSame(1, DB::table('accommodation_rooms')->count(), 'Room types never become bookable rooms.');

        $lola = FoodBeverage::find(13);
        $this->assertSame(['breakfast', 'lunch', 'merienda', 'dinner'], $lola->meals_served);
        $this->assertSame('Daily, 6:30 AM – 8:00 PM', $lola->operating_hours, 'Listed hours are kept.');
        $this->assertSame('Daily, 8:00 AM – 5:00 PM', FoodBeverage::find(2)->operating_hours, 'A vague "Daytime" gets the sourced hours.');
        $this->assertContains('Pili nut products', DB::table('retail_shops')->where('id', 7)->value('product_categories') ? json_decode(DB::table('retail_shops')->where('id', 7)->value('product_categories'), true) : []);
    }

    public function test_the_public_pages_return_the_new_details(): void
    {
        $this->seedListings();
        $this->seed(ListingEnrichmentSeeder::class);

        $this->getJson('/api/v1/accommodations/22')->assertOk()
            ->assertJsonPath('room_types.0.name', 'Deluxe Room')
            ->assertJsonPath('room_types.0.max_guests', 2)
            ->assertJsonMissingPath('room_types.0.source_url');
        $this->getJson('/api/v1/destinations/15')->assertOk()->assertJsonPath('destination.meals_offered.1', 'lunch');
        $this->getJson('/api/v1/food-beverages/13')->assertOk()->assertJsonPath('meals_served.0', 'breakfast');
    }

    /** A beach with its own food: two long activities cover the morning and the early afternoon. */
    private function generateWithOnSiteFood(bool $food): Itinerary
    {
        $beach = Destination::create($this->place(['name' => 'Food Beach', 'category_id' => 1, 'created_by' => $this->tourist->id, 'status' => 'published',
            'operating_hours' => '6:00 AM - 6:00 PM', 'entrance_fee' => 20]) + ($food ? ['dining_details' => 'A beach canteen serves grilled fish and rice.', 'meals_offered' => ['lunch', 'merienda']] : []));
        foreach (['Swimming', 'Snorkeling'] as $name) {
            DB::table('activities')->insert(['destination_id' => $beach->id, 'name' => $name, 'type' => 'outdoor', 'duration_minutes' => 180, 'created_at' => now(), 'updated_at' => now()]);
        }
        Accommodation::create($this->place(['name' => 'Town Inn', 'type' => 'Inn', 'price_range' => 'moderate', 'status' => 'approved', 'latitude' => 12.671, 'longitude' => 123.881]));
        FoodBeverage::create($this->place(['name' => 'Next Door Eatery', 'cuisine_type' => 'Filipino', 'price_range' => 'moderate', 'status' => 'approved', 'operating_hours' => '6:00 AM - 10:00 PM', 'latitude' => 12.6705, 'longitude' => 123.8805]));
        FoodBeverage::create($this->place(['name' => 'Town Grill', 'cuisine_type' => 'Filipino', 'price_range' => 'moderate', 'status' => 'approved', 'operating_hours' => '6:00 AM - 10:00 PM', 'latitude' => 12.6712, 'longitude' => 123.8812]));
        // More restaurants, so meals don't run out (no food place repeats in a trip).
        foreach (range(1, 4) as $n) {
            FoodBeverage::create($this->place(['name' => "Eatery {$n}", 'cuisine_type' => 'Filipino', 'price_range' => 'moderate', 'status' => 'approved', 'operating_hours' => '6:00 AM - 10:00 PM', 'latitude' => 12.6700 + $n * 0.0004, 'longitude' => 123.8800 + $n * 0.0004]));
        }

        $this->reply = ['title' => 'Beach day', 'days' => [
            ['day_number' => 1, 'destinations' => []],
            ['day_number' => 2, 'destinations' => [['destination_id' => $beach->id, 'reason' => 'Beach day.']]],
        ]];
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => function ($request) {
            $this->prompts[] = $request->data()['contents'][0]['parts'][0]['text'] ?? '';

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($this->reply)]]]]]]);
        }]);
        $start = now()->addDays(3);
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', [
            'municipality_ids' => [$this->town], 'num_days' => 2, 'interests' => ['nature'], 'travel_pace' => 'moderate', 'budget' => 'moderate',
            'travelers' => 2, 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDay()->toDateString(),
        ])->assertCreated();

        return Itinerary::with('items')->latest('id')->firstOrFail();
    }

    public function test_a_destination_with_its_own_food_serves_lunch_without_a_restaurant_stop(): void
    {
        $itinerary = $this->generateWithOnSiteFood(true);
        $day2 = $itinerary->items->where('day_number', 2);
        $visit = $day2->firstWhere('item_type', 'destination');

        $this->assertFalse($day2->contains(fn ($i) => str_starts_with((string) $i->notes, 'Lunch')), 'No separate lunch stop.');
        $lunch = collect($visit->activities)->firstWhere('name', 'Lunch at the on-site dining area');
        $this->assertNotNull($lunch);
        $this->assertSame('12:00 PM – 1:00 PM', $lunch['time']);
        $this->assertStringContainsString("Food Beach's own dining area", $visit->preference_note);
        $rows = $itinerary->items->map(fn ($i) => $i->only(['day_number', 'sequence', 'item_type', 'destination_id', 'time_slot', 'notes', 'travel_time_from_prev']))->all();
        $this->assertSame([], app(ItineraryService::class)->scheduleProblems($rows, Destination::all(), ['start_date' => $itinerary->start_date]));

        $prompt = end($this->prompts);
        $this->assertStringContainsString('Food on site: A beach canteen serves grilled fish and rice. (meals: lunch, merienda)', $prompt);
        $this->assertStringContainsString('5d. A destination with "Food on site"', $prompt);
    }

    public function test_without_food_on_site_lunch_is_still_a_restaurant_break(): void
    {
        $itinerary = $this->generateWithOnSiteFood(false);
        $day2 = $itinerary->items->where('day_number', 2);

        $this->assertTrue($day2->contains(fn ($i) => $i->item_type === 'food_beverage' && str_starts_with((string) $i->notes, 'Lunch')));
        $this->assertNull(collect($day2->firstWhere('item_type', 'destination')->activities)->firstWhere('name', 'Lunch at the on-site dining area'));
        $this->assertStringNotContainsString('Food on site', end($this->prompts));
    }

    public function test_a_food_place_that_lists_the_meal_is_preferred_for_it(): void
    {
        $score = fn (FoodBeverage $f, string $meal) => (new \ReflectionMethod(ItineraryService::class, 'foodScore'))->invoke(app(ItineraryService::class), $f, ['budget' => 'moderate'], $meal);
        $breakfastPlace = new FoodBeverage(['price_range' => 'moderate', 'meals_served' => ['breakfast', 'lunch']]);
        $dinnerOnly = new FoodBeverage(['price_range' => 'moderate', 'meals_served' => ['dinner']]);
        $unknown = new FoodBeverage(['price_range' => 'moderate']);

        $this->assertGreaterThan($score($dinnerOnly, 'breakfast'), $score($breakfastPlace, 'breakfast'));
        $this->assertSame($score($unknown, 'breakfast'), $score($dinnerOnly, 'breakfast'), 'Not listing a meal is no penalty.');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\MenuItem;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Generation with a faked AI reply over a small town:
 * - Hill (north): sunrise viewing, open 5 AM–6 PM, with one food place next to it.
 * - Beach (south): sunset watching and a picnic spot, no food nearby, a souvenir shop.
 * - Falls (east): nothing nearby.
 * - Only two food places in town, a hotel in the middle and a resort far away.
 */
class ItineraryGenerationTest extends TestCase
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

        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('barangays')->insert(['municipality_id' => $this->town, 'name' => 'Zone 1', 'created_at' => now(), 'updated_at' => now()]);
        // Category id 1 is one of the "nature" categories.
        DB::table('categories')->insert(['id' => 1, 'name' => 'Nature', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);

        $hill = $this->destination('Hill', 12.70, 123.87, '5:00 AM - 6:00 PM');
        $beach = $this->destination('Beach', 12.64, 123.87, null);
        $falls = $this->destination('Falls', 12.67, 123.92, null);
        $this->activity($hill, 'Sunrise Viewing at Hill', 'Around dawn, 5:00 AM to 6:30 AM');
        $this->activity($beach, 'Sunset Watching at Beach', 'Late afternoon, 4:30 PM to 6:00 PM');
        $this->activity($beach, 'Picnic by the shore', 'Daytime');

        $hotel = Accommodation::create($this->listing('Central Hotel', 12.67, 123.885) + ['type' => 'Hotel', 'price_range' => 'moderate', 'status' => 'approved']);
        Accommodation::create($this->listing('Far Resort', 12.60, 123.80) + ['type' => 'Resort', 'price_range' => 'budget', 'status' => 'approved']);
        $foodByStay = FoodBeverage::create($this->listing('Stay Seafood', 12.671, 123.885) + ['cuisine_type' => 'Seafood', 'price_range' => 'budget', 'status' => 'approved']);
        $foodByHill = FoodBeverage::create($this->listing('Hill Eatery', 12.701, 123.871) + ['cuisine_type' => 'Filipino', 'price_range' => 'moderate', 'status' => 'approved']);
        $shop = RetailShop::create($this->listing('Beach Pasalubong', 12.641, 123.871) + ['status' => 'approved']);

        foreach (['Standard' => 1500, 'Deluxe' => 2500, 'Suite' => 5000] as $category => $price) {
            foreach ([1, 2] as $n) {
                AccommodationRoom::create([
                    'accommodation_id' => $hotel->id, 'room_number' => "{$category} {$n}", 'category' => $category,
                    'price' => $price, 'status' => 'approved',
                ]);
            }
        }
        foreach ([300, 50, 200, 80, 120] as $price) {
            MenuItem::create(['food_beverage_id' => $foodByHill->id, 'name' => "Dish {$price}", 'price' => $price, 'status' => 'approved']);
        }
        foreach ([250, 100, 150] as $price) {
            MenuItem::create(['food_beverage_id' => $foodByStay->id, 'name' => "Seafood {$price}", 'price' => $price, 'status' => 'approved']);
        }
        foreach ([400, 30, 90, 60, null] as $price) {
            RetailProduct::create([
                'retail_shop_id' => $shop->id, 'name' => 'Souvenir '.($price ?? 'unpriced'), 'description' => 'Test souvenir.',
                'product_type' => 'Souvenir', 'municipality_id' => $this->town, 'price' => $price, 'status' => 'approved',
            ]);
        }

        $this->ids = compact('hill', 'beach', 'falls') + ['hotel' => $hotel->id, 'foodByStay' => $foodByStay->id, 'foodByHill' => $foodByHill->id, 'shop' => $shop->id];

        // The AI labels the sunrise and sunset activities "morning" on purpose: the planner
        // must place them by what they are, not by the label. Day 1 is the arrival day, so the
        // sunrise hill comes on day 2.
        $reply = [
            'title' => 'Three days around Bulan',
            'days' => [
                ['day_number' => 1, 'destination_id' => $falls, 'reason' => 'Cool water.', 'activities' => [
                    ['name' => 'Explore the falls', 'duration_minutes' => 120, 'time_of_day' => 'anytime'],
                ]],
                ['day_number' => 2, 'destination_id' => $hill, 'reason' => 'Great views.', 'activities' => [
                    ['name' => 'Sunrise Viewing at Hill', 'duration_minutes' => 60, 'time_of_day' => 'morning'],
                    ['name' => 'Nature walk', 'duration_minutes' => 90, 'time_of_day' => 'anytime'],
                ]],
                ['day_number' => 3, 'destination_id' => $beach, 'reason' => 'Relaxing shore.', 'activities' => [
                    ['name' => 'Sunset Watching at Beach', 'duration_minutes' => 60, 'time_of_day' => 'morning'],
                    ['name' => 'Swimming', 'duration_minutes' => 120, 'time_of_day' => 'anytime'],
                ]],
            ],
        ];
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($reply)]]]]],
        ])]);
    }

    private function listing(string $name, float $lat, float $lng): array
    {
        return [
            'name' => $name,
            'description' => "{$name} used for automated testing.",
            'municipality_id' => $this->town,
            'barangay_id' => DB::table('barangays')->value('id'),
            'address' => 'Test St.',
            'latitude' => $lat,
            'longitude' => $lng,
        ];
    }

    private function destination(string $name, float $lat, float $lng, ?string $hours): int
    {
        return Destination::create($this->listing($name, $lat, $lng) + [
            'category_id' => 1, 'operating_hours' => $hours, 'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
    }

    private function activity(int $destinationId, string $name, string $bestTime): void
    {
        DB::table('activities')->insert([
            'destination_id' => $destinationId, 'name' => $name, 'best_time_to_visit' => $bestTime,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function generate(int $travelers = 2, string $pace = 'moderate', string $budget = 'budget', ?string $notes = null): Itinerary
    {
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', array_filter([
            'municipality_id' => $this->town,
            'num_days' => 3,
            'interests' => ['nature'],
            'travel_pace' => $pace,
            'budget' => $budget,
            'travelers' => $travelers,
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'food_preference' => 'seafood',
            'accommodation_type' => 'resort',
            'additional_preferences' => $notes,
        ]))->assertCreated();

        return Itinerary::with(['items.dishes', 'items.souvenirs', 'rooms'])->latest('id')->firstOrFail();
    }

    /** "5:15 AM – 6:15 AM" → [315, 375] */
    private function minutes(string $range): array
    {
        return array_map(function ($part) {
            $t = Carbon::createFromFormat('g:i A', trim($part));

            return $t->hour * 60 + $t->minute;
        }, explode('–', $range));
    }

    private function activityOn(Itinerary $itinerary, int $day, string $name): array
    {
        $visit = $itinerary->items->first(fn ($i) => $i->day_number === $day && $i->item_type === 'destination');
        $activity = collect($visit->activities)->first(fn ($a) => str_contains($a['name'], $name));
        $this->assertNotNull($activity, "Day {$day} has no \"{$name}\" activity.");

        return $this->minutes($activity['time']);
    }

    public function test_no_destination_food_place_or_shop_repeats(): void
    {
        $items = $this->generate()->items;

        foreach (['destination_id', 'food_beverage_id', 'retail_shop_id'] as $column) {
            $ids = $items->pluck($column)->filter()->values();
            $this->assertSame($ids->unique()->count(), $ids->count(), "{$column} repeats.");
        }
        $this->assertSame(3, $items->where('item_type', 'destination')->count(), 'Each destination should be one stop.');
    }

    public function test_sunrise_and_sunset_activities_are_scheduled_at_their_time_of_day(): void
    {
        // A sunrise (before breakfast) only when the tourist asks for an early start.
        $itinerary = $this->generate(notes: 'We would love to catch the sunrise.');

        [$start, $end] = $this->activityOn($itinerary, 2, 'Sunrise Viewing');
        $this->assertGreaterThanOrEqual(300, $start);
        $this->assertLessThanOrEqual(390, $end);
        // Breakfast is the packed one, right after sunrise and over by 8:00 AM.
        [$start, $end] = $this->activityOn($itinerary, 2, 'packed breakfast');
        $this->assertGreaterThanOrEqual(360, $start);
        $this->assertLessThanOrEqual(480, $end);

        [$start, $end] = $this->activityOn($itinerary, 3, 'Sunset Watching');
        $this->assertGreaterThanOrEqual(990, $start);
        $this->assertLessThanOrEqual(1080, $end);

        // The hill closes at 6 PM, so its visit can't run later.
        $hillVisit = $itinerary->items->first(fn ($i) => $i->day_number === 2 && $i->item_type === 'destination');
        $this->assertLessThanOrEqual(1080, $this->minutes($hillVisit->time_slot)[1]);
    }

    public function test_a_sunset_destination_starts_in_the_afternoon_and_meals_move_to_the_stay_with_an_apology(): void
    {
        $itinerary = $this->generate();

        // The beach is for the sunset: the visit starts just in time, after lunch, instead of
        // waiting there from the morning.
        $beachVisit = $itinerary->items->first(fn ($i) => $i->day_number === 3 && $i->item_type === 'destination');
        $this->assertGreaterThanOrEqual(900, $this->minutes($beachVisit->time_slot)[0]);
        $lunch = $itinerary->items->first(fn ($i) => $i->day_number === 3 && str_starts_with((string) $i->notes, 'Lunch'));
        $this->assertNotNull($lunch);
        $this->assertLessThan($beachVisit->sequence, $lunch->sequence);

        $mealsAtStay = $itinerary->items->filter(fn ($i) => $i->item_type === 'accommodation' && preg_match('/^(Breakfast|Dinner) at/', $i->notes));
        $this->assertNotEmpty($mealsAtStay);
        // Breakfast at the stay is normal: no note. Nothing apologizes, and nothing is said twice.
        $this->assertFalse($itinerary->items->contains(fn ($i) => str_contains((string) $i->preference_note, "We're sorry")));
        $notes = $itinerary->items->pluck('preference_note')->filter()->values();
        $this->assertSame($notes->unique()->count(), $notes->count(), 'No note is repeated word for word.');
    }

    public function test_budget_picks_the_cheapest_dishes_souvenirs_and_rooms_for_the_group(): void
    {
        $itinerary = $this->generate(travelers: 5);

        // Lunch dishes: one main dish per traveler, the cheapest on the menu (dinner keeps 3,
        // see ItineraryMealQualityTest).
        $lunch = $itinerary->items->first(fn ($i) => $i->food_beverage_id === $this->ids['foodByHill']);
        $this->assertStringStartsWith('Lunch', $lunch->notes);
        $this->assertEqualsCanonicalizing([50.0], $lunch->dishes->pluck('price')->map(fn ($p) => (float) $p)->all());
        $this->assertTrue($lunch->dishes->every(fn ($d) => $d->pivot->quantity === 5));
        $this->assertEquals(50 * 5, (float) $lunch->cost_max);
        $this->assertSame('1 dish × 5', $lunch->cost_note);

        // Souvenirs: the 3 cheapest priced products, one of each for the group.
        $shopStop = $itinerary->items->first(fn ($i) => $i->retail_shop_id === $this->ids['shop']);
        $this->assertEqualsCanonicalizing([30.0, 60.0, 90.0], $shopStop->souvenirs->pluck('price')->map(fn ($p) => (float) $p)->all());
        $this->assertEquals(180, (float) $shopStop->cost_max);

        // Rooms: 5 travelers on a budget = 2 Standard (sleep 4) + 1 Deluxe, for 2 nights.
        $this->assertEqualsCanonicalizing(['Standard', 'Standard', 'Deluxe'], $itinerary->rooms->pluck('category')->all());
        $this->assertEquals((1500 + 1500 + 2500) * 2, (float) $itinerary->accommodation_cost_max);

        $this->assertEquals(
            (float) $itinerary->accommodation_cost_max + $itinerary->items->sum(fn ($i) => (float) $i->cost_max),
            (float) $itinerary->estimated_cost_max
        );
    }

    public function test_moderate_and_luxury_budgets_pick_mid_and_top_range_dishes_souvenirs_and_rooms(): void
    {
        $prices = fn ($rows) => $rows->pluck('price')->map(fn ($p) => (float) $p)->sort()->values()->all();

        // Menu 50/80/120/200/300 (its lunch: one dish), souvenirs 30/60/90/400, rooms Standard ₱1,500 / Deluxe ₱2,500 / Suite ₱5,000.
        $moderate = $this->generate(travelers: 2, budget: 'moderate');
        $this->assertSame([120.0], $prices($moderate->items->first(fn ($i) => $i->food_beverage_id === $this->ids['foodByHill'])->dishes));
        $this->assertSame([30.0, 60.0, 90.0], $prices($moderate->items->first(fn ($i) => $i->retail_shop_id === $this->ids['shop'])->souvenirs));
        $this->assertSame(['Deluxe'], $moderate->rooms->pluck('category')->all(), 'The middle room category, enough for 2.');

        $luxury = $this->generate(travelers: 2, budget: 'luxury');
        $this->assertSame([300.0], $prices($luxury->items->first(fn ($i) => $i->food_beverage_id === $this->ids['foodByHill'])->dishes));
        $this->assertSame([60.0, 90.0, 400.0], $prices($luxury->items->first(fn ($i) => $i->retail_shop_id === $this->ids['shop'])->souvenirs));
        $this->assertSame(['Suite'], $luxury->rooms->pluck('category')->all(), 'The top room category.');
        $this->assertGreaterThan((float) $moderate->accommodation_cost_max, (float) $luxury->accommodation_cost_max);
    }

    public function test_the_pace_sets_breakfast_activity_length_and_a_merienda(): void
    {
        $moderate = $this->generate(notes: 'Early start, we want the sunrise.');
        $breakfast = $moderate->items->first(fn ($i) => $i->day_number === 3 && str_starts_with((string) $i->notes, 'Breakfast'));
        $this->assertSame(420, $this->minutes($breakfast->time_slot)[0], 'Moderate breakfast is at 7:00 AM.');
        [$start, $end] = $this->activityOn($moderate, 2, 'Sunrise Viewing');
        $this->assertSame(60, $end - $start);

        $relaxed = $this->generate(pace: 'relaxed');
        // No sunrise on a relaxed pace: sunrise viewing isn't put at mid-morning; the visit stays.
        $hill = $relaxed->items->first(fn ($i) => $i->day_number === 2 && $i->item_type === 'destination');
        $this->assertNotNull($hill);
        $this->assertFalse(collect($hill->activities)->contains(fn ($a) => str_contains($a['name'], 'Sunrise')));

        // Snack places by the hotel, where the packed day rests after its sunrise, for the
        // afternoon merienda after lunch there.
        foreach (['Snack Corner' => 12.672, 'Kakanin Stall' => 12.673] as $name => $lat) {
            FoodBeverage::create($this->listing($name, $lat, 123.886) + ['cuisine_type' => 'Snacks & Desserts', 'price_range' => 'budget', 'status' => 'approved']);
        }
        $packed = $this->generate(pace: 'packed', notes: 'Early start, we want the sunrise.');
        [$start, $end] = $this->activityOn($packed, 2, 'Sunrise Viewing');
        $this->assertSame(45, $end - $start);
        $this->assertTrue($packed->items->contains(fn ($i) => str_starts_with((string) $i->notes, 'Afternoon merienda')));
    }

    public function test_each_visit_lists_only_its_own_activities(): void
    {
        $itinerary = $this->generate();

        $listed = DB::table('activities')->get()->groupBy('destination_id');
        foreach ($itinerary->items->where('item_type', 'destination') as $visit) {
            $names = collect($visit->activities)->pluck('name')->map(fn ($n) => preg_replace('/ — your picnic lunch$/', '', $n))->all();
            $expected = [...($listed->get($visit->destination_id)?->pluck('name')->all() ?? []), 'Explore '.Destination::find($visit->destination_id)->name];
            // Only its own activities; one whose time has passed (a midday picnic at a sunset visit) is left out.
            $this->assertNotEmpty($names);
            $this->assertSame([], array_values(array_diff($names, $expected)), "Day {$visit->day_number} should list only its destination's activities.");
        }
    }

    public function test_stay_is_central_and_a_type_mismatch_is_explained(): void
    {
        $itinerary = $this->generate();

        $this->assertSame($this->ids['hotel'], $itinerary->accommodation_id);
        $stay = $itinerary->stays()->sole();
        $this->assertSame($this->ids['hotel'], $stay->accommodation_id);
        $this->assertSame([1, 2], $stay->night_days);
        $this->assertStringContainsString('Resort', $itinerary->accommodation_note);
        $this->assertStringContainsString('Central Hotel', $itinerary->accommodation_note);

        // The hill eatery is Filipino and moderate, not the seafood/budget the form asked for.
        $hillLunch = $itinerary->items->first(fn ($i) => $i->food_beverage_id === $this->ids['foodByHill']);
        $this->assertNotNull($hillLunch);
        $this->assertStringContainsString('seafood', $hillLunch->preference_note);
    }

    public function test_hidden_sample_listings_are_never_planned(): void
    {
        config(['listings.show_samples' => false]);
        Accommodation::whereKey($this->ids['hotel'])->update(['is_sample' => true]);
        FoodBeverage::whereKey([$this->ids['foodByStay'], $this->ids['foodByHill']])->update(['is_sample' => true]);
        RetailShop::whereKey($this->ids['shop'])->update(['is_sample' => true]);

        $itinerary = $this->generate();

        $this->assertNotSame($this->ids['hotel'], $itinerary->accommodation_id);
        $this->assertNotContains($this->ids['hotel'], $itinerary->stays()->pluck('accommodation_id')->all());
        $this->assertEmpty($itinerary->items->whereIn('food_beverage_id', [$this->ids['foodByStay'], $this->ids['foodByHill']]));
        $this->assertEmpty($itinerary->items->where('retail_shop_id', $this->ids['shop']));
        $this->assertTrue($itinerary->rooms->isEmpty(), 'Sample hotel rooms must not be booked.');
    }
}

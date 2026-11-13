<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\ListingEstimate;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use App\Services\ItineraryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regressions from a generated Bulan trip (relaxed, moderate budget, fast-food preference,
 * hotel preference), with a faked AI reply over a small town:
 * - A stay named "Town Hotel" but listed only as "Accommodation", in the middle.
 * - Three mountain-category places: a camp (Bonfire, Picnic), a café hill ~10 km away with no
 *   listed activities and no food nearby, and an ocean viewpoint with only Sunset watching.
 * - Three food places by the stay, none of them fast food: a budget Filipino eatery with
 *   breakfast dishes, a budget café, and a moderate grill with one estimated menu price.
 */
class ItineraryMealQualityTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private int $town;

    private array $ids = [];

    /** What the faked AI answers. */
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
        $this->tourist = User::create([
            'role_id' => $roleId, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password'),
        ]);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('categories')->insert(['id' => 2, 'name' => 'Mountain', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);

        $this->ids = [
            'camp' => $this->destination('Camp Lakbay', 12.676, 123.876, '6:00 AM - 10:00 PM', ['Bonfire', 'Picnic']),
            'coffeeHill' => $this->destination('Kapehan Hill', 12.760, 123.880, '7:00 AM - 6:00 PM', []),
            'viewpoint' => $this->destination('Ocean View Point', 12.664, 123.874, null, ['Sunset watching']),
        ];
        $this->ids['stay'] = Accommodation::create($this->listing('Town Hotel', 12.670, 123.880) + ['type' => 'Accommodation', 'price_range' => 'moderate', 'status' => 'approved'])->id;

        $this->ids['eatery'] = $this->food('Morning Eatery', 12.671, 123.881, 'Filipino', 'budget', '6:30 AM - 8:00 PM',
            ['Tapsilog' => 120, 'Bicol Express' => 180, 'Crispy Pata' => 600, 'Halo-Halo' => 90]);
        $this->ids['cafe'] = $this->food('Corner Cafe', 12.669, 123.879, 'Cafe', 'budget', '9:00 AM - 10:00 PM',
            ['Iced Coffee' => 100, 'Banana Bread' => 60, 'Clubhouse Sandwich' => 150]);
        $this->ids['grill'] = $this->food('Town Grill', 12.672, 123.879, 'Filipino', 'moderate', '10:00 AM - 10:00 PM',
            ['Pork Sisig' => 200, 'Grilled Tuna' => 280, 'Pancit Canton' => 180, 'Lechon Party Tray' => 1500]);
        // A menu price the business hasn't confirmed: it's shown and costed as an estimate.
        $pancit = MenuItem::where('name', 'Pancit Canton')->sole();
        ListingEstimate::create(['estimable_type' => MenuItem::class, 'estimable_id' => $pancit->id, 'field' => 'price', 'value' => '180', 'basis' => 'Test estimate']);
        // More restaurants by the stay, so meals don't run out (no food place repeats).
        $this->food('Kusina ni Lola', 12.668, 123.881, 'Filipino', 'budget', '7:00 AM - 7:00 PM', ['Daily Viand with Rice' => 90, 'Pinangat' => 110, 'Tinolang Manok' => 150]);
        $this->food('Ramen House', 12.671, 123.878, 'Japanese', 'moderate', '11:00 AM - 9:00 PM', ['Gyoza' => 140, 'Katsudon' => 180, 'Tantanmen Ramen' => 210]);
        $this->food('Seaside Diner', 12.669, 123.882, 'Filipino', 'moderate', '7:00 AM - 9:00 PM', ['Beef Tapa Rice' => 150, 'Sinigang na Hipon' => 220, 'Grilled Fish' => 250]);
        $this->food('Noodle Stop', 12.672, 123.882, 'Chinese', 'moderate', '10:00 AM - 9:00 PM', ['Pancit Bihon' => 160, 'Yang Chow Rice' => 180, 'Sweet and Sour Pork' => 230]);

        $this->reply = ['title' => 'Bulan getaway', 'days' => [
            ['day_number' => 1, 'destinations' => $this->picks('camp')],
            ['day_number' => 2, 'destinations' => $this->picks('coffeeHill')],
            ['day_number' => 3, 'destinations' => $this->picks('viewpoint')],
        ]];

        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($this->reply)]]]]],
        ])]);
    }

    private function listing(string $name, float $lat, float $lng): array
    {
        return [
            'name' => $name, 'description' => "{$name} is a test listing with a view. It has a second sentence.", 'municipality_id' => $this->town,
            'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng,
        ];
    }

    private function destination(string $name, float $lat, float $lng, ?string $hours, array $activities): int
    {
        $id = Destination::create($this->listing($name, $lat, $lng) + [
            'category_id' => 2, 'operating_hours' => $hours, 'entrance_fee' => 20, 'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        foreach ($activities as $activity) {
            DB::table('activities')->insert(['destination_id' => $id, 'name' => $activity, 'type' => 'outdoor', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    private function food(string $name, float $lat, float $lng, string $cuisine, string $price, string $hours, array $menu): int
    {
        $id = FoodBeverage::create($this->listing($name, $lat, $lng) + [
            'cuisine_type' => $cuisine, 'price_range' => $price, 'operating_hours' => $hours, 'status' => 'approved',
        ])->id;
        foreach ($menu as $dish => $amount) {
            MenuItem::create(['food_beverage_id' => $id, 'name' => $dish, 'price' => $amount, 'status' => 'approved']);
        }

        return $id;
    }

    private function picks(string ...$keys): array
    {
        return array_map(fn ($key) => ['destination_id' => $this->ids[$key], 'reason' => "Picked {$key}."], $keys);
    }

    private function generate(array $overrides = []): Itinerary
    {
        $start = now()->addDays(3);
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $overrides + [
            'municipality_id' => $this->town,
            'num_days' => 3,
            'interests' => ['nature'],
            'travel_pace' => 'relaxed',
            'budget' => 'moderate',
            'travelers' => 2,
            'food_preference' => 'fast_food',
            'accommodation_type' => 'hotel',
            'arrival_time' => '08:00',
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(2)->toDateString(),
        ])->assertCreated();

        return Itinerary::with(['items.dishes', 'items.destination'])->latest('id')->firstOrFail();
    }

    /** Calls one of the planner's private helpers. */
    private function helper(string $method, ...$args)
    {
        return (new \ReflectionMethod(ItineraryService::class, $method))->invoke(app(ItineraryService::class), ...$args);
    }

    private function place(string $key): Destination
    {
        return Destination::with(['category', 'activities'])->findOrFail($this->ids[$key]);
    }

    /** "5:15 AM – 6:15 AM" → [315, 375] */
    private function minutes(string $range): array
    {
        return array_map(function ($part) {
            $t = Carbon::createFromFormat('g:i A', trim($part));

            return $t->hour * 60 + $t->minute;
        }, explode('–', $range));
    }

    private function problems(Itinerary $itinerary): array
    {
        $rows = $itinerary->items->map(fn ($i) => $i->only(['day_number', 'sequence', 'item_type', 'destination_id', 'time_slot', 'notes', 'travel_time_from_prev', 'activities', 'preference_note']))->all();

        return app(ItineraryService::class)->scheduleProblems($rows, Destination::all(), ['start_date' => $itinerary->start_date]);
    }

    public function test_bonfire_and_campfire_are_evening_activities(): void
    {
        foreach (['Bonfire', 'Campfire by the lake', 'Bonfire night'] as $name) {
            $this->assertSame('evening', $this->helper('timeOfDay', $name, null, 'anytime'), $name);
        }
        // Other activities keep their time of day.
        $this->assertSame('midday', $this->helper('timeOfDay', 'Picnic', null, 'anytime'));
        $this->assertSame('sunset', $this->helper('timeOfDay', 'Sunset watching', null, 'anytime'));
        $this->assertSame('anytime', $this->helper('timeOfDay', 'Swimming', null, 'anytime'));
        $this->assertSame('morning', $this->helper('timeOfDay', 'Bird watching', 'Early morning', 'anytime'));

        // In a generated trip the bonfire is in the evening, never the morning.
        $itinerary = $this->generate();
        $camp = $itinerary->items->first(fn ($i) => $i->destination_id === $this->ids['camp']);
        $this->assertNotNull($camp);
        $bonfire = collect($camp->activities)->first(fn ($a) => str_starts_with($a['name'], 'Bonfire'));
        $this->assertGreaterThanOrEqual(1080, $this->minutes($bonfire['time'])[0], 'The bonfire starts at 6 PM or later.');
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_a_viewpoint_mountain_is_not_a_highland_stop_but_a_hike_still_is(): void
    {
        $this->assertSame('nature', $this->helper('experienceKind', $this->place('viewpoint')), 'Only sunset watching: a viewpoint.');
        $this->assertSame('highland', $this->helper('experienceKind', $this->place('coffeeHill')), 'No listed activities: still a mountain stop.');
        $this->assertSame('highland', $this->helper('experienceKind', $this->place('camp')), 'A camp is more than a view.');

        $trail = $this->destination('Trail Peak', 12.668, 123.884, '6:00 AM - 5:00 PM', ['Hiking', 'Sunset watching']);
        $this->assertSame('highland', $this->helper('experienceKind', Destination::with(['category', 'activities'])->find($trail)));

        // The AI pairs the café hill with the viewpoint: both stay on the same day.
        $this->reply['days'] = [
            ['day_number' => 1, 'destinations' => []],
            ['day_number' => 2, 'destinations' => $this->picks('coffeeHill', 'viewpoint')],
            ['day_number' => 3, 'destinations' => []],
        ];
        $day2 = $this->generate()->items->where('day_number', 2)->pluck('destination_id')->filter()->values()->all();
        $this->assertContains($this->ids['coffeeHill'], $day2);
        $this->assertContains($this->ids['viewpoint'], $day2);

        // Two real highland stops still never share a day.
        $this->ids['trail'] = $trail;
        $this->reply['days'][1]['destinations'] = $this->picks('coffeeHill', 'trail');
        $itinerary = $this->generate();
        foreach ($itinerary->items->whereNotNull('destination_id')->groupBy('day_number') as $day => $visits) {
            $ids = $visits->pluck('destination_id')->all();
            $this->assertFalse(in_array($this->ids['coffeeHill'], $ids, true) && in_array($trail, $ids, true), "Day {$day} has two highland stops.");
        }
    }

    public function test_a_destination_without_activities_gets_a_minimum_visit_not_a_double_block(): void
    {
        $hill = $this->place('coffeeHill');
        $explore = fn (string $pace) => $this->helper('plannedActivities', $hill, $hill, $pace, null)[0]['duration'];
        // The minimum for a mountain stop (90 min) adjusted for the pace, never less than one activity.
        $this->assertSame(120, $explore('relaxed'), 'Was 180 (two 90-minute activities).');
        $this->assertSame(90, $explore('moderate'));
        $this->assertSame(75, $explore('packed'));

        // Listed activities keep their own length.
        DB::table('activities')->where('destination_id', $this->ids['viewpoint'])->update(['duration_minutes' => 45]);
        $viewpoint = $this->place('viewpoint');
        $this->assertSame(45, $this->helper('plannedActivities', $viewpoint, $viewpoint, 'relaxed', null)[0]['duration']);

        $visit = $this->generate()->items->first(fn ($i) => $i->destination_id === $this->ids['coffeeHill']);
        $this->assertSame(120, $visit->duration_minutes);
    }

    public function test_each_meal_gets_portions_that_suit_it(): void
    {
        $preferences = ['travelers' => 2, 'budget' => 'moderate'];
        $dishes = fn (string $key, string $meal) => MenuItem::whereKey(array_keys($this->helper('dishPlan', FoodBeverage::find($this->ids[$key]), $preferences, $meal)['dishes']))->pluck('name')->sort()->values()->all();

        // Breakfast: one breakfast dish per traveler, not three mains.
        $breakfast = $this->helper('dishPlan', FoodBeverage::find($this->ids['eatery']), $preferences, 'breakfast');
        $this->assertSame(['Tapsilog'], $dishes('eatery', 'breakfast'));
        $this->assertSame([2], array_column($breakfast['dishes'], 'quantity'));
        $this->assertEquals(240, $breakfast['cost_max']);
        $this->assertSame('1 dish × 2', $breakfast['cost_note']);

        // Lunch: one main dish (no dessert, no party tray).
        $this->assertCount(1, $dishes('grill', 'lunch'));
        $this->assertNotContains('Lechon Party Tray', $dishes('grill', 'lunch'));
        $this->assertNotContains('Halo-Halo', $dishes('eatery', 'lunch'));

        // Merienda: one light item; a place with only mains still gets just one dish.
        $this->assertCount(1, $dishes('cafe', 'merienda'));
        $this->assertContains($dishes('cafe', 'merienda')[0], ['Iced Coffee', 'Banana Bread', 'Clubhouse Sandwich']);
        $this->assertSame(['Halo-Halo'], $dishes('eatery', 'merienda'));
        MenuItem::where('name', 'Halo-Halo')->delete();
        $this->assertCount(1, $dishes('eatery', 'merienda'));

        // Dinner keeps the 3 dishes around the median price.
        $this->assertSame(['Grilled Tuna', 'Pancit Canton', 'Pork Sisig'], $dishes('grill', 'dinner'));
        $this->assertSame('dinner', $this->helper('mealOf', 'Dinner near Town Hotel.'));
        $this->assertSame('merienda', $this->helper('mealOf', 'Afternoon merienda (snack) near X.'));
        $this->assertSame('lunch', $this->helper('mealOf', 'Lunch break during your visit to X.'));
        $this->assertSame('dinner', $this->helper('mealOf', 'Book a table.'));

        // Generated meals follow the same rule.
        $food = $this->generate()->items->whereNotNull('food_beverage_id');
        $this->assertNotEmpty($food);
        foreach ($food as $stop) {
            $expected = str_starts_with($stop->notes, 'Dinner') ? 3 : 1;
            $this->assertLessThanOrEqual($expected, $stop->dishes->count(), $stop->notes);
            $this->assertTrue($stop->dishes->every(fn ($d) => $d->pivot->quantity === 2));
        }
    }

    public function test_merienda_prefers_a_cafe_within_the_other_rules(): void
    {
        $preferences = ['budget' => 'moderate', 'interests' => ['nature']];
        $cafe = FoodBeverage::find($this->ids['cafe']);
        $grill = FoodBeverage::find($this->ids['grill']);

        // At lunch the moderate grill matches the budget; at merienda the café wins.
        $this->assertGreaterThan($this->helper('foodScore', $cafe, $preferences, 'lunch'), $this->helper('foodScore', $grill, $preferences, 'lunch'));
        $this->assertGreaterThan($this->helper('foodScore', $grill, $preferences, 'merienda'), $this->helper('foodScore', $cafe, $preferences, 'merienda'));

        // A packed pace always offers a merienda; it's at the café, open then and near the tourist.
        $meriendas = $this->generate(['travel_pace' => 'packed'])->items->filter(fn ($i) => str_starts_with((string) $i->notes, 'Afternoon merienda'));
        $this->assertNotEmpty($meriendas);
        $this->assertSame($this->ids['cafe'], $meriendas->first()->food_beverage_id);
        $this->assertCount(1, $meriendas->first()->dishes, 'One light item per traveler.');
    }

    public function test_estimated_menu_prices_are_labelled_in_the_food_cost(): void
    {
        $preferences = ['travelers' => 2, 'budget' => 'moderate'];
        $dinner = $this->helper('dishPlan', FoodBeverage::find($this->ids['grill']), $preferences, 'dinner');
        $this->assertSame('3 dishes × 2 (incl. est. prices)', $dinner['cost_note']);

        $actual = $this->helper('dishPlan', FoodBeverage::find($this->ids['eatery']), $preferences, 'dinner');
        $this->assertSame('3 dishes × 2', $actual['cost_note'], 'All prices confirmed: no estimate label.');
    }

    public function test_a_cuisine_missing_from_town_is_explained_once_and_cheaper_places_are_not_apologized_for(): void
    {
        $itinerary = $this->generate();
        $notes = $itinerary->items->whereNotNull('food_beverage_id')->pluck('preference_note')->filter();

        $explained = $notes->filter(fn ($n) => str_contains($n, 'No verified fast food places are listed in Bulan yet'));
        $this->assertCount(1, $explained, 'Said once, not at every meal.');
        $this->assertFalse($notes->contains(fn ($n) => str_contains($n, 'unused verified fast food')), 'No "unused … near X" when none exists.');

        // Budget places are cheaper than the moderate budget: no price apology for them.
        $budgetStops = $itinerary->items->filter(fn ($i) => in_array($i->food_beverage_id, [$this->ids['eatery'], $this->ids['cafe']], true));
        $this->assertNotEmpty($budgetStops);
        $this->assertTrue($budgetStops->every(fn ($i) => ! str_contains((string) $i->preference_note, 'price range')));

        // A pricier place than the budget is still flagged.
        $note = $this->helper('foodNote', FoodBeverage::find($this->ids['grill']), 'lunch', Destination::find($this->ids['camp']), ['budget' => 'budget', 'food_preference' => 'fast_food'], false, false);
        $this->assertStringContainsString('in the Budget price range', $note);
        $this->assertStringNotContainsString('fast food', $note);
        // A cuisine that is listed in town but used up keeps the per-meal apology.
        $note = $this->helper('foodNote', FoodBeverage::find($this->ids['eatery']), 'lunch', Destination::find($this->ids['camp']), ['budget' => 'budget', 'food_preference' => 'seafood'], true, false);
        $this->assertStringContainsString('unused verified seafood place', $note);
    }

    public function test_a_stay_with_an_unspecified_type_is_described_honestly(): void
    {
        $note = (string) $this->generate()->accommodation_note;

        $this->assertStringContainsString('None of the verified stays in Bulan is listed as a Hotel yet', $note);
        $this->assertStringContainsString("Town Hotel instead: a verified stay whose type isn't specified (moderate price)", $note);
        $this->assertStringNotContainsString("couldn't find a verified Hotel", $note);
        $this->assertSame('Accommodation', Accommodation::find($this->ids['stay'])->type, 'The listing itself is unchanged.');
    }

    public function test_lunch_falls_back_to_a_restaurant_near_the_stay_before_the_stay_itself(): void
    {
        // Day 2 is the café hill alone: ~10 km out with no food place near it.
        $day2 = $this->generate()->items->where('day_number', 2)->sortBy('sequence')->values();
        $lunch = $day2->first(fn ($i) => str_starts_with((string) $i->notes, 'Lunch'));

        $this->assertNotNull($lunch);
        $this->assertNotNull($lunch->food_beverage_id, 'Lunch is at a restaurant near the stay, not at the stay.');
        $this->assertStringStartsWith('Lunch near Town Hotel.', $lunch->notes);
        $this->assertStringContainsString('near Kapehan Hill', $lunch->reason);

        // With no restaurant left anywhere, lunch is at the stay and the note names where we looked.
        FoodBeverage::query()->delete();
        $lunchAtStay = $this->generate()->items->first(fn ($i) => $i->day_number === 2 && str_starts_with((string) $i->notes, 'Lunch at'));
        $this->assertNotNull($lunchAtStay);
        $this->assertStringContainsString('near Kapehan Hill or near Town Hotel', $lunchAtStay->preference_note);
        $this->assertStringNotContainsString('in Bulan', $lunchAtStay->preference_note);
    }

    public function test_visit_notes_keep_the_travel_line_without_repeating_the_description(): void
    {
        $visits = $this->generate()->items->where('item_type', 'destination');
        $this->assertNotEmpty($visits);

        foreach ($visits as $visit) {
            $this->assertStringNotContainsString('is a test listing', (string) $visit->notes, 'The card shows the description already.');
            $this->assertStringStartsWith("Visit {$visit->destination->name}.", (string) $visit->notes);
        }
        $this->assertTrue($visits->contains(fn ($v) => preg_match('/Leave .+ by \d{1,2}:\d{2} [AP]M: about \d+ min/', (string) $v->notes)));
    }
}

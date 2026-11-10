<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use App\Services\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * How the selected interests shape a trip, with a faked AI reply over a small town:
 * - Testville: a hotel in the middle, two eco parks and two hilltops a few minutes away, a
 *   beach, a heritage museum ~3 km out and a day-use resort; two restaurants (Japanese next
 *   to the hotel, a Filipino restobar a little farther) and a souvenir shop.
 * - Othertown: a waterfall right across the town line.
 * The AI only ever picks the two eco parks (Nature); everything else is up to the planner.
 */
class ItineraryInterestTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private array $towns = [];

    private array $ids = [];

    private array $reply = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'test-model',
            'services.gemini.fallback_models' => [],
            'services.google_maps.api_key' => '',
            'services.itinerary.daily_limit' => 100,
        ]);

        $roleId = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        $this->tourist = User::create([
            'role_id' => $roleId, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password'),
        ]);
        foreach (['testville' => 'Testville', 'othertown' => 'Othertown'] as $key => $name) {
            $this->towns[$key] = DB::table('municipalities')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([1 => 'Beach', 2 => 'Mountain', 4 => 'Historical', 7 => 'Eco-Tourism', 9 => 'Resort'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->ids = [
            'eco1' => $this->destination('North Eco Park', 7, 12.672, 123.880, 'Bird watching'),
            'eco2' => $this->destination('South Eco Park', 7, 12.668, 123.880, 'Tree planting'),
            'hill1' => $this->destination('East Hilltop', 2, 12.674, 123.882, 'Photo stop'),
            'hill2' => $this->destination('West Hilltop', 2, 12.666, 123.878, 'Photo stop'),
            'beach' => $this->destination('Cove Beach', 1, 12.676, 123.876, 'Swimming'),
            'museum' => $this->destination('Heritage Museum', 4, 12.700, 123.880, 'Guided tour', '8:00 AM - 5:00 PM'),
            'resort' => $this->destination('Day Resort', 9, 12.670, 123.884, 'Pool lounging'),
            'otherFalls' => $this->destination('Border Falls', 7, 12.671, 123.881, 'Swimming', null, 'othertown'),
        ];
        $this->ids['hotel'] = Accommodation::create($this->listing('Central Inn', 12.670, 123.880) + ['type' => 'Hotel', 'price_range' => 'moderate', 'status' => 'approved'])->id;
        $this->ids['japanese'] = $this->restaurant('Sakura Diner', 'Japanese', 12.6705, 123.8805);
        $this->ids['filipino'] = $this->restaurant('Lola Restobar', 'Filipino Restobar', 12.6725, 123.8815);
        $this->ids['shop'] = RetailShop::create($this->listing('Pasalubong Corner', 12.671, 123.879) + ['status' => 'approved'])->id;

        $this->reply = ['title' => 'Testville', 'days' => [
            ['day_number' => 1, 'destinations' => [['destination_id' => $this->ids['eco1'], 'reason' => 'Birds.']]],
            ['day_number' => 2, 'destinations' => [['destination_id' => $this->ids['eco2'], 'reason' => 'Trees.']]],
        ]];

        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($this->reply)]]]]],
        ])]);
    }

    private function listing(string $name, float $lat, float $lng, string $town = 'testville'): array
    {
        return [
            'name' => $name, 'description' => "{$name} is a test listing.", 'municipality_id' => $this->towns[$town],
            'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng,
        ];
    }

    private function destination(string $name, int $category, float $lat, float $lng, string $activity, ?string $hours = '6:00 AM - 6:00 PM', string $town = 'testville'): int
    {
        $id = Destination::create($this->listing($name, $lat, $lng, $town) + [
            'category_id' => $category, 'operating_hours' => $hours, 'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        DB::table('activities')->insert(['destination_id' => $id, 'name' => $activity, 'type' => 'outdoor', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function restaurant(string $name, string $cuisine, float $lat, float $lng): int
    {
        return FoodBeverage::create($this->listing($name, $lat, $lng) + [
            'cuisine_type' => $cuisine, 'price_range' => 'moderate', 'status' => 'approved', 'operating_hours' => '6:00 AM - 10:00 PM',
        ])->id;
    }

    private function request(array $interests, array $extra = [])
    {
        $start = now()->addDays(3);

        return $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $extra + [
            'municipality_id' => $this->towns['testville'],
            'num_days' => 2,
            'interests' => $interests,
            'travel_pace' => 'moderate',
            'budget' => 'moderate',
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
        ]);
    }

    private function generate(array $interests, array $extra = []): Itinerary
    {
        $this->request($interests, $extra)->assertCreated();

        return Itinerary::with(['items.destination', 'items.foodBeverage'])->latest('id')->firstOrFail();
    }

    private function visited(Itinerary $itinerary): array
    {
        return $itinerary->items->where('item_type', 'destination')->pluck('destination_id')->map(fn ($id) => (int) $id)->all();
    }

    private function aiWasAsked(): bool
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage'))->isNotEmpty();
    }

    /** The prompt sent to the (faked) AI. */
    private function prompt(): string
    {
        $request = Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage'))->last()[0];

        return $request->data()['contents'][0]['parts'][0]['text'];
    }

    private function problems(Itinerary $itinerary): array
    {
        $rows = $itinerary->items->map(fn ($i) => $i->only(['day_number', 'sequence', 'item_type', 'destination_id', 'time_slot', 'notes', 'travel_time_from_prev']))->all();

        return app(ItineraryService::class)->scheduleProblems($rows, Destination::all(), ['start_date' => now()->addDays(3)->toDateString()]);
    }

    private function eligible(array $interests, array $extra = []): array
    {
        $service = app(ItineraryService::class);

        return (new ReflectionMethod($service, 'getEligibleDestinations'))
            ->invoke($service, $extra + ['municipality_ids' => [$this->towns['testville']], 'interests' => $interests])
            ->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    public function test_nature_only_visits_only_nature_places(): void
    {
        $itinerary = $this->generate(['nature']);

        $categories = $itinerary->items->where('item_type', 'destination')->map(fn ($i) => $i->destination->category_id)->unique()->values();
        $this->assertNotEmpty($categories);
        $this->assertEmpty($categories->diff([1, 2, 3, 7, 8]), 'Only Beach, Mountain, Waterfall, Eco-Tourism or Island.');
        $this->assertNotContains($this->ids['museum'], $this->visited($itinerary));
        $this->assertNotContains($this->ids['resort'], $this->visited($itinerary));
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_nature_food_culture_covers_culture_and_prefers_local_food(): void
    {
        $itinerary = $this->generate(['nature', 'food', 'culture']);

        $visited = $this->visited($itinerary);
        $this->assertContains($this->ids['museum'], $visited, 'The AI picked only Nature; the planner brings in Culture.');
        $this->assertNotContains($this->ids['resort'], $visited, 'No Relaxation place: it was not selected.');
        $museum = $itinerary->items->firstWhere('destination_id', $this->ids['museum']);
        $this->assertStringStartsWith('Brings your Culture interest into the trip.', $museum->reason);
        $this->assertTrue($itinerary->items->contains('food_beverage_id', $this->ids['filipino']));
        $this->assertStringContainsString('Represent every selected interest (Nature, Culture)', $this->prompt());
        $this->assertStringContainsString('Food and Shopping interests are covered by the planner', $this->prompt());
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_nature_food_adventure_together(): void
    {
        $itinerary = $this->generate(['nature', 'food', 'adventure']);

        $categories = $itinerary->items->where('item_type', 'destination')->map(fn ($i) => $i->destination->category_id)->unique()->values();
        $this->assertNotEmpty($categories);
        $this->assertEmpty($categories->diff([1, 2, 3, 7, 8]), 'Only Nature / Adventure places: no museum (Culture) or day resort (Relaxation).');
        $this->assertNotEmpty($categories->intersect([2, 3, 7, 8]), 'At least one Adventure place.');
        $this->assertStringContainsString('Interests: Nature, Food, Adventure', $this->prompt());
        $this->assertStringContainsString('Represent every selected interest (Nature, Adventure)', $this->prompt());
        $this->assertStringContainsString('Food and Shopping interests are covered by the planner', $this->prompt());
        $this->assertTrue($itinerary->items->contains('food_beverage_id', $this->ids['filipino']), 'Food: local Filipino cooking is preferred.');
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_top_up_prefers_an_uncovered_interest_over_a_closer_place(): void
    {
        // The hilltops and beach are ~5 minutes away, the museum ~10: by distance alone the
        // museum would never make it in.
        $withCulture = $this->visited($this->generate(['nature', 'culture']));
        $this->assertContains($this->ids['museum'], $withCulture);
        $this->assertSame(1, collect($withCulture)->filter(fn ($id) => $id === $this->ids['museum'])->count(), 'Culture once; no equal split forced.');

        // Unequal availability: with the museum gone there's no Culture place at all — the trip
        // is still planned, with Nature only.
        Destination::whereKey($this->ids['museum'])->update(['status' => 'draft']);
        $itinerary = $this->generate(['nature', 'culture']);
        $this->assertNotEmpty($this->visited($itinerary));
        $this->assertEmpty($itinerary->items->where('item_type', 'destination')->filter(fn ($i) => in_array($i->destination->category_id, [4, 5], true)));
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_food_only_plans_meals_at_verified_restaurants_without_sightseeing_or_ai(): void
    {
        $itinerary = $this->generate(['food']);

        $this->assertFalse($this->aiWasAsked(), 'No destinations to choose, so the AI is not asked.');
        $this->assertSame([], $this->visited($itinerary), 'No attractions were selected, so none are added.');
        $this->assertSame('2-Day Food Trip in Testville', $itinerary->title);
        $food = $itinerary->items->where('item_type', 'food_beverage');
        $this->assertNotEmpty($food);
        $this->assertTrue($food->every(fn ($i) => in_array($i->food_beverage_id, [$this->ids['japanese'], $this->ids['filipino']], true)), 'Only real restaurant records.');
        $this->assertTrue($itinerary->items->contains(fn ($i) => str_contains((string) $i->preference_note, 'without a sightseeing interest')));
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_shopping_only_plans_the_souvenir_stop_without_sightseeing_or_ai(): void
    {
        $itinerary = $this->generate(['shopping']);

        $this->assertFalse($this->aiWasAsked());
        $this->assertSame([], $this->visited($itinerary));
        $this->assertSame('2-Day Shopping Trip in Testville', $itinerary->title);
        $this->assertSame([$this->ids['shop']], $itinerary->items->where('item_type', 'retail_shop')->pluck('retail_shop_id')->map(fn ($id) => (int) $id)->values()->all(), 'The one real shop, once.');
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_food_and_shopping_together(): void
    {
        $itinerary = $this->generate(['food', 'shopping']);

        $this->assertFalse($this->aiWasAsked());
        $this->assertSame('2-Day Food & Shopping Trip in Testville', $itinerary->title);
        $this->assertTrue($itinerary->items->contains('item_type', 'retail_shop'));
        $this->assertTrue($itinerary->items->contains('item_type', 'food_beverage'));
        $this->assertSame([], $this->visited($itinerary));
    }

    public function test_food_or_shopping_without_any_records_gives_a_clear_message(): void
    {
        // Shown to the tourist as is (422), also in production where debug details are hidden.
        config(['app.debug' => false]);
        FoodBeverage::query()->update(['status' => 'draft']);
        RetailShop::query()->update(['status' => 'draft']);

        foreach ([['food'], ['shopping'], ['food', 'shopping']] as $interests) {
            $this->request($interests)->assertStatus(422)
                ->assertJsonPath('message', 'No destinations, restaurants or souvenir shops in this municipality match your interests. Try adding more interests.');
        }
        $this->assertFalse($this->aiWasAsked());

        // Shopping still works when only the restaurants are missing.
        RetailShop::query()->update(['status' => 'approved']);
        $this->assertTrue($this->generate(['food', 'shopping'])->items->contains('item_type', 'retail_shop'));
    }

    /** Day 1's breakfast after a 6 AM arrival: the first meal, near the hotel, before any restaurant is used. */
    private function breakfast(array $interests)
    {
        $itinerary = $this->generate($interests, ['arrival_time' => '06:00']);

        return [$itinerary, $itinerary->items->first(fn ($i) => $i->day_number === 1 && str_starts_with((string) $i->notes, 'Breakfast'))];
    }

    public function test_food_interest_prefers_local_filipino_cooking(): void
    {
        // Without Food the nearest restaurant (Japanese, next door) wins; with it, the Filipino
        // restobar a little farther.
        [, $plain] = $this->breakfast(['nature']);
        $this->assertSame($this->ids['japanese'], $plain->food_beverage_id);

        [, $food] = $this->breakfast(['nature', 'food']);
        $this->assertSame($this->ids['filipino'], $food->food_beverage_id);
        $this->assertStringContainsString('a taste of local Filipino cooking', $food->reason);
    }

    public function test_food_interest_looks_farther_for_a_restaurant_before_eating_at_the_stay(): void
    {
        FoodBeverage::query()->update(['status' => 'draft']);
        $far = $this->restaurant('Hilltop Kainan', 'Filipino', 12.710, 123.880); // ~4.5 km from the hotel

        [, $plain] = $this->breakfast(['nature']);
        $this->assertNull($plain, 'Without Food: no restaurant within 3 km, so no breakfast stop out.');

        [$itinerary, $food] = $this->breakfast(['nature', 'food']);
        $this->assertSame($far, $food?->food_beverage_id, 'With Food: worth the short drive.');
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_filipino_preference_matches_every_recorded_filipino_cuisine(): void
    {
        $service = app(ItineraryService::class);
        $matches = (new ReflectionMethod($service, 'cuisineMatcher'))->invoke($service, ['food_preference' => 'filipino_bicolano']);

        foreach (['Filipino', 'Filipino Bar & Grill', 'Filipino Restaurant & Cafe', 'Filipino Restobar'] as $cuisine) {
            $this->assertTrue($matches(new FoodBeverage(['cuisine_type' => $cuisine])), "{$cuisine} should match.");
        }
        foreach (['Japanese', 'Cafe', 'Seafood', '', null] as $cuisine) {
            $this->assertFalse($matches(new FoodBeverage(['cuisine_type' => $cuisine])), var_export($cuisine, true).' should not match.');
        }
    }

    public function test_preferred_destinations_never_bypass_the_town_or_interest_filters(): void
    {
        $natureOnly = $this->eligible(['nature']);

        // Across the town line: not eligible even when preferred.
        $this->assertSame($natureOnly, $this->eligible(['nature'], ['preferred_destinations' => [$this->ids['otherFalls']]]));
        $this->assertNotContains($this->ids['otherFalls'], $natureOnly);
        // A resort with only Nature selected: not eligible even when preferred.
        $this->assertSame($natureOnly, $this->eligible(['nature'], ['preferred_destinations' => [$this->ids['resort']]]));
        $this->assertNotContains($this->ids['resort'], $natureOnly);

        // Through the API too, and an eligible preferred place is pointed out to the AI.
        $itinerary = $this->generate(['nature'], ['preferred_destinations' => [$this->ids['otherFalls'], $this->ids['resort'], $this->ids['hill1']]]);
        $this->assertNotContains($this->ids['otherFalls'], $this->visited($itinerary));
        $this->assertNotContains($this->ids['resort'], $this->visited($itinerary));
        $this->assertStringNotContainsString('Border Falls', $this->prompt());
        $this->assertMatchesRegularExpression('/East Hilltop[^\n]*The tourist asked for this place/', $this->prompt());
    }

    public function test_free_text_notes_guide_the_ai_without_widening_the_list(): void
    {
        $itinerary = $this->generate(['nature'], ['additional_preferences' => 'Quiet places, less walking, family-friendly please.']);

        $prompt = $this->prompt();
        $this->assertStringContainsString('Additional notes from tourist: Quiet places, less walking', $prompt);
        $this->assertStringContainsString("Use the tourist's notes to choose and arrange destinations from the list above", $prompt);
        $this->assertNotContains($this->ids['museum'], $this->visited($itinerary), 'Notes never add places outside the selected interests.');
    }
}

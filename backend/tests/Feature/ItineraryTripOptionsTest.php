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
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The planner's departure time, Beaches interest and preferred destinations, with a faked AI
 * reply over a small town: two beaches, an island, a hilltop, a church and a park around a
 * hotel with ten restaurants and a souvenir shop, plus a beach in another town.
 */
class ItineraryTripOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private int $town;

    private array $ids = [];

    /** What the faked AI answers. */
    private array $reply = [];

    /** The prompts the AI was sent. */
    private array $prompts = [];

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
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Testville', 'created_at' => now(), 'updated_at' => now()]);
        $otherTown = DB::table('municipalities')->insertGetId(['name' => 'Elsewhere', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1 => 'Beach', 2 => 'Mountain', 4 => 'Historical', 6 => 'Park', 8 => 'Island'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->ids = [
            'northBeach' => $this->destination('North Beach', 1, 12.676, 123.880, '6:00 AM - 6:00 PM', 'Swimming'),
            'southBeach' => $this->destination('South Beach', 1, 12.664, 123.880, '6:00 AM - 6:00 PM', 'Swimming'),
            'island' => $this->destination('Little Island', 8, 12.670, 123.890, '7:00 AM - 5:00 PM', 'Island hopping'),
            'hill' => $this->destination('Lookout Hill', 2, 12.674, 123.874, '6:00 AM - 6:00 PM', 'Photo walk'),
            'church' => $this->destination('Old Church', 4, 12.671, 123.882, '6:00 AM - 7:00 PM', 'Heritage tour', 'indoor'),
            'park' => $this->destination('Town Park', 6, 12.668, 123.878, '6:00 AM - 8:00 PM', 'Strolling'),
            'otherBeach' => $this->destination('Faraway Beach', 1, 12.900, 123.700, '6:00 AM - 6:00 PM', 'Swimming', 'outdoor', $otherTown),
        ];
        Accommodation::create($this->listing('Central Inn', 12.670, 123.880) + ['type' => 'Hotel', 'price_range' => 'moderate', 'status' => 'approved']);
        foreach (range(0, 9) as $n) {
            FoodBeverage::create($this->listing("Eatery {$n}", 12.666 + ($n % 5) * 0.002, 123.878 + intdiv($n, 5) * 0.004) + [
                'cuisine_type' => 'Filipino', 'price_range' => 'moderate', 'status' => 'approved', 'operating_hours' => '6:00 AM - 10:00 PM',
            ]);
        }
        RetailShop::create($this->listing('Pasalubong Center', 12.671, 123.879) + ['status' => 'approved']);

        $this->reply = ['title' => 'Testville getaway', 'days' => [
            ['day_number' => 1, 'destinations' => $this->picks('church')],
            ['day_number' => 2, 'destinations' => $this->picks('northBeach', 'hill', 'park')],
        ]];

        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => function ($request) {
            $this->prompts[] = $request->data()['contents'][0]['parts'][0]['text'] ?? '';

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($this->reply)]]]]]]);
        }]);
    }

    private function listing(string $name, float $lat, float $lng, ?int $town = null): array
    {
        return [
            'name' => $name, 'description' => "{$name} is a test listing.", 'municipality_id' => $town ?? $this->town,
            'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng,
        ];
    }

    private function destination(string $name, int $category, float $lat, float $lng, ?string $hours, string $activity, string $type = 'outdoor', ?int $town = null): int
    {
        $id = Destination::create($this->listing($name, $lat, $lng, $town) + [
            'category_id' => $category, 'operating_hours' => $hours, 'entrance_fee' => 20, 'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        DB::table('activities')->insert(['destination_id' => $id, 'name' => $activity, 'type' => $type, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function picks(string ...$keys): array
    {
        return array_map(fn ($key) => ['destination_id' => $this->ids[$key], 'reason' => "Picked {$key}."], $keys);
    }

    private function start(): Carbon
    {
        return now()->addDay()->next(Carbon::TUESDAY);
    }

    private function request(array $overrides = []): array
    {
        $days = $overrides['num_days'] ?? 2;

        return $overrides + [
            'municipality_ids' => [$this->town],
            'num_days' => $days,
            'interests' => ['nature', 'culture', 'relaxation'],
            'travel_pace' => 'moderate',
            'budget' => 'moderate',
            'travelers' => 2,
            'start_date' => $this->start()->toDateString(),
            'end_date' => $this->start()->addDays($days - 1)->toDateString(),
        ];
    }

    private function generate(array $overrides = []): Itinerary
    {
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $this->request($overrides))->assertCreated();

        return Itinerary::with('items')->latest('id')->firstOrFail();
    }

    /** "7:30 AM – 9:00 AM" → [450, 540] */
    private function minutes(string $range): array
    {
        return array_map(function ($part) {
            $t = Carbon::createFromFormat('g:i A', trim($part));

            return $t->hour * 60 + $t->minute;
        }, explode('–', $range));
    }

    private function day(Itinerary $itinerary, int $n)
    {
        return $itinerary->items->where('day_number', $n)->sortBy('sequence')->values();
    }

    private function problems(Itinerary $itinerary): array
    {
        $rows = $itinerary->items->map(fn ($i) => $i->only(['day_number', 'sequence', 'item_type', 'destination_id', 'time_slot', 'notes', 'travel_time_from_prev', 'activities', 'preference_note']))->all();

        return app(ItineraryService::class)->scheduleProblems($rows, Destination::all(), ['start_date' => $this->start()->toDateString()]);
    }

    private function eligible(array $interests, array $extra = []): array
    {
        $method = new \ReflectionMethod(ItineraryService::class, 'getEligibleDestinations');

        return $method->invoke(app(ItineraryService::class), ['interests' => $interests, 'municipality_ids' => [$this->town]] + $extra)
            ->pluck('id')->sort()->values()->all();
    }

    private function ids(string ...$keys): array
    {
        return collect($keys)->map(fn ($k) => $this->ids[$k])->sort()->values()->all();
    }

    // ---------------- Departure time ----------------

    public function test_the_last_day_ends_by_the_departure_time(): void
    {
        $itinerary = $this->generate(['departure_time' => '13:00']);
        $lastDay = $this->day($itinerary, 2);

        $this->assertNotEmpty($lastDay);
        foreach ($lastDay as $stop) {
            $this->assertLessThanOrEqual(780, $this->minutes($stop->time_slot)[1], "{$stop->notes} ends after 1:00 PM.");
        }
        $this->assertStringContainsString('You leave around 1:00 PM', $lastDay->first()->preference_note);
        $this->assertFalse($lastDay->contains(fn ($i) => str_starts_with((string) $i->notes, 'Dinner')), 'No dinner after leaving.');
        // The AI's three picks don't all fit a morning: what doesn't fit is left out, never squeezed in.
        $this->assertLessThan(3, $lastDay->where('item_type', 'destination')->count());
        $this->assertSame([], $this->problems($itinerary));
        // Earlier days are untouched by it.
        $this->assertTrue($this->day($itinerary, 1)->contains(fn ($i) => str_starts_with((string) $i->notes, 'Dinner')));
    }

    public function test_an_early_departure_leaves_only_check_out(): void
    {
        $lastDay = $this->day($this->generate(['departure_time' => '07:00']), 2);

        $this->assertCount(1, $lastDay);
        $this->assertStringStartsWith('Check out', $lastDay->first()->notes);
        $this->assertLessThanOrEqual(420, $this->minutes($lastDay->first()->time_slot)[1]);
        $this->assertStringContainsString('before breakfast would be over', $lastDay->first()->preference_note);
    }

    public function test_a_one_day_trip_ends_by_the_departure_time(): void
    {
        $this->reply['days'] = [['day_number' => 1, 'destinations' => $this->picks('church', 'park', 'hill')]];
        $itinerary = $this->generate(['num_days' => 1, 'arrival_time' => '08:00', 'departure_time' => '15:00']);

        foreach ($itinerary->items as $stop) {
            $this->assertLessThanOrEqual(900, $this->minutes($stop->time_slot)[1], "{$stop->notes} ends after 3:00 PM.");
        }
        $this->assertTrue($itinerary->items->contains('item_type', 'destination'));
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_a_blank_departure_keeps_the_last_day_as_before(): void
    {
        $lastDay = $this->day($this->generate(), 2);

        // Without a departure time the last day simply runs through dinner.
        $this->assertTrue($lastDay->contains(fn ($i) => str_starts_with((string) $i->notes, 'Dinner')));
        $this->assertStringNotContainsString('Departure on Day', end($this->prompts));
    }

    public function test_departure_time_is_validated_and_shapes_the_prompt(): void
    {
        $post = fn (array $overrides) => $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $this->request($overrides));

        $post(['departure_time' => '5pm'])->assertUnprocessable()->assertJsonValidationErrors('departure_time');
        $post(['num_days' => 1, 'arrival_time' => '14:00', 'departure_time' => '09:00'])->assertUnprocessable()->assertJsonValidationErrors('departure_time');
        // On a longer trip an earlier clock time on the last day is fine.
        $post(['arrival_time' => '14:00', 'departure_time' => '09:00'])->assertCreated();

        $this->generate(['departure_time' => '13:00']);
        $prompt = end($this->prompts);
        $this->assertStringContainsString('- Departure on Day 2: around 1:00 PM', $prompt);
        $this->assertStringContainsString('The tourist leaves around 1:00 PM on Day 2', $prompt);

        // The last day holds fewer stops the earlier the tourist leaves; other days keep the pace's.
        $target = fn (string $time) => (new \ReflectionMethod(ItineraryService::class, 'dayTarget'))->invoke(app(ItineraryService::class), 2, $this->request(['departure_time' => $time]));
        $this->assertSame(0, $target('08:30'));
        $this->assertSame(2, $target('12:00'));
        $this->assertSame(3, $target('20:00'));
        $this->assertSame(3, (new \ReflectionMethod(ItineraryService::class, 'dayTarget'))->invoke(app(ItineraryService::class), 2, $this->request()));
    }

    // ---------------- Beaches ----------------

    public function test_beaches_selects_beach_and_island_destinations_on_its_own(): void
    {
        $this->assertSame($this->ids('northBeach', 'southBeach', 'island'), $this->eligible(['beaches']));
        $this->assertNotContains($this->ids['otherBeach'], $this->eligible(['beaches']), 'Still only the selected towns.');

        $this->reply['days'] = [
            ['day_number' => 1, 'destinations' => $this->picks('northBeach')],
            ['day_number' => 2, 'destinations' => $this->picks('southBeach', 'hill')],
        ];
        $itinerary = $this->generate(['interests' => ['beaches']]);
        $visited = $itinerary->items->pluck('destination_id')->filter()->all();
        $this->assertNotEmpty($visited);
        $this->assertEmpty(array_diff($visited, $this->ids('northBeach', 'southBeach', 'island')), 'Only beach and island stops.');
        $this->assertNotContains($this->ids['hill'], $visited, 'A hill the AI suggests is not a Beaches pick.');
        $this->assertStringContainsString('- Interests: Beaches', end($this->prompts));
    }

    public function test_beaches_combines_with_other_interests_without_changing_them(): void
    {
        $this->assertSame($this->ids('northBeach', 'southBeach', 'island', 'church'), $this->eligible(['beaches', 'culture']));
        // Existing interests select exactly what they did before.
        $this->assertSame($this->ids('northBeach', 'southBeach', 'island', 'hill'), $this->eligible(['nature']));
        $this->assertSame($this->ids('church'), $this->eligible(['culture']));

        $this->reply['days'] = [
            ['day_number' => 1, 'destinations' => $this->picks('church')],
            ['day_number' => 2, 'destinations' => $this->picks('northBeach')],
        ];
        $itinerary = $this->generate(['interests' => ['beaches', 'culture']]);
        $visited = $itinerary->items->pluck('destination_id')->filter()->all();
        $this->assertContains($this->ids['church'], $visited);
        $this->assertNotEmpty(array_intersect($visited, $this->ids('northBeach', 'southBeach', 'island')));
        $this->assertStringContainsString('every selected interest (Beaches, Culture)', end($this->prompts));
    }

    public function test_beaches_is_never_assumed(): void
    {
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $this->request(['interests' => ['beach']]))
            ->assertUnprocessable()->assertJsonValidationErrors('interests.0');
        $this->generate(['interests' => ['culture']]);
        $this->assertStringNotContainsString('beaches', mb_strtolower(explode("\n", explode('- Interests: ', end($this->prompts))[1])[0]));
    }

    // ---------------- Preferred destinations ----------------

    public function test_preferred_destinations_are_pointed_out_and_favoured_within_the_rules(): void
    {
        // The AI leaves room on Day 2; the planner fills it with the place the tourist asked for first.
        $this->reply['days'][1]['destinations'] = $this->picks('northBeach');
        $itinerary = $this->generate(['preferred_destinations' => [$this->ids['park']]]);

        $prompt = end($this->prompts);
        $this->assertMatchesRegularExpression('/Town Park[^\n]*The tourist asked for this place/', $prompt);
        $this->assertStringContainsString('5c. Include every destination marked', $prompt);
        $park = $itinerary->items->firstWhere('destination_id', $this->ids['park']);
        $this->assertNotNull($park, 'The preferred park joins the trip.');
        $this->assertStringContainsString('One of the places you asked for', (string) $park->reason);
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_preferred_destinations_never_break_the_town_interest_or_day_rules(): void
    {
        // Another town's beach, and a park while only Beaches is selected: never planned.
        $itinerary = $this->generate(['interests' => ['beaches'], 'preferred_destinations' => [$this->ids['otherBeach'], $this->ids['park']]]);
        $visited = $itinerary->items->pluck('destination_id')->filter()->all();
        $this->assertNotContains($this->ids['otherBeach'], $visited);
        $this->assertNotContains($this->ids['park'], $visited);

        // A second beach on a day that has one breaks the one-water-stop rule, even when preferred.
        $this->reply['days'][1]['destinations'] = $this->picks('northBeach');
        $itinerary = $this->generate(['num_days' => 2, 'preferred_destinations' => [$this->ids['southBeach']]]);
        foreach ($itinerary->items->whereNotNull('destination_id')->groupBy('day_number') as $day => $visits) {
            $beaches = $visits->whereIn('destination_id', $this->ids('northBeach', 'southBeach'))->count();
            $this->assertLessThanOrEqual(1, $beaches, "Day {$day} has two beaches.");
        }

        // An unpublished destination is ignored.
        Destination::whereKey($this->ids['hill'])->update(['status' => 'draft']);
        $this->generate(['preferred_destinations' => [$this->ids['hill']]]);
        $this->assertStringNotContainsString('Lookout Hill', end($this->prompts));
    }

    public function test_unknown_preferred_destination_ids_are_rejected(): void
    {
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $this->request(['preferred_destinations' => [999999]]))
            ->assertUnprocessable()->assertJsonValidationErrors('preferred_destinations.0');
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $this->request(['preferred_destinations' => ['abc']]))
            ->assertUnprocessable()->assertJsonValidationErrors('preferred_destinations.0');
        // No picks: an empty list, as before.
        $this->generate(['preferred_destinations' => []]);
        $this->assertStringNotContainsString('5c.', end($this->prompts));
    }

    // ---------------- Transportation preference ----------------

    public function test_every_transport_preference_is_accepted_and_shapes_travel_times(): void
    {
        $shape = fn (Itinerary $i) => $i->items->sortBy(['day_number', 'sequence'])
            ->map(fn ($s) => [$s->day_number, $s->item_type, $s->destination_id ?? $s->food_beverage_id ?? $s->accommodation_id ?? $s->retail_shop_id, $s->time_slot])->values()->all();
        $withoutTransportLine = fn (string $prompt) => preg_replace('/^- Getting around: .*\n/m', '', $prompt);

        $baseline = $this->generate();
        $baselinePrompt = end($this->prompts);
        $this->assertStringContainsString('- Getting around: no preference', $baselinePrompt);
        $this->assertStringContainsString('lunch 11:30 AM – 12:30 PM (never later)', $baselinePrompt);
        // Driving modes: the same plan with driving times; the AI is told how the tourist travels.
        foreach (['private_car', 'motorcycle', 'rented_vehicle', 'no_preference'] as $mode) {
            $itinerary = $this->generate(['transport_preference' => $mode]);
            $this->assertSame($shape($baseline), $shape($itinerary), "{$mode} changed the plan.");
            $this->assertEquals((float) $baseline->estimated_cost_max, (float) $itinerary->estimated_cost_max, "{$mode} changed the cost.");
            $this->assertSame($withoutTransportLine($baselinePrompt), $withoutTransportLine(end($this->prompts)), "{$mode} changed what the AI is asked.");
            // Kept with the itinerary, so its directions can follow it.
            $this->assertSame($mode, $itinerary->transport_preference);
            $this->actingAs($this->tourist)->getJson("/api/v1/itineraries/{$itinerary->id}")->assertOk()->assertJsonPath('transport_preference', $mode);
        }
        $this->assertNull($baseline->transport_preference, 'Left out: none saved, shown as no preference.');

        // Public transport: slower legs with waiting time, said to be estimates, and the AI is
        // asked for fewer, closer stops. The plan still keeps every scheduling rule.
        $public = $this->generate(['transport_preference' => 'public_transport']);
        $this->assertStringContainsString('PUBLIC TRANSPORT', end($this->prompts));
        $legs = $public->items->filter(fn ($i) => (int) $i->travel_time_from_prev > 0);
        $this->assertNotEmpty($legs);
        $this->assertTrue($legs->every(fn ($i) => preg_match('/min (on foot|by tricycle|by jeepney)/', (string) $i->notes) === 1), 'Every leg says how to ride it.');
        $this->assertFalse($public->items->contains(fn ($i) => str_contains((string) $i->notes, 'driving time')), 'No driving times.');
        $baselineLegs = $baseline->items->filter(fn ($i) => (int) $i->travel_time_from_prev > 0)->sum('travel_time_from_prev');
        $this->assertGreaterThan($baselineLegs / max(1, $baseline->items->filter(fn ($i) => (int) $i->travel_time_from_prev > 0)->count()),
            $legs->sum('travel_time_from_prev') / $legs->count(), 'Legs take longer on average.');
        $this->assertSame([], $this->problems($public));

        $post = fn (array $overrides) => $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $this->request($overrides));
        $post(['transport_preference' => 'helicopter'])->assertUnprocessable()->assertJsonValidationErrors('transport_preference');
        $post(['transport_preference' => ['private_car', 'motorcycle']])->assertUnprocessable()->assertJsonValidationErrors('transport_preference');
        $this->postJson('/api/v1/itineraries/guest-generate', $this->request(['transport_preference' => 'public_transport']))->assertCreated();
    }

    // ---------------- Both flows ----------------

    public function test_guests_and_tourists_can_use_all_three_options(): void
    {
        $options = ['interests' => ['beaches', 'culture'], 'departure_time' => '16:00', 'preferred_destinations' => [$this->ids['church']]];

        $guest = $this->postJson('/api/v1/itineraries/guest-generate', $this->request($options))->assertCreated();
        $this->assertNotEmpty($guest->json('preview.days'));

        $itinerary = $this->generate($options);
        foreach ($this->day($itinerary, 2) as $stop) {
            $this->assertLessThanOrEqual(960, $this->minutes($stop->time_slot)[1]);
        }
        $this->assertContains('beaches', $itinerary->interests);
        $this->assertSame([], $this->problems($itinerary));
    }
}

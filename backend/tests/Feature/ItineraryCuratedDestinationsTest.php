<?php

namespace Tests\Feature;

use App\Contracts\ItineraryAI;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Role;
use App\Models\User;
use App\Services\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SOSOGON's curated destinations (services.itinerary.default_towns, priority_destinations,
 * full_day_destinations): a trip with no municipality chosen stays in the eight default towns
 * plus the priority destinations listed by name elsewhere (Malawmawan Island in Castilla, Orok
 * in Casiguran); a municipality the tourist chooses is used instead, in full and nothing else;
 * Subic Beach gets a day of its own. The towns carry the real names, so the real configuration
 * is what's tested. Outside the list: Donsol (an iconic whale-shark island), Barcelona (an
 * iconic ruin right next to Gubat), and Castilla's and Casiguran's other places.
 * The fake AI gives each day one place from its list, in list order, unless $reply is set.
 */
class ItineraryCuratedDestinationsTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT_TOWNS = ['Sorsogon City', 'Gubat', 'Bulusan', 'Matnog', 'Juban', 'Irosin', 'Bulan', 'Santa Magdalena'];

    private User $tourist;

    private array $towns = [];

    private array $ids = [];

    private object $ai;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_maps.api_key' => '', 'services.itinerary.daily_limit' => 1000]);
        Http::preventStrayRequests();
        $this->ai = new class implements ItineraryAI
        {
            public array $prompts = [];

            public ?array $reply = null;

            public function generate(string $prompt): string
            {
                $this->prompts[] = $prompt;
                if ($this->reply !== null) {
                    return json_encode(['title' => 'Fixture trip', 'days' => $this->reply]);
                }
                preg_match_all('/^ID:(\d+) \|/m', $prompt, $ids);
                preg_match('/Create a (\d+)-day/', $prompt, $days);
                $plan = [];
                for ($n = 1; $n <= (int) $days[1]; $n++) {
                    $id = (int) ($ids[1][$n - 1] ?? 0);
                    $plan[] = ['day_number' => $n, 'title' => "Day {$n}", 'highlight_id' => $id, 'destinations' => $id ? [['destination_id' => $id, 'reason' => 'A fixture pick.']] : []];
                }

                return json_encode(['title' => 'Fixture trip', 'days' => $plan]);
            }
        };
        $this->app->instance(ItineraryAI::class, $this->ai);
        $this->app->forgetInstance(ItineraryService::class);

        $role = Role::create(['name' => 'tourist', 'description' => 'Tourist']);
        $this->tourist = User::create(['role_id' => $role->id, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password')]);
        foreach ([...self::DEFAULT_TOWNS, 'Castilla', 'Casiguran', 'Donsol', 'Barcelona'] as $name) {
            $this->towns[$name] = DB::table('municipalities')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([1 => 'Beach', 2 => 'Mountain', 3 => 'Waterfall', 4 => 'Historical', 5 => 'Church', 6 => 'Park', 7 => 'Eco-Tourism', 8 => 'Island', 9 => 'Resort'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->place('paguriran', 'Paguriran Lagoon', 1, 'Sorsogon City', 13.070, 124.121, [['Lagoon swimming', 90]]);
        $this->place('museo', 'Museo Sorsogon', 4, 'Sorsogon City', 12.972, 124.002, [['Museum visit', 60]]);
        $this->place('cityPark', 'Capitol Park', 6, 'Sorsogon City', 12.970, 124.004, [['Strolling', 45]]);
        $this->place('lolaSayong', 'Lola Sayong Eco-Surfing Camp', 1, 'Gubat', 12.896, 124.120, [['Surfing lessons', 60]]);
        $this->place('miniSiargao', 'Gubat Tourism Hub "Mini Siargao"', 1, 'Gubat', 12.900, 124.128, [['Skateboarding', 60]]);
        $this->place('bulusanLake', 'Bulusan Lake', 7, 'Bulusan', 12.753, 124.048, [['Kayaking', 60]]);
        $this->place('subic', 'Subic Beach', 1, 'Matnog', 12.542, 124.106, [['Island hopping', 180, 'Morning'], ['Swimming & Snorkeling', 90, 'Midday, calm seas'], ['Pink Sand Beach Walk', 60]]);
        $this->place('matnogHill', 'Ladladan Hills', 2, 'Matnog', 12.580, 124.080, [['Hiking', 90]]);
        $this->place('matnogCove', 'Mila Beach', 1, 'Matnog', 12.570, 124.090, [['Picnic', 60]]);
        $this->place('tangke', 'Tangke Nature Spring', 7, 'Juban', 12.791, 123.998, [['Spring bathing', 60]]);
        $this->place('sanBenon', 'San Benon Resort', 9, 'Irosin', 12.732, 124.026, [['Hot spring circuit', 90]]);
        $this->place('bulanMuseum', 'Bulan Heritage Museum', 4, 'Bulan', 12.674, 123.876, [['Heritage tour', 60]]);
        $this->place('magdalenaBeach', 'Marahlee Beach Resort', 1, 'Santa Magdalena', 12.650, 124.100, [['Beach relaxation', 60]]);
        $this->place('malawmawan', 'Malawmawan Island', 8, 'Castilla', 12.846, 123.767, [['Sandbar walk', 60]]);
        $this->place('castillaFalls', 'Castilla Falls', 3, 'Castilla', 12.950, 123.880, [['Waterfall visit', 60]], featured: true);
        $this->place('orok', 'Orok Cold Spring Resort', 9, 'Casiguran', 12.805, 124.061, [['Cold spring pool', 90]]);
        $this->place('casiguranChurch', 'Casiguran Church', 5, 'Casiguran', 12.870, 124.010, [['Church visit', 45]], featured: true);
        $this->place('whaleSharks', 'Whale Shark Bay', 8, 'Donsol', 12.908, 123.597, [['Whale shark watching', 120]], 'iconic', true);
        $this->place('donsolChurch', 'Donsol Church', 5, 'Donsol', 12.909, 123.600, [['Church visit', 45]]);
        $this->place('barcelonaRuins', 'Barcelona Ruins', 4, 'Barcelona', 12.868, 124.140, [['Heritage walk', 60]], 'iconic', true);
    }

    /** @param  array<int, array{0: string, 1: int, 2?: string}>  $activities  name, minutes, best time */
    private function place(string $key, string $name, int $category, string $town, float $lat, float $lng, array $activities, ?string $priority = null, bool $featured = false): void
    {
        $id = Destination::create([
            'name' => $name, 'description' => "{$name} is a test listing.", 'category_id' => $category, 'municipality_id' => $this->towns[$town],
            'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng, 'operating_hours' => '6:00 AM - 6:00 PM',
            'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        // Set directly as the tourism office would (not mass-assignable).
        DB::table('destinations')->where('id', $id)->update(['tourism_priority' => $priority, 'is_featured' => $featured]);
        foreach ($activities as $activity) {
            DB::table('activities')->insert(['destination_id' => $id, 'name' => $activity[0], 'duration_minutes' => $activity[1], 'best_time_to_visit' => $activity[2] ?? null,
                'type' => 'outdoor', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->ids[$key] = $id;
    }

    private function generate(array $fields, int $days = 3): Itinerary
    {
        $start = now()->addDays(3);
        $id = $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $fields + [
            'num_days' => $days, 'accommodation_needed' => false,
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
        ])->assertCreated()->json('itinerary.id');

        return Itinerary::with('items.destination')->find($id);
    }

    private function stops(Itinerary $itinerary)
    {
        return $itinerary->items->where('item_type', 'destination')->values();
    }

    private function stopIds(Itinerary $itinerary): array
    {
        return $this->stops($itinerary)->pluck('destination_id')->map(fn ($id) => (int) $id)->all();
    }

    /** The destination IDs the AI was offered. */
    private function offered(): array
    {
        preg_match_all('/^ID:(\d+) \|/m', $this->ai->prompts[0], $ids);

        return array_map('intval', $ids[1]);
    }

    /** What a default trip may use: the eight towns' places, Malawmawan Island and Orok. */
    private function curated(): array
    {
        $inTowns = Destination::whereIn('municipality_id', array_map(fn ($t) => $this->towns[$t], self::DEFAULT_TOWNS))->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [...$inTowns, $this->ids['malawmawan'], $this->ids['orok']];
    }

    private function categories(Itinerary $itinerary): array
    {
        return $this->stops($itinerary)->map(fn ($i) => (int) $i->destination->category_id)->unique()->sort()->values()->all();
    }

    public function test_with_no_municipality_only_the_curated_towns_and_places_are_offered_and_planned(): void
    {
        $itinerary = $this->generate([], 6);

        $this->assertNotEmpty($this->offered());
        $this->assertSame([], array_diff($this->offered(), $this->curated()), 'The AI never sees another town\'s places.');
        $this->assertSame([], array_diff($this->stopIds($itinerary), $this->curated()), 'Nor does the trip use one.');
        // Not even iconic, featured ones, or a curated town's neighbour.
        foreach (['whaleSharks', 'donsolChurch', 'barcelonaRuins', 'castillaFalls', 'casiguranChurch'] as $outside) {
            $this->assertNotContains($this->ids[$outside], [...$this->offered(), ...$this->stopIds($itinerary)], $outside);
        }
    }

    public function test_priority_destinations_are_recognised_and_lead_the_trip(): void
    {
        $itinerary = $this->generate([], 5);

        $prompt = $this->ai->prompts[0];
        foreach (['Paguriran Lagoon', 'Bulusan Lake', 'San Benon Resort', 'Orok Cold Spring Resort'] as $name) {
            $this->assertMatchesRegularExpression('/^ID:\d+ \| '.preg_quote($name, '/').' \|.*Standing: [^|]*one of SOSOGON\'s priority destinations/mi', $prompt, $name);
        }
        $this->assertDoesNotMatchRegularExpression('/^ID:\d+ \| Capitol Park \|.*priority destinations/mi', $prompt, 'Only the listed ones.');
        // A five-day trip covers five towns, each led by its priority destination.
        foreach (['paguriran', 'lolaSayong', 'bulusanLake'] as $key) {
            $this->assertContains($this->ids[$key], $this->stopIds($itinerary), $key);
        }
        $this->assertStringContainsString('You let SOSOGON choose the municipalities', (string) $this->stops($itinerary)->first()->preference_note);
    }

    public function test_beaches_only_draws_on_the_curated_beaches(): void
    {
        $itinerary = $this->generate(['interests' => ['beaches']], 4);

        $this->assertSame([], array_diff($this->categories($itinerary), [1, 8]), 'Beaches and islands only: no lake or museum to fill the days.');
        $this->assertContains($this->ids['subic'], $this->stopIds($itinerary));
        $this->assertContains($this->ids['paguriran'], $this->stopIds($itinerary));
        $this->assertNotContains($this->ids['whaleSharks'], $this->offered(), 'Donsol\'s island is not in the curated scope.');
        $this->assertSame([], array_diff($this->stopIds($itinerary), $this->curated()));
    }

    public function test_nature_only_keeps_to_nature_in_the_curated_towns(): void
    {
        $itinerary = $this->generate(['interests' => ['nature']], 3);

        $this->assertSame([], array_diff($this->categories($itinerary), [1, 2, 3, 7, 8]));
        $this->assertNotContains($this->ids['castillaFalls'], $this->offered(), 'A featured waterfall outside the curated scope stays out.');
        $this->assertSame([], array_diff($this->stopIds($itinerary), $this->curated()));
    }

    public function test_all_interests_give_a_varied_trip_from_the_curated_pool(): void
    {
        $itinerary = $this->generate(['interests' => ['nature', 'beaches', 'culture', 'history', 'adventure', 'relaxation']], 5);

        $this->assertGreaterThanOrEqual(3, count($this->categories($itinerary)), 'Several kinds of places, not beach after beach.');
        $this->assertGreaterThan(2, $this->stops($itinerary)->pluck('destination.municipality_id')->unique()->count());
        $this->assertSame(count($this->stopIds($itinerary)), count(array_unique($this->stopIds($itinerary))), 'No place twice.');
        $this->assertSame([], array_diff($this->stopIds($itinerary), $this->curated()));
    }

    public function test_no_interests_give_a_balanced_trip_from_the_curated_pool(): void
    {
        $itinerary = $this->generate([], 4);

        $this->assertNotEmpty($this->stops($itinerary));
        $this->assertNotEmpty(array_intersect([1, 8], $this->categories($itinerary)), 'A beach or island.');
        $this->assertNotEmpty(array_diff($this->categories($itinerary), [1, 8]), 'And something else.');
        $this->assertSame([], array_diff($this->stopIds($itinerary), $this->curated()));
    }

    public function test_a_chosen_municipality_outside_the_default_towns_is_respected(): void
    {
        $itinerary = $this->generate(['municipality_ids' => [$this->towns['Donsol']]], 2);

        $this->assertContains($this->ids['whaleSharks'], $this->stopIds($itinerary));
        $this->assertSame([$this->towns['Donsol']], $this->stops($itinerary)->pluck('destination.municipality_id')->map(fn ($t) => (int) $t)->unique()->values()->all());
        $this->assertStringNotContainsString('You let SOSOGON choose', (string) $this->stops($itinerary)->first()->preference_note);
    }

    public function test_chosen_municipalities_are_the_whole_scope_with_no_neighbouring_places_added(): void
    {
        // Gubat has two places for three days; Barcelona's ruins are right next door, but not chosen.
        $itinerary = $this->generate(['municipality_ids' => [$this->towns['Gubat'], $this->towns['Bulusan']], 'interests' => ['beaches', 'nature', 'history']], 3);

        $towns = $this->stops($itinerary)->pluck('destination.municipality_id')->map(fn ($t) => (int) $t)->unique()->all();
        $this->assertSame([], array_diff($towns, [$this->towns['Gubat'], $this->towns['Bulusan']]));
        $this->assertNotContains($this->ids['barcelonaRuins'], $this->stopIds($itinerary));
        $this->assertStringNotContainsString('A short drive away in', (string) $this->stops($itinerary)->pluck('reason')->implode(' '));
    }

    public function test_subic_beach_gets_a_day_of_its_own(): void
    {
        $this->ai->reply = [
            ['day_number' => 1, 'destinations' => [['destination_id' => $this->ids['matnogCove'], 'reason' => 'A picnic.']]],
            // A hill and a beach could otherwise share a day.
            ['day_number' => 2, 'destinations' => [['destination_id' => $this->ids['subic'], 'reason' => 'The island.'], ['destination_id' => $this->ids['matnogHill'], 'reason' => 'Views.']]],
            ['day_number' => 3, 'destinations' => []],
        ];
        $itinerary = $this->generate(['municipality_ids' => [$this->towns['Matnog']], 'interests' => ['beaches', 'nature']], 3);

        $this->assertMatchesRegularExpression('/^ID:\d+ \| Subic Beach \|.*Full-day destination: give it a day of its own/m', $this->ai->prompts[0]);
        $subic = $this->stops($itinerary)->firstWhere('destination_id', $this->ids['subic']);
        $this->assertNotNull($subic);
        $this->assertSame(1, $this->stops($itinerary)->where('day_number', $subic->day_number)->count(), 'Nothing else that day.');
        $this->assertGreaterThanOrEqual(360, (int) $subic->duration_minutes, 'A whole day, not a quick stop.');
        $activities = collect($subic->activities)->pluck('name')->implode(', ');
        foreach (['Island hopping', 'Swimming & Snorkeling', 'Pink Sand Beach Walk'] as $activity) {
            $this->assertStringContainsString($activity, $activities);
        }
        $this->assertStringContainsString('depend on your boat arrangement', (string) $subic->preference_note, 'Island stops and fees are never promised.');
        // The hill moved to another day rather than being squeezed in.
        $this->assertContains($this->ids['matnogHill'], $this->stopIds($itinerary));
    }

    public function test_subic_beach_keeps_its_day_even_when_its_listed_activities_are_short(): void
    {
        // Without the island hopping, its activities take under three hours.
        DB::table('activities')->where('destination_id', $this->ids['subic'])->where('name', 'Island hopping')->delete();
        $this->ai->reply = [
            ['day_number' => 1, 'destinations' => [['destination_id' => $this->ids['matnogCove'], 'reason' => 'A picnic.']]],
            ['day_number' => 2, 'destinations' => [['destination_id' => $this->ids['subic'], 'reason' => 'The island.'], ['destination_id' => $this->ids['matnogHill'], 'reason' => 'Views.']]],
            ['day_number' => 3, 'destinations' => []],
        ];
        $itinerary = $this->generate(['municipality_ids' => [$this->towns['Matnog']], 'interests' => ['beaches', 'nature']], 3);

        $subic = $this->stops($itinerary)->firstWhere('destination_id', $this->ids['subic']);
        $this->assertSame(1, $this->stops($itinerary)->where('day_number', $subic->day_number)->count(), 'The hill goes to another day.');
        $this->assertGreaterThanOrEqual(360, (int) $subic->duration_minutes, 'Its activities are given the whole day.');
    }

    public function test_subic_beach_on_a_short_day_gets_the_time_there_is_and_says_so(): void
    {
        $itinerary = $this->generate(['municipality_ids' => [$this->towns['Matnog']], 'interests' => ['beaches'], 'arrival_time' => '14:00'], 1);

        $subic = $this->stops($itinerary)->firstWhere('destination_id', $this->ids['subic']);
        $this->assertNotNull($subic, 'Still visited, not left out.');
        $this->assertLessThan(360, (int) $subic->duration_minutes);
        $this->assertStringContainsString('Subic Beach is best as a whole day', (string) $subic->preference_note);
        // Over by the end of the visiting day.
        preg_match('/– (\d+):(\d+) (AM|PM)$/', $subic->time_slot, $end);
        $this->assertLessThanOrEqual(17 * 60, ((int) $end[1] % 12 + ($end[3] === 'PM' ? 12 : 0)) * 60 + (int) $end[2]);
    }

    public function test_a_priority_destination_missing_from_the_database_is_never_made_up(): void
    {
        DB::table('destinations')->where('id', $this->ids['bulusanLake'])->update(['status' => 'draft']);
        config(['services.itinerary.priority_destinations.Matnog' => ['Subic Beach', 'Imaginary Cove']]);

        $itinerary = $this->generate(['interests' => ['nature', 'beaches']], 5);

        $this->assertNotContains($this->ids['bulusanLake'], [...$this->offered(), ...$this->stopIds($itinerary)], 'An unpublished priority place stays out.');
        $this->assertStringNotContainsString('Imaginary Cove', $this->ai->prompts[0]);
        $this->assertSame(0, Destination::where('name', 'Imaginary Cove')->count());
        $this->assertSame([], array_diff($this->stopIds($itinerary), Destination::published()->pluck('id')->map(fn ($id) => (int) $id)->all()));
    }

    public function test_a_priority_name_in_another_town_is_not_the_priority_destination(): void
    {
        // Malawmawan Island is listed under Castilla; moved to Donsol it no longer matches, so a
        // default trip leaves it out, and a Donsol "Subic Beach" is not Matnog's.
        DB::table('destinations')->where('id', $this->ids['malawmawan'])->update(['municipality_id' => $this->towns['Donsol']]);
        $this->place('fakeSubic', 'Subic Beach', 1, 'Donsol', 12.905, 123.590, [['Swimming', 60]]);

        $this->generate(['interests' => ['beaches']], 4);
        $this->assertNotContains($this->ids['malawmawan'], $this->offered());
        $this->assertNotContains($this->ids['fakeSubic'], $this->offered());

        $this->ai->prompts = [];
        $this->generate(['municipality_ids' => [$this->towns['Donsol']], 'interests' => ['beaches']], 2);
        $this->assertDoesNotMatchRegularExpression('/^ID:'.$this->ids['fakeSubic'].' \|.*(priority destinations|Full-day destination)/m', $this->ai->prompts[0]);
    }

    public function test_repeated_or_outside_picks_from_the_ai_never_reach_the_trip(): void
    {
        $this->ai->reply = [
            ['day_number' => 1, 'destinations' => [['destination_id' => $this->ids['paguriran'], 'reason' => 'Lagoon.']]],
            ['day_number' => 2, 'destinations' => [['destination_id' => $this->ids['paguriran'], 'reason' => 'Again.'], ['destination_id' => $this->ids['whaleSharks'], 'reason' => 'Not offered.']]],
        ];
        $itinerary = $this->generate([], 2);

        $ids = $this->stopIds($itinerary);
        $this->assertSame(1, count(array_keys($ids, $this->ids['paguriran'])));
        $this->assertNotContains($this->ids['whaleSharks'], $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));

        // The saved trip opens as usual.
        $this->actingAs($this->tourist)->getJson("/api/v1/itineraries/{$itinerary->id}")->assertOk();
    }
}

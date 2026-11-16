<?php

namespace Tests\Feature;

use App\Contracts\ItineraryAI;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Role;
use App\Models\User;
use App\Services\InterestMatcher;
use App\Services\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What the tourist leaves open, SOSOGON decides: no municipality (the planner chooses towns
 * province-wide), no interests (a balanced trip), no pace or budget (moderate). What they do
 * choose is kept. Four towns:
 * - Shoreline: a swimming beach, an island-hopping island and an old fort.
 * - Oldtown (next to Shoreline): a stone church, a museum and a town beach.
 * - Highland (~25 km away): a hiking peak and a waterfall.
 * - Quietville (far from the rest): one park.
 * The fake AI gives each day one place from its list, in list order, unless $reply is set.
 */
class ItineraryOpenChoicesTest extends TestCase
{
    use RefreshDatabase;

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
        foreach (['shoreline' => 'Shoreline', 'oldtown' => 'Oldtown', 'highland' => 'Highland', 'quietville' => 'Quietville'] as $key => $name) {
            $this->towns[$key] = DB::table('municipalities')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([1 => 'Beach', 2 => 'Mountain', 3 => 'Waterfall', 4 => 'Historical', 5 => 'Church', 6 => 'Park', 8 => 'Island'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->place('sandBeach', 'Sand Beach', 1, 'shoreline', 12.950, 124.000, 'Swimming', 'iconic');
        $this->place('coralIsle', 'Coral Isle', 8, 'shoreline', 12.956, 124.006, 'Island hopping');
        $this->place('oldFort', 'Old Fort', 4, 'shoreline', 12.953, 123.996, 'Heritage tour');
        $this->place('stoneChurch', 'Stone Church', 5, 'oldtown', 12.970, 124.010, 'Church visit', 'popular');
        $this->place('museum', 'Town Museum', 4, 'oldtown', 12.972, 124.012, 'Museum visit');
        $this->place('townBeach', 'Town Beach', 1, 'oldtown', 12.968, 124.016, 'Picnic');
        $this->place('peak', 'Green Peak', 2, 'highland', 12.760, 124.100, 'Hiking', 'popular');
        $this->place('falls', 'Hidden Falls', 3, 'highland', 12.765, 124.104, 'Swimming at the basin');
        $this->place('park', 'Quiet Park', 6, 'quietville', 12.300, 123.400, 'Strolling');
    }

    private function place(string $key, string $name, int $category, string $town, float $lat, float $lng, string $activity, ?string $priority = null): void
    {
        $id = Destination::create([
            'name' => $name, 'description' => "{$name} is a test listing.", 'category_id' => $category, 'municipality_id' => $this->towns[$town],
            'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng, 'operating_hours' => '6:00 AM - 6:00 PM',
            'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        // The tourism office's ranking, set directly as an admin would (not mass-assignable).
        DB::table('destinations')->where('id', $id)->update(['tourism_priority' => $priority]);
        DB::table('activities')->insert(['destination_id' => $id, 'name' => $activity, 'type' => 'outdoor', 'created_at' => now(), 'updated_at' => now()]);
        $this->ids[$key] = $id;
    }

    /** Only what the test gives, plus the dates and the number of days. */
    private function generate(array $fields, int $days = 3): Itinerary
    {
        $start = now()->addDays(3);
        $id = $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $fields + [
            'num_days' => $days, 'accommodation_needed' => false,
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
        ])->assertCreated()->json('itinerary.id');

        return Itinerary::with('items.destination')->find($id);
    }

    /** The trip's destination stops. */
    private function stops(Itinerary $itinerary)
    {
        return $itinerary->items->where('item_type', 'destination')->values();
    }

    private function categories(Itinerary $itinerary): array
    {
        return $this->stops($itinerary)->map(fn ($i) => (int) $i->destination->category_id)->unique()->sort()->values()->all();
    }

    public function test_with_nothing_but_dates_sosogon_plans_a_balanced_trip_across_the_province(): void
    {
        $itinerary = $this->generate([]);

        $this->assertSame(InterestMatcher::BALANCED, $itinerary->interests, 'Saved as the balanced interests, so edits plan the same way.');
        $this->assertSame('moderate', $itinerary->travel_pace);
        $this->assertSame('moderate', $itinerary->budget);
        $this->assertStringContainsString('- Interests: none chosen. The tourist left it to you, so plan a balanced trip', $this->ai->prompts[0]);

        $stops = $this->stops($itinerary);
        $this->assertGreaterThan(1, $stops->pluck('destination.municipality_id')->unique()->count(), 'More than one municipality.');
        // A mix of kinds of places, not beach after beach.
        $this->assertNotEmpty(array_intersect([1, 8], $this->categories($itinerary)), 'A beach or island.');
        $this->assertNotEmpty(array_intersect([4, 5], $this->categories($itinerary)), 'A heritage stop.');
        $this->assertSame($stops->count(), $stops->pluck('destination_id')->unique()->count(), 'No place twice.');
    }

    public function test_an_explicitly_empty_interest_list_is_a_balanced_trip_too(): void
    {
        $itinerary = $this->generate(['interests' => [], 'travel_pace' => 'relaxed', 'budget' => 'luxury']);

        $this->assertSame(InterestMatcher::BALANCED, $itinerary->interests);
        // What the tourist did choose is kept.
        $this->assertSame('relaxed', $itinerary->travel_pace);
        $this->assertSame('luxury', $itinerary->budget);
    }

    public function test_beaches_only_keeps_every_stop_a_beach_or_island(): void
    {
        $itinerary = $this->generate(['interests' => ['beaches']]);

        $this->assertNotEmpty($this->stops($itinerary));
        $this->assertSame([], array_diff($this->categories($itinerary), [1, 8]), 'Nothing unrelated added for variety.');
        $this->assertStringContainsString("5a. Beaches is the tourist's one interest: every destination serves it, so make each stop a different experience", $this->ai->prompts[0]);
        // Different experiences of it: the swim, the island hopping, the picnic beach, never the same twice in a day.
        foreach ($this->stops($itinerary)->groupBy('day_number') as $day => $stops) {
            $this->assertSame($stops->count(), $stops->map(fn ($i) => $i->destination->activities()->value('name'))->unique()->count(), "Day {$day}");
        }
    }

    public function test_history_only_keeps_to_heritage_places(): void
    {
        $itinerary = $this->generate(['interests' => ['history']]);

        $this->assertNotEmpty($this->stops($itinerary));
        $this->assertSame([], array_diff($this->categories($itinerary), [4, 5]));
    }

    public function test_beaches_and_history_both_appear_and_are_mixed(): void
    {
        $itinerary = $this->generate(['interests' => ['beaches', 'history']]);

        $this->assertNotEmpty(array_intersect([1, 8], $this->categories($itinerary)));
        $this->assertNotEmpty(array_intersect([4, 5], $this->categories($itinerary)));
        $this->assertStringContainsString('Mix them within days where the places are close', $this->ai->prompts[0]);
    }

    /** Every stop is in the chosen towns: none is brought in from another town, not even next door. */
    private function assertKeptTo(Itinerary $itinerary, array $towns): void
    {
        $others = $this->stops($itinerary)->reject(fn ($i) => in_array((int) $i->destination->municipality_id, $towns, true));
        $this->assertSame([], $others->pluck('destination.name')->all());
    }

    public function test_one_municipality_keeps_the_trip_there(): void
    {
        $itinerary = $this->generate(['municipality_ids' => [$this->towns['oldtown']]], 2);

        $this->assertNotEmpty($this->stops($itinerary));
        $this->assertKeptTo($itinerary, [$this->towns['oldtown']]);
        $this->assertSame($this->towns['oldtown'], (int) $this->stops($itinerary)->first()->destination->municipality_id);
    }

    public function test_chosen_municipalities_are_the_trip_s_scope(): void
    {
        $itinerary = $this->generate(['municipality_ids' => [$this->towns['shoreline'], $this->towns['highland']]], 3);

        $this->assertKeptTo($itinerary, [$this->towns['shoreline'], $this->towns['highland']]);
        foreach ([$this->towns['shoreline'], $this->towns['highland']] as $town) {
            $this->assertTrue($this->stops($itinerary)->contains(fn ($i) => (int) $i->destination->municipality_id === $town), 'Each chosen town is visited.');
        }
    }

    public function test_a_municipality_with_too_few_places_is_never_padded_with_made_up_ones(): void
    {
        $itinerary = $this->generate(['municipality_ids' => [$this->towns['quietville']]], 3);

        // Its one park, once; the other days are free rather than invented or borrowed from far away.
        $this->assertSame([$this->ids['park']], $this->stops($itinerary)->pluck('destination_id')->all());
    }

    public function test_made_up_unpublished_and_repeated_places_from_the_ai_never_reach_the_trip(): void
    {
        DB::table('destinations')->where('id', $this->ids['museum'])->update(['status' => 'draft']);
        $this->ai->reply = [
            ['day_number' => 1, 'destinations' => [['destination_id' => 99999, 'reason' => 'Made up.'], ['destination_id' => $this->ids['stoneChurch'], 'reason' => 'Real.']]],
            ['day_number' => 2, 'destinations' => [['destination_id' => $this->ids['museum'], 'reason' => 'A draft.'], ['destination_id' => $this->ids['stoneChurch'], 'reason' => 'Again.']]],
            ['day_number' => 7, 'destinations' => [['destination_id' => $this->ids['townBeach'], 'reason' => 'A day the trip does not have.']]],
        ];
        $itinerary = $this->generate(['municipality_ids' => [$this->towns['oldtown']], 'interests' => ['history', 'beaches']], 2);

        $ids = $this->stops($itinerary)->pluck('destination_id')->all();
        $this->assertNotContains(99999, $ids);
        $this->assertNotContains($this->ids['museum'], $ids, 'A draft is never planned.');
        $this->assertSame(1, count(array_keys($ids, $this->ids['stoneChurch'])), 'Once, not on both days.');
        $this->assertSame([1, 2], $itinerary->items->pluck('day_number')->unique()->sort()->values()->all(), 'Exactly the trip\'s days.');
        $this->assertTrue(Destination::published()->whereIn('id', $ids)->count() === count(array_unique($ids)), 'Every stop is a published place.');
    }

    public function test_the_place_picker_without_interests_lists_what_a_balanced_trip_draws_from(): void
    {
        $response = $this->getJson('/api/v1/itinerary-places?'.http_build_query(['municipality_ids' => [$this->towns['oldtown']]]))->assertOk();

        $this->assertTrue($response->json('sightseeing'));
        $this->assertSame([], $response->json('unmatched_interests'));
        $this->assertEqualsCanonicalizing(
            [$this->ids['stoneChurch'], $this->ids['museum'], $this->ids['townBeach']],
            collect($response->json('data'))->pluck('id')->all()
        );
    }
}

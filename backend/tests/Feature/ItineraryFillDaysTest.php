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
 * Days in a town with few kinds of places still get filled: in a town where every matching place
 * is a beach or water spot a day may have two of them, and when the chosen town has nothing left
 * that fits, one place just across the town line may join a day (never as its first stop).
 * - Surfside: three beaches, nothing else.
 * - Mixtown: two beaches and a park.
 * - Neighbor (~10 km from Surfside): a heritage church.
 * - Faraway (~25 km): a museum, too far to join a day.
 * The fake AI picks one place per day, like a cautious planner, so the rest comes from top-ups.
 */
class ItineraryFillDaysTest extends TestCase
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

            public function generate(string $prompt): string
            {
                $this->prompts[] = $prompt;
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
        foreach (['surfside' => 'Surfside', 'mixtown' => 'Mixtown', 'neighbor' => 'Neighbor', 'faraway' => 'Faraway'] as $key => $name) {
            $this->towns[$key] = DB::table('municipalities')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([1 => 'Beach', 4 => 'Historical', 5 => 'Church', 6 => 'Park'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->place('surfA', 'Surf Point', 1, 'surfside', 12.670, 123.880, 'Swimming');
        $this->place('surfB', 'Quiet Cove', 1, 'surfside', 12.672, 123.882, 'Swimming');
        $this->place('surfC', 'Sandbar Beach', 1, 'surfside', 12.668, 123.884, 'Swimming');
        $this->place('mixA', 'Mix Beach One', 1, 'mixtown', 12.500, 123.700, 'Swimming');
        $this->place('mixB', 'Mix Beach Two', 1, 'mixtown', 12.502, 123.702, 'Swimming');
        $this->place('mixPark', 'Mix Plaza Park', 6, 'mixtown', 12.501, 123.701, 'Evening stroll');
        $this->place('church', 'Neighbor Heritage Church', 5, 'neighbor', 12.670, 123.970, 'Heritage church visit');
        $this->place('museum', 'Faraway Museum', 4, 'faraway', 12.895, 123.880, 'Museum visit');
    }

    private function place(string $key, string $name, int $category, string $town, float $lat, float $lng, string $activity): void
    {
        $id = Destination::create([
            'name' => $name, 'description' => "{$name} is a test listing.", 'category_id' => $category, 'municipality_id' => $this->towns[$town],
            'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng, 'operating_hours' => '6:00 AM - 6:00 PM',
            'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        DB::table('activities')->insert(['destination_id' => $id, 'name' => $activity, 'type' => 'outdoor', 'created_at' => now(), 'updated_at' => now()]);
        $this->ids[$key] = $id;
    }

    private function generate(string $town, array $interests, array $extra = [])
    {
        $start = now()->addDays(3);

        return $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $extra + [
            'municipality_ids' => [$this->towns[$town]], 'num_days' => 2, 'interests' => $interests, 'travel_pace' => 'moderate', 'budget' => 'moderate',
            'accommodation_needed' => false, 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDay()->toDateString(),
        ]);
    }

    /** Each day's destination stops, in order: [day => [destination id]]. */
    private function days(int $itineraryId): array
    {
        return Itinerary::with('items')->find($itineraryId)->items->where('item_type', 'destination')->sortBy(['day_number', 'sequence'])
            ->groupBy('day_number')->map(fn ($items) => $items->pluck('destination_id')->map(fn ($id) => (int) $id)->values()->all())->all();
    }

    public function test_a_town_with_only_beaches_gets_two_water_stops_a_day(): void
    {
        $response = $this->generate('surfside', ['beaches'])->assertCreated();
        $days = $this->days($response->json('itinerary.id'));

        $beaches = [$this->ids['surfA'], $this->ids['surfB'], $this->ids['surfC']];
        $this->assertSame($beaches, collect($days)->flatten()->sort()->values()->all(), 'All three beaches are used.');
        $this->assertTrue(collect($days)->contains(fn ($ids) => count(array_intersect($ids, $beaches)) === 2), 'A day has two of them.');
        $this->assertStringContainsString('3a. Exception: every listed place in Surfside is a beach or water spot', end($this->ai->prompts));
        $problems = app(ItineraryService::class)->scheduleProblems(
            Itinerary::with('items')->find($response->json('itinerary.id'))->items->map(fn ($i) => $i->only(['day_number', 'sequence', 'item_type', 'destination_id', 'time_slot', 'notes', 'travel_time_from_prev', 'activities', 'preference_note']))->all(),
            Destination::all(), ['start_date' => now()->addDays(3)->toDateString()]);
        $this->assertSame([], $problems);
    }

    public function test_a_town_with_other_kinds_of_places_keeps_one_water_stop_a_day(): void
    {
        $response = $this->generate('mixtown', ['beaches', 'relaxation'])->assertCreated();

        foreach ($this->days($response->json('itinerary.id')) as $day => $ids) {
            $this->assertLessThanOrEqual(1, count(array_intersect($ids, [$this->ids['mixA'], $this->ids['mixB']])), "Day {$day}");
        }
        $this->assertStringNotContainsString('3a. Exception', end($this->ai->prompts));
    }

    public function test_a_nearby_town_fills_a_day_the_chosen_town_cannot(): void
    {
        $response = $this->generate('surfside', ['beaches', 'culture'])->assertCreated();
        $days = $this->days($response->json('itinerary.id'));
        $itinerary = Itinerary::with('items')->find($response->json('itinerary.id'));

        $this->assertContains($this->ids['church'], collect($days)->flatten()->all(), 'The church across the town line fills a day.');
        foreach ($days as $day => $ids) {
            $this->assertNotSame([], array_diff($ids, [$this->ids['church']]), "Day {$day} has a stop in the chosen town.");
        }
        $this->assertStringStartsWith('A short drive away in Neighbor.', $itinerary->items->firstWhere('destination_id', $this->ids['church'])->reason);
        $this->assertNotContains($this->ids['museum'], collect($days)->flatten()->all(), 'Too far to join a day.');
        // The AI only ever sees the chosen town's places.
        $this->assertStringNotContainsString('Neighbor Heritage Church', $this->ai->prompts[0]);
    }

    public function test_a_place_of_the_chosen_town_always_comes_before_one_across_the_town_line(): void
    {
        // Mixtown's park fits a beach day there, so nothing is brought in from elsewhere.
        $response = $this->generate('mixtown', ['beaches', 'relaxation', 'culture'])->assertCreated();

        $this->assertContains($this->ids['mixPark'], collect($this->days($response->json('itinerary.id')))->flatten()->all());
        $this->assertNotContains($this->ids['church'], collect($this->days($response->json('itinerary.id')))->flatten()->all());
    }

    public function test_a_preferred_place_in_another_town_is_not_brought_in(): void
    {
        $response = $this->generate('surfside', ['beaches', 'culture'], ['preferred_destinations' => [$this->ids['church']]])->assertCreated();

        $this->assertNotContains($this->ids['church'], collect($this->days($response->json('itinerary.id')))->flatten()->all());
        $this->assertSame('outside_towns', $response->json('preferred_notice.destinations.0.reason'));
    }
}

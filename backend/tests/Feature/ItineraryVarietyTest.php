<?php

namespace Tests\Feature;

use App\Contracts\ItineraryAI;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Role;
use App\Models\User;
use App\Services\ItineraryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A day offers different experiences, read from what each place actually lists (not just its
 * category), and its stops follow their listed best times, whatever order the AI gave them.
 * - Surfside: two swimming beaches and a surf spot (best in the morning), nothing else.
 * - Parkville: two picnic parks, a snorkeling cove (best in the morning), a heritage
 *   watchtower (best in the afternoon) and a boardwalk for an evening stroll.
 * The fake AI returns $reply as its plan.
 */
class ItineraryVarietyTest extends TestCase
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

            public array $reply = [];

            public function generate(string $prompt): string
            {
                $this->prompts[] = $prompt;

                return json_encode(['title' => 'Fixture trip', 'days' => $this->reply]);
            }
        };
        $this->app->instance(ItineraryAI::class, $this->ai);
        $this->app->forgetInstance(ItineraryService::class);

        $role = Role::create(['name' => 'tourist', 'description' => 'Tourist']);
        $this->tourist = User::create(['role_id' => $role->id, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password')]);
        foreach (['surfside' => 'Surfside', 'parkville' => 'Parkville'] as $key => $name) {
            $this->towns[$key] = DB::table('municipalities')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([1 => 'Beach', 4 => 'Historical', 6 => 'Park'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->place('swimA', 'Long Beach', 1, 'surfside', 12.670, 123.880, ['Swimming']);
        $this->place('swimB', 'White Beach', 1, 'surfside', 12.672, 123.882, ['Swimming']);
        $this->place('surf', 'Surf Bay', 1, 'surfside', 12.668, 123.884, [['Surfing lessons', 'Morning']]);
        $this->place('parkA', 'Plaza Picnic Grove', 6, 'parkville', 12.500, 123.700, ['Picnic']);
        $this->place('parkB', 'Riverside Picnic Park', 6, 'parkville', 12.5205, 123.7005, ['Picnic']);
        $this->place('cove', 'Coral Cove', 1, 'parkville', 12.524, 123.700, [['Snorkeling', 'Early morning']]);
        $this->place('watchtower', 'Old Watchtower', 4, 'parkville', 12.522, 123.702, [['Heritage walk', 'Afternoon']]);
        $this->place('boardwalk', 'Bay Boardwalk', 6, 'parkville', 12.540, 123.700, ['Evening stroll'], '5:00 AM - 10:00 PM');
    }

    /** @param array<int, string|array{0: string, 1: string}> $activities name, or [name, best time] */
    private function place(string $key, string $name, int $category, string $town, float $lat, float $lng, array $activities, string $hours = '6:00 AM - 6:00 PM'): void
    {
        $id = Destination::create([
            'name' => $name, 'description' => "{$name} is a test listing.", 'category_id' => $category, 'municipality_id' => $this->towns[$town],
            'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng, 'operating_hours' => $hours,
            'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        foreach ($activities as $activity) {
            [$activityName, $best] = is_array($activity) ? $activity : [$activity, null];
            DB::table('activities')->insert(['destination_id' => $id, 'name' => $activityName, 'type' => 'outdoor', 'best_time_to_visit' => $best, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->ids[$key] = $id;
    }

    /** The AI's plan: one list of place keys per day. */
    private function reply(array ...$days): void
    {
        $this->ai->reply = array_map(fn ($keys, $n) => [
            'day_number' => $n + 1, 'title' => 'Day '.($n + 1),
            'destinations' => array_map(fn ($key) => ['destination_id' => $this->ids[$key], 'reason' => "Picked {$key}."], $keys),
        ], $days, array_keys($days));
    }

    private function generate(string $town, array $interests, array $extra = []): int
    {
        $start = now()->next('Saturday');

        return $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $extra + [
            'municipality_ids' => [$this->towns[$town]], 'num_days' => 2, 'interests' => $interests, 'travel_pace' => 'moderate', 'budget' => 'moderate',
            'accommodation_needed' => false, 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDay()->toDateString(),
        ])->assertCreated()->json('itinerary.id');
    }

    /** Each day's destination stops, in visiting order: [day => [destination id]]. */
    private function days(int $itineraryId): array
    {
        return Itinerary::with('items')->find($itineraryId)->items->where('item_type', 'destination')->sortBy(['day_number', 'sequence'])
            ->groupBy('day_number')->map(fn ($items) => $items->pluck('destination_id')->map(fn ($id) => (int) $id)->values()->all())->all();
    }

    private function sameDay(array $days, string $a, string $b): bool
    {
        return collect($days)->contains(fn ($ids) => in_array($this->ids[$a], $ids, true) && in_array($this->ids[$b], $ids, true));
    }

    private function helper(string $method, ...$args)
    {
        return (new \ReflectionMethod(ItineraryService::class, $method))->invoke(app(ItineraryService::class), ...$args);
    }

    public function test_two_swimming_beaches_never_share_a_day_even_when_the_ai_pairs_them(): void
    {
        $this->reply(['swimA', 'swimB'], ['surf']);
        $days = $this->days($this->generate('surfside', ['beaches']));

        $this->assertFalse($this->sameDay($days, 'swimA', 'swimB'), 'Two places offering the same swim make a repetitive day.');
        // A beach-only town still gets two water stops a day when they differ: a swim and the surf spot.
        $this->assertTrue($this->sameDay($days, 'surf', 'swimA') || $this->sameDay($days, 'surf', 'swimB'));
    }

    public function test_the_same_experience_is_split_up_whatever_the_category(): void
    {
        // Parks have no per-day limit by kind; two picnic spots are still one experience twice.
        $this->reply(['parkA', 'parkB'], ['boardwalk']);
        $days = $this->days($this->generate('parkville', ['relaxation']));

        $this->assertFalse($this->sameDay($days, 'parkA', 'parkB'));
    }

    public function test_a_top_up_avoids_an_experience_another_day_already_has(): void
    {
        // Day 2 has room for one more stop. The second picnic park is the closest to the cove, but
        // Day 1 already has a picnic, so the boardwalk (a different experience) is added instead.
        $this->reply(['parkA'], ['cove']);
        $days = $this->days($this->generate('parkville', ['relaxation', 'beaches'], ['travel_pace' => 'relaxed']));

        $this->assertContains($this->ids['boardwalk'], $days[2]);
        $this->assertNotContains($this->ids['parkB'], $days[2]);
    }

    public function test_a_place_best_in_the_morning_comes_before_one_best_in_the_afternoon(): void
    {
        $this->reply([], ['watchtower', 'cove']);
        $days = $this->days($this->generate('parkville', ['beaches', 'culture']));

        $order = collect($days)->first(fn ($ids) => in_array($this->ids['cove'], $ids, true));
        $this->assertContains($this->ids['watchtower'], $order, 'Both on one day.');
        $this->assertLessThan(array_search($this->ids['watchtower'], $order, true), array_search($this->ids['cove'], $order, true), 'Snorkeling in the morning, the heritage walk in the afternoon.');
    }

    public function test_an_evening_place_is_the_day_s_last_stop(): void
    {
        $this->reply([], ['boardwalk', 'cove']);
        $id = $this->generate('parkville', ['beaches', 'relaxation']);
        $days = $this->days($id);

        $this->assertSame($this->ids['boardwalk'], end($days[2]), 'The evening stroll ends the day, whatever the AI order.');
        $stroll = Itinerary::with('items')->find($id)->items->firstWhere('destination_id', $this->ids['boardwalk']);
        $start = Carbon::createFromFormat('g:i A', trim(explode('–', $stroll->time_slot)[0]));
        $this->assertGreaterThanOrEqual(18 * 60, $start->hour * 60 + $start->minute, 'Its visit starts in the evening.');
    }

    public function test_a_long_list_of_activities_is_kept_to_a_sensible_visit(): void
    {
        // Six activities without a listed length would be six hours at the pace's usual hour each.
        $place = Destination::with('activities', 'category')->find($this->ids['parkA']);
        foreach (['Kite flying', 'Tree planting', 'Bird feeding', 'Fishing', 'Painting'] as $name) {
            DB::table('activities')->insert(['destination_id' => $place->id, 'name' => $name, 'type' => 'outdoor', 'created_at' => now(), 'updated_at' => now()]);
        }
        $place->load('activities');

        foreach (['relaxed' => 300, 'moderate' => 240, 'packed' => 180] as $pace => $cap) {
            $planned = collect($this->helper('plannedActivities', $place, $place, $pace, null))->reject(fn ($a) => $a['label'] === 'prep');
            $this->assertCount(6, $planned, $pace);
            $this->assertLessThanOrEqual($cap, $planned->sum('duration'), $pace);
            $this->assertGreaterThanOrEqual(30, $planned->min('duration'), 'Every activity keeps at least half an hour.');
        }

        // A length the listing gives is kept as listed.
        DB::table('activities')->where('destination_id', $place->id)->where('name', 'Fishing')->update(['duration_minutes' => 180]);
        $place->load('activities');
        $planned = collect($this->helper('plannedActivities', $place, $place, 'moderate', null));
        $this->assertSame(180, $planned->firstWhere('name', 'Fishing')['duration']);
        $this->assertSame(30, $planned->firstWhere('name', 'Picnic')['duration'], 'The others share what is left, at least 30 minutes each.');
    }

    public function test_experiences_come_from_the_listed_activities(): void
    {
        $load = fn (string $key) => Destination::with('activities', 'category')->find($this->ids[$key]);

        $this->assertSame(['surfing'], $this->helper('experiencesOf', $load('surf')));
        $this->assertTrue($this->helper('sameExperience', $load('swimA'), $load('swimB')));
        $this->assertFalse($this->helper('sameExperience', $load('swimA'), $load('surf')));
        $this->assertSame('morning', $this->helper('preferredPart', $load('cove')));
        $this->assertSame('afternoon', $this->helper('preferredPart', $load('watchtower')));
        $this->assertNull($this->helper('preferredPart', $load('parkA')));
    }

    public function test_the_prompt_gives_each_place_s_experience_and_best_time(): void
    {
        $this->reply(['parkA'], ['boardwalk']);
        $this->generate('parkville', ['relaxation']);
        $prompt = $this->ai->prompts[0];

        $this->assertStringContainsString('Evening stroll (outdoor, suits the evening) | Experience: strolling', $prompt);
        $this->assertStringContainsString('Picnic (outdoor, suits the midday) | Experience: picnic', $prompt);
        $this->assertStringContainsString('3b. Never put two places with the same "Experience" on one day', $prompt);
    }
}

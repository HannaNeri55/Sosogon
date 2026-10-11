<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Generation over several towns with a faked AI reply:
 * - Bulan (west) and Matnog (east) with two destinations each, Irosin (north-east) with one.
 * - A lodge between Bulan and Matnog, an inn next to Bulan's destinations and a resort next
 *   to Matnog's, so only the lodge is central to both towns.
 */
class ItineraryMultiTownTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private array $towns = [];

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
        // Category id 1 is one of the "nature" categories.
        DB::table('categories')->insert(['id' => 1, 'name' => 'Nature', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);

        foreach (['bulan' => 'Bulan', 'matnog' => 'Matnog', 'irosin' => 'Irosin'] as $key => $name) {
            $this->towns[$key] = DB::table('municipalities')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->ids = [
            'bulanHill' => $this->destination('bulan', 'Bulan Hill', 12.70, 123.87),
            'bulanBeach' => $this->destination('bulan', 'Bulan Beach', 12.68, 123.86),
            'matnogCove' => $this->destination('matnog', 'Matnog Cove', 12.58, 124.08),
            'matnogIsland' => $this->destination('matnog', 'Matnog Island', 12.60, 124.10),
            'irosinFalls' => $this->destination('irosin', 'Irosin Falls', 12.70, 124.03),
            'lodge' => $this->stay('matnog', 'Middle Lodge', 12.635, 123.98),
            'inn' => $this->stay('bulan', 'Bulan Inn', 12.69, 123.87),
            'resort' => $this->stay('matnog', 'Matnog Resort', 12.59, 124.09),
        ];

        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($this->reply)]]]]],
        ])]);
    }

    private function fields(string $town, string $name, float $lat, float $lng): array
    {
        return [
            'name' => $name, 'description' => "{$name} used for automated testing.", 'municipality_id' => $this->towns[$town],
            'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng,
        ];
    }

    private function destination(string $town, string $name, float $lat, float $lng): int
    {
        $id = Destination::create($this->fields($town, $name, $lat, $lng) + [
            'category_id' => 1, 'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        foreach (['Nature walk', 'Photo stop', 'Picnic by the view'] as $activity) {
            DB::table('activities')->insert([
                'destination_id' => $id, 'name' => "{$activity} at {$name}", 'best_time_to_visit' => 'Anytime',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function stay(string $town, string $name, float $lat, float $lng): int
    {
        return Accommodation::create($this->fields($town, $name, $lat, $lng) + ['type' => 'Hotel', 'price_range' => 'moderate', 'status' => 'approved'])->id;
    }

    private function generate(array $towns, int $days, string $pace = 'moderate')
    {
        return $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', [
            'municipality_ids' => array_map(fn ($t) => $this->towns[$t], $towns),
            'num_days' => $days,
            'interests' => ['nature'],
            'travel_pace' => $pace,
            'budget' => 'moderate',
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(2 + $days)->toDateString(),
        ]);
    }

    private function day(int $number, string ...$destinations): array
    {
        return ['day_number' => $number, 'destinations' => array_map(fn ($d) => ['destination_id' => $this->ids[$d], 'reason' => 'Nice.'], $destinations)];
    }

    public function test_each_town_gets_its_own_central_stay_and_nights(): void
    {
        $this->reply = ['title' => 'Bulan and Matnog', 'days' => [
            $this->day(1, 'bulanHill'), $this->day(2, 'matnogCove'), $this->day(3, 'matnogIsland'),
        ]];

        $this->generate(['bulan', 'matnog'], 3)->assertCreated();

        $itinerary = Itinerary::with(['municipalities', 'stays'])->latest('id')->firstOrFail();
        [$bulan, $matnog] = $itinerary->stays->all();
        $this->assertSame($this->towns['bulan'], $bulan->municipality_id);
        $this->assertSame($this->ids['inn'], $bulan->accommodation_id, "Bulan's stay is the one in Bulan, near its destinations.");
        $this->assertSame([1], $bulan->night_days);
        $this->assertSame($this->towns['matnog'], $matnog->municipality_id);
        $this->assertSame($this->ids['resort'], $matnog->accommodation_id, "Matnog's stay is the one nearest Matnog's destinations.");
        $this->assertSame([2], $matnog->night_days);
        $this->assertStringContainsString('Your base in Matnog', $matnog->reason);

        // Each stay is priced for its own night; the trip total adds them up.
        $this->assertGreaterThan(0, (float) $bulan->cost_max);
        $this->assertEquals((float) $bulan->cost_max + (float) $matnog->cost_max, (float) $itinerary->accommodation_cost_max);
        $this->assertSame($this->ids['inn'], $itinerary->accommodation_id);
        $this->assertEqualsCanonicalizing([$this->towns['bulan'], $this->towns['matnog']], $itinerary->municipalities->pluck('id')->all());

        // Day 2 starts from where night 1 was spent: the drive from Bulan's inn to Matnog.
        $firstVisit = $itinerary->items()->where('day_number', 2)->where('item_type', 'destination')->orderBy('sequence')->first();
        $this->assertGreaterThan(20, (float) $firstVisit->distance_from_prev_km);
    }

    public function test_every_day_has_one_destination_in_one_town(): void
    {
        // The AI forgets Irosin; Bulan has two days, so one of them goes to Irosin.
        $this->reply = ['title' => 'Three towns', 'days' => [$this->day(1, 'bulanHill'), $this->day(2, 'matnogCove'), $this->day(3, 'bulanBeach')]];

        $this->generate(['bulan', 'matnog', 'irosin'], 3)->assertCreated();

        $itinerary = Itinerary::with(['items.destination', 'stays'])->latest('id')->firstOrFail();
        $visits = $itinerary->items->where('item_type', 'destination');
        $this->assertSame([1, 2, 3], $visits->pluck('day_number')->sort()->values()->all(), 'Exactly one destination per day.');
        $this->assertEqualsCanonicalizing(
            array_values($this->towns),
            $visits->map(fn ($v) => $v->destination->municipality_id)->values()->all(),
            'Each town gets a day of its own.'
        );

        // One stay per town; a 3-day trip has two nights, in the towns of days 1 and 2.
        $this->assertCount(3, $itinerary->stays);
        $this->assertSame(2, $itinerary->stays->sum(fn ($s) => count($s->night_days)));
    }

    public function test_days_in_the_same_town_are_kept_together(): void
    {
        // The AI hops Bulan → Matnog → Bulan; the planner groups Bulan's days.
        $this->reply = ['title' => 'Back and forth', 'days' => [$this->day(1, 'bulanHill'), $this->day(2, 'matnogCove'), $this->day(3, 'bulanBeach')]];

        $this->generate(['bulan', 'matnog'], 3)->assertCreated();

        $towns = Itinerary::with('items.destination')->latest('id')->firstOrFail()->items
            ->where('item_type', 'destination')->sortBy('day_number')
            ->map(fn ($v) => $v->destination->municipality_id)->values()->all();
        $this->assertSame([$this->towns['bulan'], $this->towns['bulan'], $this->towns['matnog']], $towns);
    }

    public function test_more_towns_than_days_gets_a_polite_message(): void
    {
        $response = $this->generate(['bulan', 'matnog', 'irosin'], 2)->assertUnprocessable();

        $message = $response->json('errors.municipality_ids.0');
        $this->assertStringContainsString("You've picked 3 municipalities for a 2-day trip", $message);
        $this->assertStringContainsString('add 1 more day', $message);
    }

    public function test_trips_can_be_up_to_30_days(): void
    {
        $this->reply = ['title' => 'Long stay', 'days' => [$this->day(1, 'bulanHill')]];

        $this->generate(['bulan'], 10)->assertCreated();
        $this->generate(['bulan'], 31)->assertUnprocessable()->assertJsonValidationErrors('num_days');
    }

    public function test_each_visit_lists_exactly_its_own_activities(): void
    {
        $this->reply = ['title' => 'Bulan', 'days' => [
            ['day_number' => 1, 'destination_id' => $this->ids['bulanHill'], 'reason' => 'Old reply shape still works.'],
        ]];

        $this->generate(['bulan'], 1)->assertCreated();

        $visit = Itinerary::with('items')->latest('id')->firstOrFail()->items->firstWhere('destination_id', $this->ids['bulanHill']);
        $names = collect($visit->activities)->pluck('name')->all();
        $this->assertEqualsCanonicalizing(
            ['Nature walk at Bulan Hill', 'Photo stop at Bulan Hill', 'Picnic by the view at Bulan Hill'],
            $names
        );
        foreach ($visit->activities as $activity) {
            [$start, $end] = $this->minutes($activity['time']);
            $this->assertSame(60, $end - $start, 'Each activity takes an hour on a moderate pace.');
        }
    }

    public function test_a_relaxed_visit_over_noon_uses_the_picnic_as_lunch(): void
    {
        // 3 × 90 minutes from mid-morning runs over noon, and there's no food place at all.
        $this->reply = ['title' => 'Bulan', 'days' => [$this->day(1, 'bulanHill')]];

        $this->generate(['bulan'], 1, 'relaxed')->assertCreated();

        $visit = Itinerary::with('items')->latest('id')->firstOrFail()->items->firstWhere('destination_id', $this->ids['bulanHill']);
        $picnic = collect($visit->activities)->first(fn ($a) => str_ends_with($a['name'], '— your picnic lunch'));
        $this->assertNotNull($picnic);
        $this->assertSame([720, 780], $this->minutes($picnic['time']));
        $this->assertCount(3, $visit->activities);
    }

    public function test_a_stay_can_be_changed_within_its_town(): void
    {
        $this->reply = ['title' => 'Bulan and Matnog', 'days' => [$this->day(1, 'bulanHill'), $this->day(2, 'matnogCove'), $this->day(3, 'matnogIsland')]];
        $this->generate(['bulan', 'matnog'], 3)->assertCreated();
        $itinerary = Itinerary::with('stays')->latest('id')->firstOrFail();
        [$bulan, $matnog] = $itinerary->stays->all();
        $url = "/api/v1/itineraries/{$itinerary->id}";

        // The lodge is in Matnog, so it can't replace Bulan's stay…
        $this->actingAs($this->tourist)->putJson($url, ['stays' => [['id' => $bulan->id, 'accommodation_id' => $this->ids['lodge']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('stays.0.accommodation_id');

        // …but it can replace Matnog's, leaving Bulan's untouched.
        $this->actingAs($this->tourist)->putJson($url, ['stays' => [['id' => $matnog->id, 'accommodation_id' => $this->ids['lodge']]]])
            ->assertOk();
        $this->assertSame($this->ids['lodge'], $matnog->refresh()->accommodation_id);
        $this->assertSame('You picked this stay.', $matnog->reason);
        $this->assertSame($this->ids['inn'], $bulan->refresh()->accommodation_id);
    }

    /** "5:15 AM – 6:15 AM" → [315, 375] */
    private function minutes(string $range): array
    {
        return array_map(function ($part) {
            $t = \Carbon\Carbon::createFromFormat('g:i A', trim($part));

            return $t->hour * 60 + $t->minute;
        }, explode('–', $range));
    }
}

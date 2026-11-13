<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\Role;
use App\Models\User;
use App\Services\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The fixed meal times and the schedule check every generated trip passes, over three towns
 * (Bulan, Gubat, Matnog) with several destinations, restaurants and a stay each, whatever the
 * (faked) AI suggests:
 * - breakfast 6:00 – 8:00 AM, lunch 11:30 AM – 12:30 PM (never later), dinner 6:00 – 7:30 PM;
 * - a day with more stops than fit loses stops instead of moving lunch;
 * - public transport gives slower, labelled travel times.
 */
class ItineraryScheduleRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private array $towns = [];

    private array $ids = [];

    /** What the faked AI answers. */
    private array $reply = [];

    /** What the AI was asked. */
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
        DB::table('categories')->insert(['id' => 1, 'name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('categories')->insert(['id' => 2, 'name' => 'Mountain', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);

        foreach (['bulan' => 'Bulan', 'gubat' => 'Gubat', 'matnog' => 'Matnog'] as $key => $name) {
            $this->towns[$key] = DB::table('municipalities')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        // Each town: four destinations a few kilometres apart, six restaurants and a hotel.
        $centers = ['bulan' => [12.67, 123.87], 'gubat' => [12.92, 124.12], 'matnog' => [12.58, 124.08]];
        foreach ($centers as $town => [$lat, $lng]) {
            foreach (range(1, 4) as $n) {
                $this->ids["{$town}{$n}"] = $this->destination($town, ucfirst($town)." Spot {$n}", $lat + $n * 0.012, $lng + ($n % 2) * 0.01, $n % 2 ? 2 : 1);
            }
            foreach (range(1, 6) as $n) {
                FoodBeverage::create($this->fields($town, ucfirst($town)." Grill {$n}", $lat + $n * 0.008, $lng + 0.005) + [
                    'cuisine_type' => 'Filipino', 'price_range' => 'budget', 'status' => 'approved',
                ]);
            }
            Accommodation::create($this->fields($town, ucfirst($town).' Hotel', $lat + 0.03, $lng) + ['type' => 'Hotel', 'price_range' => 'moderate', 'status' => 'approved']);
        }

        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => function ($request) {
            $this->prompts[] = $request->data()['contents'][0]['parts'][0]['text'] ?? '';

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($this->reply)]]]]]]);
        }]);
    }

    private function fields(string $town, string $name, float $lat, float $lng): array
    {
        return [
            'name' => $name, 'description' => "{$name} used for automated testing.", 'municipality_id' => $this->towns[$town],
            'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng,
        ];
    }

    private function destination(string $town, string $name, float $lat, float $lng, int $category): int
    {
        $id = Destination::create($this->fields($town, $name, $lat, $lng) + [
            'category_id' => $category, 'created_by' => $this->tourist->id, 'status' => 'published', 'operating_hours' => '6:00 AM - 8:00 PM',
        ])->id;
        // Beaches (category 1) for a swim, the others for a walk, so a day can mix them.
        foreach (($category === 1 ? ['Swimming' => 120] : ['Nature walk' => 90]) + ['Viewpoint photos' => 60] as $activity => $minutes) {
            DB::table('activities')->insert([
                'destination_id' => $id, 'name' => "{$activity} at {$name}", 'best_time_to_visit' => 'Anytime', 'duration_minutes' => $minutes,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function generate(array $towns, int $days, array $options = []): Itinerary
    {
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $options + [
            'municipality_ids' => array_map(fn ($t) => $this->towns[$t], $towns),
            'num_days' => $days,
            'interests' => ['nature', 'beaches'],
            'travel_pace' => 'moderate',
            'budget' => 'moderate',
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(2 + $days)->toDateString(),
        ])->assertCreated();

        return Itinerary::with(['items.destination'])->latest('id')->firstOrFail();
    }

    private function day(int $number, string ...$destinations): array
    {
        return ['day_number' => $number, 'destinations' => array_map(fn ($d) => ['destination_id' => $this->ids[$d], 'reason' => 'Nice.'], $destinations)];
    }

    private function minutes(string $range): array
    {
        preg_match_all('/(\d{1,2}):(\d{2}) ([AP])M/', $range, $m, PREG_SET_ORDER);

        return array_map(fn ($t) => ((int) $t[1] % 12 + ($t[3] === 'P' ? 12 : 0)) * 60 + (int) $t[2], $m);
    }

    private function problems(Itinerary $itinerary): array
    {
        $rows = $itinerary->items->map(fn ($i) => $i->only(['day_number', 'sequence', 'item_type', 'destination_id', 'time_slot', 'notes', 'travel_time_from_prev', 'activities', 'preference_note']))->all();

        return app(ItineraryService::class)->scheduleProblems($rows, Destination::all(), [
            'num_days' => $itinerary->num_days,
            'start_date' => $itinerary->start_date?->toDateString(),
            'municipality_ids' => $itinerary->municipalities()->pluck('municipalities.id')->all(),
        ]);
    }

    /** Every day's lunch, breakfast and dinner as [start, end], by day. */
    private function meals(Itinerary $itinerary, string $meal): array
    {
        $found = [];
        foreach ($itinerary->items as $item) {
            if (str_starts_with((string) $item->notes, $meal)) {
                $found[$item->day_number][] = $this->minutes($item->time_slot);
            }
            foreach ((array) $item->activities as $activity) {
                if (stripos($activity['name'], $meal) !== false) {
                    $found[$item->day_number][] = $this->minutes($activity['time']);
                }
            }
        }

        return $found;
    }

    private function assertMealTimes(Itinerary $itinerary): void
    {
        $lunches = $this->meals($itinerary, 'Lunch');
        foreach (range(1, $itinerary->num_days) as $day) {
            $this->assertCount(1, $lunches[$day] ?? [], "Day {$day} has one lunch.");
            $this->assertSame([690, 750], $lunches[$day][0], "Day {$day}: lunch is 11:30 AM – 12:30 PM.");
        }
        foreach ($this->meals($itinerary, 'Breakfast') as $day => $slots) {
            foreach ($slots as [$start, $end]) {
                $this->assertTrue($start >= 360 && $end <= 480, "Day {$day}: breakfast is within 6:00 – 8:00 AM.");
            }
        }
        foreach ($this->meals($itinerary, 'Dinner') as $day => $slots) {
            foreach ($slots as [$start, $end]) {
                $this->assertTrue($start >= 1080 && $end <= 1170, "Day {$day}: dinner is within 6:00 – 7:30 PM.");
            }
        }
        // Nothing else happens during lunch.
        foreach ($itinerary->items as $item) {
            if (preg_match('/^Lunch/', (string) $item->notes)) {
                continue;
            }
            foreach ((array) $item->activities as $activity) {
                [$start, $end] = $this->minutes($activity['time']);
                if (stripos($activity['name'], 'lunch') === false) {
                    $this->assertFalse($start < 750 && $end > 690, "Day {$item->day_number}: \"{$activity['name']}\" overlaps lunch.");
                }
            }
            if ($item->item_type !== 'destination') {
                [$start, $end] = $this->minutes($item->time_slot);
                $this->assertFalse($start < 750 && $end > 690, "Day {$item->day_number}: {$item->notes} overlaps lunch.");
            }
        }
    }

    public function test_two_towns_by_public_transport_keep_lunch_every_day(): void
    {
        $this->reply = ['title' => 'Bulan and Gubat', 'days' => [$this->day(1, 'bulan1', 'bulan2', 'bulan3'), $this->day(2, 'gubat1', 'gubat2', 'gubat3')]];

        $itinerary = $this->generate(['bulan', 'gubat'], 2, ['transport_preference' => 'public_transport']);

        $this->assertMealTimes($itinerary);
        $this->assertSame([], $this->problems($itinerary));
        $legs = $itinerary->items->filter(fn ($i) => (int) $i->travel_time_from_prev > 0);
        $this->assertTrue($legs->every(fn ($i) => preg_match('/min (on foot|by tricycle|by jeepney)/', (string) $i->notes) === 1), 'Every leg says how to ride it.');
        $this->assertTrue($legs->contains(fn ($i) => preg_match('/tricycle|jeepney/', (string) $i->notes) === 1), 'Says which ride to take.');
    }

    public function test_three_towns_by_car_group_each_town_and_keep_the_rules(): void
    {
        // The AI mixes the towns up; the plan keeps each town's days together.
        $this->reply = ['title' => 'Three towns', 'days' => [
            $this->day(1, 'bulan1', 'bulan2'), $this->day(2, 'matnog1', 'matnog2'), $this->day(3, 'gubat1', 'gubat2', 'bulan3'),
        ]];

        $itinerary = $this->generate(['bulan', 'gubat', 'matnog'], 3, ['transport_preference' => 'private_car']);

        $this->assertMealTimes($itinerary);
        $this->assertSame([], $this->problems($itinerary));
        $visits = $itinerary->items->where('item_type', 'destination');
        $this->assertTrue($visits->groupBy('day_number')->every(fn ($day) => $day->pluck('destination.municipality_id')->unique()->count() === 1), 'A day stays in one town.');
        $order = $visits->sortBy(['day_number', 'sequence'])->pluck('destination.municipality_id')->unique()->values();
        $this->assertCount(3, $order, 'Every town is visited, one after the other.');
    }

    public function test_five_days_across_the_province_are_not_overloaded(): void
    {
        $this->reply = ['title' => 'Province', 'days' => [
            $this->day(1, 'bulan1', 'bulan2', 'bulan3', 'bulan4'), $this->day(2, 'bulan2'), $this->day(3, 'gubat1', 'gubat2', 'gubat3', 'gubat4'),
            $this->day(4, 'matnog1', 'matnog2', 'matnog3', 'matnog4'), $this->day(5, 'matnog3'),
        ]];

        $itinerary = $this->generate(['bulan', 'gubat', 'matnog'], 5);

        $this->assertMealTimes($itinerary);
        $this->assertSame([], $this->problems($itinerary));
        foreach ($itinerary->items->where('item_type', 'destination')->groupBy('day_number') as $day => $visits) {
            $this->assertLessThanOrEqual(3, $visits->count(), "Day {$day} has at most three attractions on a moderate pace.");
        }
    }

    public function test_a_budget_public_transport_trip_keeps_the_rules(): void
    {
        $this->reply = ['title' => 'Budget', 'days' => [$this->day(1, 'gubat1', 'gubat2'), $this->day(2, 'gubat3', 'gubat4')]];

        $itinerary = $this->generate(['gubat'], 2, ['transport_preference' => 'public_transport', 'budget' => 'budget', 'travelers' => 2]);

        $this->assertMealTimes($itinerary);
        $this->assertSame([], $this->problems($itinerary));
        $this->assertSame('budget', $itinerary->budget);
    }

    public function test_an_overloaded_day_loses_stops_instead_of_moving_lunch(): void
    {
        // Two-hour swims at four places on the arrival day, by public transport: not all fit.
        $this->reply = ['title' => 'Too much', 'days' => [$this->day(1, 'bulan1', 'bulan2', 'bulan3', 'bulan4')]];

        $itinerary = $this->generate(['bulan'], 1, ['transport_preference' => 'public_transport', 'travel_pace' => 'packed', 'arrival_time' => '07:00', 'departure_time' => '17:00']);

        $this->assertMealTimes($itinerary);
        $this->assertSame([], $this->problems($itinerary));
        $visits = $itinerary->items->where('item_type', 'destination');
        $this->assertLessThan(4, $visits->count(), 'Not every stop is forced in.');
        $this->assertTrue($itinerary->items->every(fn ($i) => $this->minutes($i->time_slot)[1] <= 17 * 60), 'Nothing runs past the departure.');
    }

    public function test_an_evening_activity_comes_after_dinner(): void
    {
        // Open late enough for an evening bonfire (7:30 PM, after dinner).
        Destination::whereKey($this->ids['bulan1'])->update(['operating_hours' => '6:00 AM - 10:00 PM']);
        DB::table('activities')->insert([
            'destination_id' => $this->ids['bulan1'], 'name' => 'Bonfire', 'best_time_to_visit' => 'Evening', 'duration_minutes' => 60,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->reply = ['title' => 'Evening', 'days' => [$this->day(1, 'bulan2'), $this->day(2, 'bulan3', 'bulan1')]];

        $itinerary = $this->generate(['bulan'], 2);

        $this->assertMealTimes($itinerary);
        $this->assertSame([], $this->problems($itinerary));
        $bonfire = $itinerary->items->flatMap(fn ($i) => (array) $i->activities)->first(fn ($a) => $a['name'] === 'Bonfire');
        $this->assertNotNull($bonfire);
        $dinner = $this->meals($itinerary, 'Dinner')[2][0];
        $this->assertGreaterThanOrEqual($dinner[1], $this->minutes($bonfire['time'])[0], 'The bonfire starts after dinner.');
    }

    public function test_no_sunrise_before_breakfast_unless_asked(): void
    {
        Destination::whereKey($this->ids['bulan1'])->update(['operating_hours' => '4:30 AM - 6:00 PM']);
        DB::table('activities')->insert([
            'destination_id' => $this->ids['bulan1'], 'name' => 'Sunrise viewing', 'best_time_to_visit' => 'Sunrise', 'duration_minutes' => 60,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->reply = ['title' => 'Sunrise', 'days' => [$this->day(1, 'bulan2'), $this->day(2, 'bulan1', 'bulan3')]];

        $plain = $this->generate(['bulan'], 2);
        $this->assertFalse($plain->items->contains(fn ($i) => collect((array) $i->activities)->contains(fn ($a) => $a['name'] === 'Sunrise viewing')));
        $this->assertMealTimes($plain);

        $early = $this->generate(['bulan'], 2, ['additional_preferences' => 'We want to see the sunrise.']);
        $this->assertTrue($early->items->contains(fn ($i) => collect((array) $i->activities)->contains(fn ($a) => $a['name'] === 'Sunrise viewing')));
        $this->assertSame([], $this->problems($early));
    }

    public function test_removing_a_visit_turns_its_lunch_break_into_an_ordinary_lunch(): void
    {
        // Arriving at 8 AM, the swim and photos run into lunchtime: the visit breaks for lunch at a grill nearby.
        $this->reply = ['title' => 'Break', 'days' => [$this->day(1, 'bulan2'), $this->day(2, 'bulan3')]];
        $itinerary = $this->generate(['bulan'], 2, ['arrival_time' => '08:00']);
        $break = $itinerary->items->first(fn ($i) => str_starts_with((string) $i->notes, 'Lunch break during your visit to '));
        $this->assertNotNull($break, 'A lunch break during a visit.');
        $this->assertSame([690, 750], $this->minutes($break->time_slot));
        $host = $itinerary->items->where('day_number', $break->day_number)->where('item_type', 'destination')
            ->first(fn ($i) => str_contains($break->notes, $i->destination->name));

        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$itinerary->id}", ['delete_item_ids' => [$host->id]])->assertOk();

        $notes = Itinerary::with('items')->find($itinerary->id)->items->firstWhere('id', $break->id)->notes;
        $this->assertStringStartsWith('Lunch near ', $notes);
        $this->assertStringNotContainsString($host->destination->name, $notes, 'It no longer names the removed visit.');
    }

    public function test_a_must_visit_place_comes_before_closer_ordinary_ones(): void
    {
        // The AI picks one Bulan stop; the planner tops the day up. Spot 4 is the farthest from
        // Spot 1, but the tourism office calls it a must-visit.
        $this->reply = ['title' => 'Bulan', 'days' => [$this->day(1, 'bulan1'), $this->day(2, 'bulan3')]];
        $plain = $this->generate(['bulan'], 2);
        $this->assertFalse($plain->items->contains('destination_id', $this->ids['bulan4']), 'Unclassified, the closer place fills the day.');

        Destination::whereKey($this->ids['bulan4'])->update(['tourism_priority' => 'iconic']);
        $itinerary = $this->generate(['bulan'], 2);

        $this->assertTrue($itinerary->items->contains('destination_id', $this->ids['bulan4']), 'The must-visit place is in the trip.');
        $this->assertStringContainsString('Standing: Must-visit', end($this->prompts));

        // Even when the AI fills every day with ordinary places, the must-visit one takes the
        // place of the weakest stop rather than being left out.
        $this->reply = ['title' => 'Bulan', 'days' => [$this->day(1, 'bulan1'), $this->day(2, 'bulan3', 'bulan2')]];
        $full = $this->generate(['bulan'], 2);
        $this->assertTrue($full->items->contains('destination_id', $this->ids['bulan4']), 'The must-visit place replaces a weaker stop.');
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_three_towns_in_two_days_share_a_day_when_close(): void
    {
        // Bulan and Matnog are about 25 km apart and share a day; Gubat keeps its own.
        $this->reply = ['title' => 'Three towns', 'days' => [$this->day(1, 'bulan1', 'matnog2'), $this->day(2, 'gubat1', 'gubat2')]];

        $itinerary = $this->generate(['bulan', 'matnog', 'gubat'], 2);

        $this->assertSame(2, $itinerary->items->max('day_number'));
        $this->assertEqualsCanonicalizing([$this->towns['bulan'], $this->towns['matnog'], $this->towns['gubat']], $itinerary->municipalities()->pluck('municipalities.id')->all());
        $visitedTowns = $itinerary->items->where('item_type', 'destination')->pluck('destination.municipality_id')->unique()->values()->all();
        $this->assertContains($this->towns['gubat'], $visitedTowns);
        $this->assertMealTimes($itinerary);
        $this->assertStringContainsString('share their days as one area', end($this->prompts));

        // Towns too far apart to share a day can't be squeezed in.
        DB::table('destinations')->where('municipality_id', $this->towns['matnog'])->update(['latitude' => DB::raw('latitude - 1')]);
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', [
            'municipality_ids' => [$this->towns['bulan'], $this->towns['matnog'], $this->towns['gubat']], 'num_days' => 2,
            'interests' => ['nature', 'beaches'], 'travel_pace' => 'moderate', 'budget' => 'moderate',
            'start_date' => now()->addDays(3)->toDateString(), 'end_date' => now()->addDays(4)->toDateString(),
        ])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'too far apart to share days'));
    }

    public function test_an_open_air_place_is_never_a_rainy_day_alternative(): void
    {
        DB::table('categories')->insert(['id' => 4, 'name' => 'Historical', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $make = fn (string $name, float $lng, array $extra = []) => Destination::create($this->fields('bulan', $name, 12.70, $lng) + ['category_id' => 4, 'created_by' => $this->tourist->id, 'status' => 'published'] + $extra);
        $sports = $make('Balogo Sports Complex', 123.880);
        $ruins = $make('Old Ruins', 123.881);
        $museum = $make('Bulan Museum', 123.882);
        $marked = $make('Covered Market Hall', 123.883, ['setting' => 'indoor']);
        $this->reply = ['title' => 'Bulan', 'days' => [$this->day(1, 'bulan1'), $this->day(2, 'bulan2')]];

        $itinerary = $this->generate(['bulan'], 2);
        $options = collect(app(ItineraryService::class)->backupOptions($itinerary))->flatMap(fn ($plan) => $plan['places']);

        $this->assertFalse($options->contains('id', $sports->id), 'A sports complex is open-air, not a shelter.');
        $this->assertFalse($options->contains('id', $ruins->id), 'Ruins are open-air.');
        $this->assertTrue($options->contains('id', $museum->id) || $options->contains('id', $marked->id));
        $this->assertFalse($options->contains(fn ($o) => str_contains($o['why'], 'Mostly under a roof')));

        Destination::whereKey($museum->id)->update(['setting' => 'outdoor']);
        $options = collect(app(ItineraryService::class)->backupOptions($itinerary->fresh()))->flatMap(fn ($plan) => $plan['places']);
        $this->assertFalse($options->contains('id', $museum->id), "The tourism office's setting wins over the name.");
    }

    public function test_a_long_beach_visit_has_lunch_there_instead_of_a_trip_back(): void
    {
        // No restaurant in Bulan and the hotel far away: the beach visit keeps a packed lunch.
        FoodBeverage::where('municipality_id', $this->towns['bulan'])->delete();
        Accommodation::where('municipality_id', $this->towns['bulan'])->update(['latitude' => 12.80, 'longitude' => 123.80]);
        $this->reply = ['title' => 'Beach', 'days' => [$this->day(1, 'bulan2'), $this->day(2, 'bulan4')]];

        $itinerary = $this->generate(['bulan'], 2, ['arrival_time' => '08:00']);

        $this->assertMealTimes($itinerary);
        $visit = $itinerary->items->firstWhere('destination_id', $this->ids['bulan2']);
        $lunch = collect($visit->activities)->first(fn ($a) => str_contains($a['name'], 'packed lunch'));
        $this->assertNotNull($lunch, 'Lunch is at the beach.');
        $this->assertSame([690, 750], $this->minutes($lunch['time']));
        $this->assertStringContainsString('outside food is allowed', $lunch['description']);
    }

    public function test_notes_are_short_specific_and_never_repeated(): void
    {
        $this->reply = ['title' => 'Notes', 'days' => [$this->day(1, 'bulan2', 'bulan1'), $this->day(2, 'bulan4', 'bulan3'), $this->day(3, 'bulan1')]];

        $itinerary = $this->generate(['bulan'], 3);

        $text = $itinerary->items->flatMap(fn ($i) => [$i->notes, $i->reason, $i->preference_note])->filter();
        foreach (["We're sorry", 'Please note', 'Keep in mind', 'Your base for this part of the trip', 'without another trip', 'so allow extra time'] as $phrase) {
            $this->assertFalse($text->contains(fn ($t) => str_contains($t, $phrase)), "No \"{$phrase}\".");
        }
        // The swimwear reminder is given once, not at every beach.
        $this->assertSame(1, $itinerary->items->filter(fn ($i) => str_contains((string) $i->preference_note, 'Bring swimwear'))->count());
        // Breakfast at the stay needs no explanation.
        $this->assertTrue($itinerary->items->filter(fn ($i) => str_starts_with((string) $i->notes, 'Breakfast at'))->every(fn ($i) => $i->reason === null));
    }

    public function test_each_day_has_a_title_and_a_highlight(): void
    {
        $this->reply = ['title' => 'Titled', 'days' => [
            ['day_number' => 1, 'title' => 'Bulan beaches and viewpoints', 'highlight_id' => $this->ids['bulan2'], 'destinations' => [['destination_id' => $this->ids['bulan2'], 'reason' => 'Swim.']]],
            ['day_number' => 2, 'destinations' => [['destination_id' => $this->ids['bulan3'], 'reason' => 'Walk.']]],
        ]];

        $itinerary = $this->generate(['bulan'], 2);
        $plans = collect($itinerary->day_plans)->mapWithKeys(fn ($plan, $day) => [(int) $day => $plan]);

        $this->assertSame(['title' => 'Bulan beaches and viewpoints', 'highlight_id' => $this->ids['bulan2']], $plans[1]);
        $this->assertNotEmpty($plans[2]['title'], 'Without an AI title, the day is named after its highlight.');
        $this->assertTrue($itinerary->items->where('day_number', 2)->contains('destination_id', $plans[2]['highlight_id']));
    }

    /** Where each day's breakfast is: 'stay' or 'restaurant', by day. */
    private function breakfasts(Itinerary $itinerary): array
    {
        return $itinerary->items->filter(fn ($i) => str_starts_with((string) $i->notes, 'Breakfast'))
            ->mapWithKeys(fn ($i) => [$i->day_number => $i->item_type === 'accommodation' ? 'stay' : 'restaurant'])->sortKeys()->all();
    }

    private function hotel(): Accommodation
    {
        return Accommodation::where('municipality_id', $this->towns['bulan'])->firstOrFail();
    }

    private function threeDays(array $options = []): Itinerary
    {
        // Enough budget eateries around the hotel that breakfast never runs out of places.
        if (! FoodBeverage::where('name', 'like', 'Hotel Street Eatery%')->exists()) {
            $hotel = $this->hotel();
            foreach (range(1, 4) as $n) {
                FoodBeverage::create($this->fields('bulan', "Hotel Street Eatery {$n}", (float) $hotel->latitude + $n * 0.001, (float) $hotel->longitude) + [
                    'cuisine_type' => 'Filipino', 'price_range' => 'budget', 'status' => 'approved',
                ]);
            }
        }
        $this->reply = ['title' => 'Breakfast', 'days' => [$this->day(1, 'bulan1'), $this->day(2, 'bulan2'), $this->day(3, 'bulan3')]];

        return $this->generate(['bulan'], 3, $options);
    }

    public function test_free_breakfast_at_the_hotel_is_where_breakfast_is_every_day(): void
    {
        $this->hotel()->update(['amenities' => ['Free Wi-Fi', 'Restaurant', 'Free Breakfast']]);
        // The booked room doesn't say either way (null): the hotel's own listing decides.
        $this->hotel()->rooms()->create(['name' => 'Queen Room', 'max_guests' => 2, 'price' => 2000, 'status' => 'approved', 'breakfast_included' => null]);

        $itinerary = $this->threeDays();

        $this->assertSame([2 => 'stay', 3 => 'stay'], $this->breakfasts($itinerary), 'No outside restaurant for breakfast.');
        $first = $itinerary->items->first(fn ($i) => str_starts_with((string) $i->notes, 'Breakfast'));
        $this->assertSame('Breakfast included with your room', $first->cost_note);
        $this->assertStringContainsString('lists free breakfast', (string) $first->preference_note);
        $this->assertMealTimes($itinerary);
    }

    public function test_breakfast_included_with_the_room_counts_even_without_the_amenity(): void
    {
        $room = $this->hotel()->rooms()->create(['name' => 'Twin Room', 'max_guests' => 2, 'price' => 2000, 'status' => 'approved', 'breakfast_included' => true]);

        $this->assertSame([2 => 'stay', 3 => 'stay'], $this->breakfasts($this->threeDays()));

        // The room's own "not included" wins over a property-wide "Free Breakfast".
        $room->update(['breakfast_included' => false]);
        $this->hotel()->update(['amenities' => ['Free Breakfast', 'Restaurant']]);
        $itinerary = $this->threeDays(['budget' => 'budget']);
        $this->assertSame([2 => 'restaurant', 3 => 'restaurant'], $this->breakfasts($itinerary), 'On a budget, a budget eatery beats paying extra at the hotel.');
    }

    public function test_paid_breakfast_follows_the_budget(): void
    {
        $this->hotel()->update(['amenities' => ['Restaurant'], 'dining_details' => 'The restaurant serves breakfast from 6:00 AM.']);

        $this->assertSame([2 => 'restaurant', 3 => 'restaurant'], $this->breakfasts($this->threeDays(['budget' => 'budget'])));
        $luxury = $this->threeDays(['budget' => 'luxury']);
        $this->assertSame([2 => 'stay', 3 => 'stay'], $this->breakfasts($luxury), 'Not on a budget: breakfast at the hotel is the convenient choice.');
        $this->assertStringContainsString('at extra cost', (string) $luxury->items->first(fn ($i) => str_starts_with((string) $i->notes, 'Breakfast'))->preference_note);
    }

    public function test_a_hotel_without_breakfast_sends_breakfast_to_a_nearby_eatery(): void
    {
        $this->hotel()->update(['amenities' => ['Free Wi-Fi'], 'dining_details' => null]);

        $itinerary = $this->threeDays();

        $this->assertSame([2 => 'restaurant', 3 => 'restaurant'], $this->breakfasts($itinerary));
        $this->assertMealTimes($itinerary);
    }

    public function test_without_accommodation_breakfast_is_near_the_first_stop(): void
    {
        $itinerary = $this->threeDays(['accommodation_needed' => false]);

        $breakfasts = $itinerary->items->filter(fn ($i) => str_starts_with((string) $i->notes, 'Breakfast'));
        $this->assertNotEmpty($breakfasts);
        $this->assertTrue($breakfasts->every(fn ($i) => $i->item_type === 'food_beverage'));
        $this->assertFalse($itinerary->items->contains('item_type', 'accommodation'));
    }

    public function test_the_check_rejects_a_late_lunch_and_overlaps(): void
    {
        $check = fn (array $rows) => app(ItineraryService::class)->scheduleProblems($rows, Destination::all(), ['num_days' => 1]);
        $row = fn (int $seq, string $type, string $slot, string $notes, int $travel = 0) => [
            'day_number' => 1, 'sequence' => $seq, 'item_type' => $type, 'destination_id' => $type === 'destination' ? $this->ids['bulan1'] : null,
            'time_slot' => $slot, 'notes' => $notes, 'travel_time_from_prev' => $travel,
        ];
        $breakfast = $row(1, 'food_beverage', '7:00 AM – 7:45 AM', 'Breakfast near the hotel.');
        $visit = $row(2, 'destination', '8:00 AM – 11:15 AM', 'Visit.', 15);
        $dinner = $row(9, 'food_beverage', '6:30 PM – 7:30 PM', 'Dinner near the hotel.', 10);

        $this->assertSame([], $check([$breakfast, $visit, $row(3, 'food_beverage', '11:30 AM – 12:30 PM', 'Lunch near the beach.', 10), $dinner]));
        $this->assertContains('Day 1: Lunch at 1:00 PM – 2:00 PM is outside 11:30 AM – 12:30 PM.',
            $check([$breakfast, $visit, $row(3, 'food_beverage', '1:00 PM – 2:00 PM', 'Lunch near the beach.', 10), $dinner]));
        $this->assertContains("Day 1: there's no lunch in the plan.", $check([$breakfast, $visit, $dinner]));
        $this->assertNotSame([], $check([$breakfast, $row(2, 'destination', '8:00 AM – 12:00 PM', 'Visit.', 15), $row(3, 'food_beverage', '11:30 AM – 12:30 PM', 'Lunch near the beach.', 10), $dinner]), 'A stop running into lunch.');
        $this->assertContains('Day 1: Dinner at 8:00 PM – 9:00 PM is outside 6:00 PM – 7:30 PM.',
            $check([$breakfast, $visit, $row(3, 'food_beverage', '11:30 AM – 12:30 PM', 'Lunch near the beach.', 10), $row(9, 'food_beverage', '8:00 PM – 9:00 PM', 'Dinner near the hotel.', 10)]));
        $this->assertContains('Day 1: Breakfast at 8:30 AM – 9:15 AM is outside 6:00 AM – 8:00 AM.',
            $check([$row(1, 'food_beverage', '8:30 AM – 9:15 AM', 'Breakfast near the hotel.'), $row(2, 'destination', '9:30 AM – 11:15 AM', 'Visit.', 15), $row(3, 'food_beverage', '11:30 AM – 12:30 PM', 'Lunch near the beach.', 10), $dinner]));
    }
}

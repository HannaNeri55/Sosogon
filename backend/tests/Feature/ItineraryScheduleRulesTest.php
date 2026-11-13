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
        $this->assertTrue($legs->every(fn ($i) => str_contains((string) $i->notes, 'public transport estimate')), 'Every leg is a labelled public-transport estimate.');
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

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
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * How a generated trip reads to a tourist, with a faked AI reply over a small town:
 * - A hotel in the middle, three beaches, a weekday-only museum, a hilltop viewpoint, an eco
 *   park and a day-use resort close together, and a waterfall ~25 km away.
 * - Ten food places and a souvenir shop around the hotel.
 * The AI reply is deliberately poor (three beaches on one day, a repeat, an unknown ID, the
 * museum on a Sunday); the planner must still produce a sensible trip. The trip starts on a
 * Saturday, so Day 2 is a Sunday and Day 3 a Monday.
 */
class ItineraryPlanningQualityTest extends TestCase
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
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Testville', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1 => 'Beach', 2 => 'Mountain', 3 => 'Waterfall', 4 => 'Historical', 6 => 'Park', 9 => 'Resort'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->ids = [
            'northBeach' => $this->destination('North Beach', 1, 12.680, 123.880, '6:00 AM - 6:00 PM', 'Swimming'),
            'southBeach' => $this->destination('South Beach', 1, 12.660, 123.880, '6:00 AM - 6:00 PM', 'Swimming'),
            'eastCove' => $this->destination('East Cove', 1, 12.670, 123.892, null, 'Snorkeling'),
            'museum' => $this->destination('Heritage Museum', 4, 12.672, 123.884, 'Monday to Friday: 8:00 AM - 5:00 PM', 'Guided heritage tour', 'indoor'),
            'viewpoint' => $this->destination('Hilltop Viewpoint', 2, 12.676, 123.874, '6:00 AM - 7:00 PM', 'Photo walk'),
            'ecoPark' => $this->destination('Eco Park', 6, 12.664, 123.886, '7:00 AM - 5:00 PM', 'Bird watching'),
            'dayResort' => $this->destination('Day Resort', 9, 12.668, 123.874, '8:00 AM - 8:00 PM', 'Pool lounging'),
            'farFalls' => $this->destination('Far Falls', 3, 12.900, 123.880, '7:00 AM - 5:00 PM', 'Swimming at the basin'),
        ];
        $this->ids['hotel'] = Accommodation::create($this->listing('Central Inn', 12.670, 123.880) + ['type' => 'Hotel', 'price_range' => 'moderate', 'status' => 'approved'])->id;
        foreach (range(0, 9) as $n) {
            FoodBeverage::create($this->listing("Eatery {$n}", 12.664 + ($n % 5) * 0.004, 123.876 + intdiv($n, 5) * 0.008) + [
                'cuisine_type' => 'Filipino', 'price_range' => 'moderate', 'status' => 'approved', 'operating_hours' => '6:00 AM - 9:00 PM',
            ]);
        }
        RetailShop::create($this->listing('Pasalubong Center', 12.671, 123.879) + ['status' => 'approved']);

        $this->reply = ['title' => 'Testville getaway', 'days' => [
            ['day_number' => 1, 'destinations' => $this->picks('northBeach', 'southBeach', 'eastCove')],
            ['day_number' => 2, 'destinations' => [...$this->picks('farFalls', 'museum', 'northBeach'), ['destination_id' => 9999, 'reason' => 'Made up.']]],
            ['day_number' => 3, 'destinations' => $this->picks('southBeach', 'viewpoint')],
        ]];

        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($this->reply)]]]]],
        ])]);
    }

    private function listing(string $name, float $lat, float $lng): array
    {
        return [
            'name' => $name, 'description' => "{$name} is a test listing. It has a second sentence.", 'municipality_id' => $this->town,
            'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng,
        ];
    }

    private function destination(string $name, int $category, float $lat, float $lng, ?string $hours, string $activity, string $type = 'outdoor'): int
    {
        $id = Destination::create($this->listing($name, $lat, $lng) + [
            'category_id' => $category, 'operating_hours' => $hours, 'entrance_fee' => 20, 'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        DB::table('activities')->insert([
            'destination_id' => $id, 'name' => $activity, 'type' => $type, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function picks(string ...$keys): array
    {
        return array_map(fn ($key) => ['destination_id' => $this->ids[$key], 'reason' => "Picked {$key}."], $keys);
    }

    /** The coming Saturday (at least a day away), so Day 2 is a Sunday. */
    private function saturday(): Carbon
    {
        return now()->addDay()->next(Carbon::SATURDAY);
    }

    private function generate(array $overrides = []): Itinerary
    {
        $start = $this->saturday();
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $overrides + [
            'municipality_id' => $this->town,
            'num_days' => 3,
            'interests' => ['nature', 'culture', 'relaxation'],
            'travel_pace' => 'moderate',
            'budget' => 'moderate',
            'travelers' => 2,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(2)->toDateString(),
        ])->assertCreated();

        return Itinerary::with(['items.destination.category'])->latest('id')->firstOrFail();
    }

    /** "5:15 AM – 6:15 AM" → [315, 375] */
    private function minutes(string $range): array
    {
        return array_map(function ($part) {
            $t = Carbon::createFromFormat('g:i A', trim($part));

            return $t->hour * 60 + $t->minute;
        }, explode('–', $range));
    }

    /** The trip's stops grouped by day, each day in order. */
    private function days(Itinerary $itinerary)
    {
        return $itinerary->items->sortBy('sequence')->groupBy('day_number')->sortKeys();
    }

    /** What the planner's own rule check says about a saved trip. */
    private function problems(Itinerary $itinerary): array
    {
        $rows = $itinerary->items->map(fn ($i) => $i->only(['day_number', 'sequence', 'item_type', 'destination_id', 'time_slot', 'notes', 'travel_time_from_prev']))->all();

        return app(ItineraryService::class)->scheduleProblems($rows, Destination::all(), ['start_date' => $this->saturday()->toDateString()]);
    }

    /** Day 1's stops, in order, for an arrival time ("HH:MM"; null sends none). */
    private function dayOne(?string $arrival)
    {
        return $this->days($this->generate($arrival === null ? [] : ['arrival_time' => $arrival]))->first()->values();
    }

    private function startsWith($stops, string $prefix)
    {
        return $stops->filter(fn ($i) => str_starts_with((string) $i->notes, $prefix));
    }

    public function test_day_one_starts_with_the_arrival_at_the_stay(): void
    {
        $first = $this->dayOne(null)->first();

        $this->assertSame('accommodation', $first->item_type);
        $this->assertSame($this->ids['hotel'], $first->accommodation_id);
        // Central Inn lists no check-in time, so checking in on arrival isn't assumed.
        $this->assertStringStartsWith('Arrive at Central Inn and check in if your room is ready.', $first->notes);
        $this->assertStringContainsString("doesn't list a check-in time", $first->preference_note);
        $this->assertStringNotContainsString('hold your bags', $first->preference_note.$first->reason);
    }

    public function test_day_one_never_starts_before_the_arrival_and_follows_the_rules(): void
    {
        foreach (['06:00', '08:00', '12:00', '14:30', '16:00', '17:00', '21:30', null] as $arrival) {
            $day1 = $this->dayOne($arrival);
            $at = $arrival === null ? 600 : (int) substr($arrival, 0, 2) * 60 + (int) substr($arrival, 3, 2);

            $this->assertSame($at, $this->minutes($day1->first()->time_slot)[0], "Arrival {$arrival}: Day 1 starts with the arrival.");
            foreach ($day1 as $stop) {
                $this->assertGreaterThanOrEqual($at, $this->minutes($stop->time_slot)[0], "Arrival {$arrival}: stop {$stop->sequence} starts too early.");
            }
            // Meals in their windows, no overlaps, no repeats, opening hours kept.
            $this->assertSame([], $this->problems(Itinerary::with('items')->latest('id')->first()), "Arrival {$arrival} breaks a planning rule.");
        }
    }

    public function test_a_6am_arrival_gets_breakfast_and_a_morning_of_sightseeing(): void
    {
        $day1 = $this->dayOne('06:00');

        $breakfast = $this->startsWith($day1, 'Breakfast')->first();
        $this->assertNotNull($breakfast, 'An early arrival still gets breakfast.');
        $this->assertGreaterThanOrEqual(6 * 60 + 45, $this->minutes($breakfast->time_slot)[0]);
        $this->assertTrue($day1->contains(fn ($i) => $i->item_type === 'destination' && $this->minutes($i->time_slot)[0] < 11 * 60), 'Sightseeing starts in the morning.');
        $this->assertCount(1, $this->startsWith($day1, 'Lunch'));
    }

    public function test_an_8am_arrival_starts_the_schedule_at_8(): void
    {
        $day1 = $this->dayOne('08:00');

        $this->assertSame('8:00 AM – 8:45 AM', $day1->first()->time_slot);
        $this->assertStringContainsString('You arrive around 8:00 AM.', $day1->first()->notes);
        $this->assertTrue($day1->contains(fn ($i) => $i->item_type === 'destination' && $this->minutes($i->time_slot)[0] < 12 * 60), 'A morning attraction.');
        $this->assertCount(1, $this->startsWith($day1, 'Breakfast'));
        $this->assertCount(1, $this->startsWith($day1, 'Lunch'));
    }

    public function test_a_noon_arrival_gets_lunch_then_afternoon_sightseeing(): void
    {
        $day1 = $this->dayOne('12:00');

        $this->assertEmpty($this->startsWith($day1, 'Breakfast'), 'No breakfast at noon.');
        $lunch = $this->startsWith($day1, 'Lunch')->first();
        $this->assertNotNull($lunch);
        $this->assertGreaterThanOrEqual(12 * 60 + 45, $this->minutes($lunch->time_slot)[0]);
        $visits = $day1->where('item_type', 'destination');
        $this->assertNotEmpty($visits);
        $this->assertTrue($visits->every(fn ($i) => $i->sequence > $lunch->sequence), 'Sightseeing comes after lunch.');
    }

    public function test_a_half_two_arrival_gets_a_visit_that_fits_the_afternoon(): void
    {
        // 2:30 PM, as the planner form's time field sends it.
        $day1 = $this->dayOne('14:30');

        $this->assertSame('2:30 PM – 3:15 PM', $day1->first()->time_slot);
        $this->assertStringContainsString('You arrive around 2:30 PM.', $day1->first()->notes);
        $this->assertEmpty($day1->filter(fn ($i) => preg_match('/^(Breakfast|Lunch)/', (string) $i->notes)), 'No breakfast or lunch after 2:30 PM.');
        $visit = $day1->firstWhere('item_type', 'destination');
        $this->assertNotNull($visit, 'The afternoon still has an attraction.');
        $this->assertLessThanOrEqual(17 * 60, $this->minutes($visit->time_slot)[1], 'It fits before the day ends.');
        // The AI's Day 1 beach needs longer, so it moves to another day instead of being cut short.
        $this->assertTrue(Itinerary::latest('id')->first()->items()->where('destination_id', $this->ids['northBeach'])->where('day_number', '>', 1)->exists());
    }

    public function test_a_5pm_arrival_is_arrival_rest_and_dinner(): void
    {
        $day1 = $this->dayOne('17:00');

        $this->assertSame(['accommodation', 'accommodation', 'food_beverage'], $day1->pluck('item_type')->all());
        $this->assertStringStartsWith('Free time at Central Inn', $day1[1]->notes);
        $this->assertStringStartsWith('Dinner', $day1[2]->notes);
        $this->assertGreaterThanOrEqual(18 * 60, $this->minutes($day1[2]->time_slot)[0]);
        // The AI's Day 1 pick isn't lost: it moves to a later day.
        $this->assertTrue(Itinerary::latest('id')->first()->items()->where('destination_id', $this->ids['northBeach'])->where('day_number', '>', 1)->exists());
    }

    public function test_a_4pm_arrival_has_no_sightseeing_only_rest_and_dinner(): void
    {
        $day1 = $this->dayOne('16:00');

        $this->assertEmpty($day1->where('item_type', 'destination'), 'No visit fits before the visiting day ends.');
        $this->assertEmpty($day1->filter(fn ($i) => preg_match('/^(Breakfast|Lunch|Afternoon merienda)/', (string) $i->notes)));
        $this->assertStringStartsWith('Dinner', $day1->last()->notes);
        $this->assertTrue(Itinerary::latest('id')->first()->items()->where('destination_id', $this->ids['northBeach'])->where('day_number', '>', 1)->exists(), 'The Day 1 pick moves to a later day.');
    }

    public function test_a_late_night_arrival_has_no_dinner(): void
    {
        $day1 = $this->dayOne('21:30');

        $this->assertCount(1, $day1);
        $this->assertSame('9:30 PM – 10:15 PM', $day1->first()->time_slot);
        $this->assertStringContainsString('after the usual dinner time', $day1->first()->preference_note);
    }

    public function test_without_an_arrival_time_day_one_assumes_ten_am(): void
    {
        foreach ([[], ['arrival_time' => null], ['arrival_time' => '']] as $input) {
            $day1 = $this->days($this->generate($input))->first()->values();
            $this->assertSame('10:00 AM – 10:45 AM', $day1->first()->time_slot);
            $this->assertStringContainsString('Day 1 assumes you arrive around 10:00 AM', $day1->first()->notes);
            $this->assertGreaterThanOrEqual(2, $day1->where('item_type', 'destination')->count(), 'Two attractions, as before.');
        }
    }

    public function test_a_listed_check_in_time_is_followed(): void
    {
        Accommodation::whereKey($this->ids['hotel'])->update(['operating_hours' => 'Check-in: 2:00 PM–9:00 PM; check-out before 11:00 AM.']);

        // Arriving before check-in: arrive first, check in later, at or after 2:00 PM.
        $day1 = $this->dayOne('08:00');
        $this->assertStringStartsWith('Arrive at Central Inn. You arrive around 8:00 AM.', $day1->first()->notes);
        $this->assertStringContainsString('listed check-in at 2:00 PM', $day1->first()->preference_note);
        $checkIn = $this->startsWith($day1, 'Check in at Central Inn')->first();
        $this->assertNotNull($checkIn, 'Day 1 has a check-in stop.');
        $this->assertGreaterThanOrEqual(14 * 60, $this->minutes($checkIn->time_slot)[0]);

        // Arriving after it: check in straight away.
        $day1 = $this->dayOne('15:00');
        $this->assertStringStartsWith('Arrive and check in at Central Inn.', $day1->first()->notes);
        $this->assertEmpty($this->startsWith($day1, 'Check in at'));
    }

    public function test_an_invalid_arrival_time_is_rejected(): void
    {
        $start = $this->saturday();
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', [
            'municipality_id' => $this->town, 'num_days' => 3, 'interests' => ['nature'], 'travel_pace' => 'moderate',
            'budget' => 'moderate', 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString(),
            'arrival_time' => '2:30 PM',
        ])->assertUnprocessable()->assertJsonValidationErrors('arrival_time');
    }

    public function test_guests_can_give_an_arrival_time_too(): void
    {
        $start = $this->saturday();
        $this->postJson('/api/v1/itineraries/guest-generate', [
            'municipality_ids' => [$this->town], 'num_days' => 3, 'interests' => ['nature', 'culture', 'relaxation'], 'travel_pace' => 'moderate',
            'budget' => 'moderate', 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString(),
            'arrival_time' => '14:30',
        ])->assertCreated()->assertJsonStructure(['itinerary_id', 'claim_token', 'preview']);

        $checkIn = Itinerary::latest('id')->firstOrFail()->items()->where('day_number', 1)->orderBy('sequence')->first();
        $this->assertSame('2:30 PM – 3:15 PM', $checkIn->time_slot);
    }

    public function test_attractions_lead_and_food_stops_are_only_meals(): void
    {
        $days = $this->days($this->generate());

        $this->assertGreaterThanOrEqual(2, $days[1]->where('item_type', 'destination')->count(), 'Arrival day: two attractions.');
        $this->assertGreaterThanOrEqual(3, $days[3]->where('item_type', 'destination')->count(), 'A full day: three attractions.');
        foreach ($days as $day => $stops) {
            $food = $stops->where('item_type', 'food_beverage');
            $meals = $food->map(fn ($i) => preg_match('/^(Breakfast|Lunch|Afternoon merienda|Dinner)/', $i->notes, $m) ? $m[1] : null);
            $this->assertNotContains(null, $meals->all(), "Day {$day}: every food stop is a meal.");
            $this->assertSame($meals->count(), $meals->unique()->count(), "Day {$day}: no meal twice.");
        }
    }

    public function test_lunch_is_between_eleven_and_noon(): void
    {
        $itinerary = $this->generate();

        $lunches = $itinerary->items->filter(fn ($i) => str_starts_with((string) $i->notes, 'Lunch'));
        $this->assertSame([1, 2, 3], $lunches->pluck('day_number')->unique()->sort()->values()->all(), 'Every day has lunch.');
        foreach ($lunches as $lunch) {
            [$start] = $this->minutes($lunch->time_slot);
            $this->assertGreaterThanOrEqual(660, $start, "Day {$lunch->day_number}: lunch starts at 11:00 AM or later.");
            // Around noon (12:15 PM at the latest, after a short drive); Day 1 may wait for a late arrival.
            $this->assertLessThanOrEqual($lunch->day_number === 1 ? 810 : 735, $start, "Day {$lunch->day_number}: lunch starts around noon.");
        }
    }

    public function test_removing_a_visit_turns_its_lunch_break_into_an_ordinary_lunch(): void
    {
        $itinerary = $this->generate();
        $break = $itinerary->items->first(fn ($i) => str_starts_with((string) $i->notes, 'Lunch break during your visit to '));
        if (! $break) {
            $this->markTestSkipped('This plan has no lunch break during a visit.');
        }
        $host = $itinerary->items->where('day_number', $break->day_number)->where('item_type', 'destination')
            ->first(fn ($i) => str_contains($break->notes, $i->destination->name));

        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$itinerary->id}", ['delete_item_ids' => [$host->id]])->assertOk();

        $notes = Itinerary::with('items')->find($itinerary->id)->items->firstWhere('id', $break->id)->notes;
        $this->assertStringStartsWith('Lunch near ', $notes);
        $this->assertStringNotContainsString($host->destination->name, $notes, 'It no longer names the removed visit.');
    }

    public function test_merienda_comes_in_the_middle_of_the_afternoon(): void
    {
        $meriendas = $this->generate()->items->filter(fn ($i) => str_starts_with((string) $i->notes, 'Afternoon merienda'));

        $this->assertNotEmpty($meriendas);
        foreach ($meriendas as $merienda) {
            [$start] = $this->minutes($merienda->time_slot);
            $this->assertGreaterThanOrEqual(870, $start);
            $this->assertLessThanOrEqual(990, $start);
        }
    }

    public function test_each_days_destinations_are_close_together(): void
    {
        $days = $this->days($this->generate());

        foreach ($days as $day => $stops) {
            $places = $stops->where('item_type', 'destination')->pluck('destination');
            foreach ($places as $a) {
                foreach ($places as $b) {
                    $km = 111 * hypot($a->latitude - $b->latitude, ($a->longitude - $b->longitude) * cos(deg2rad($a->latitude)));
                    $this->assertLessThan(20, $km, "Day {$day}: {$a->name} and {$b->name} are too far apart for one day.");
                }
            }
        }
        $fallsDay = $days->first(fn ($stops) => $stops->contains('destination_id', $this->ids['farFalls']));
        $this->assertSame(1, $fallsDay->where('item_type', 'destination')->count(), 'The far waterfall gets a day of its own.');
    }

    public function test_travel_times_follow_the_distance_and_say_they_are_estimates(): void
    {
        $legs = $this->generate()->items->filter(fn ($i) => $i->travel_time_from_prev > 0);

        $this->assertNotEmpty($legs);
        foreach ($legs as $leg) {
            $this->assertSame((int) (ceil((float) $leg->distance_from_prev_km / 30 * 60 / 5) * 5), $leg->travel_time_from_prev);
            $this->assertStringContainsString("about {$leg->travel_time_from_prev} min (estimated from the map distance", $leg->notes);
        }
    }

    public function test_google_driving_times_are_used_when_available(): void
    {
        config(['services.google_maps.api_key' => 'maps-test-key']);
        // Every measured route is a 25-minute, 12.3 km drive.
        Http::fake(['maps.googleapis.com/*' => function ($request) {
            $count = substr_count($request->data()['origins'], '|') + 1;
            $row = ['elements' => array_fill(0, $count, ['status' => 'OK', 'duration' => ['value' => 1500], 'distance' => ['value' => 12300]])];

            return Http::response(['rows' => array_fill(0, $count, $row)]);
        }]);

        $items = $this->generate()->items->sortBy(['day_number', 'sequence'])->values();

        $drives = $items->filter(fn ($item, $n) => $n > 0 && $item->item_type === 'destination'
            && $items[$n - 1]->item_type === 'destination' && $items[$n - 1]->day_number === $item->day_number);
        $this->assertNotEmpty($drives, 'Some day goes straight from one destination to the next.');
        foreach ($drives as $drive) {
            $this->assertSame(25, $drive->travel_time_from_prev);
            $this->assertEquals(12.3, (float) $drive->distance_from_prev_km);
            $this->assertStringContainsString('about 25 min (driving time from Google Maps)', $drive->notes);
        }
    }

    public function test_repeated_and_unknown_destinations_are_dropped(): void
    {
        $ids = $this->generate()->items->pluck('destination_id')->filter();

        $this->assertSame($ids->unique()->count(), $ids->count(), 'No destination repeats.');
        $this->assertNotContains(9999, $ids->all());
        $this->assertTrue($ids->every(fn ($id) => Destination::whereKey($id)->exists()));
    }

    public function test_returning_to_the_hotel_is_allowed(): void
    {
        $itinerary = $this->generate();

        $hotelStops = $itinerary->items->where('accommodation_id', $this->ids['hotel']);
        $this->assertGreaterThanOrEqual(3, $hotelStops->count(), 'Check-in, rest and check-out are all at the hotel.');
        $this->assertTrue($hotelStops->contains(fn ($i) => str_starts_with($i->notes, 'Check out of Central Inn.') && $i->day_number === 3));
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_no_two_beaches_on_the_same_day_or_back_to_back(): void
    {
        foreach ($this->days($this->generate()) as $day => $stops) {
            $categories = $stops->where('item_type', 'destination')->map(fn ($i) => $i->destination->category->name)->values();
            $this->assertLessThanOrEqual(1, $categories->filter(fn ($c) => in_array($c, ['Beach', 'Waterfall'], true))->count(), "Day {$day} has more than one beach.");
            foreach ($categories as $n => $category) {
                $this->assertNotSame($categories[$n - 1] ?? null, $category, "Day {$day} repeats a kind of place back to back.");
            }
        }
    }

    public function test_opening_hours_and_closing_days_are_respected(): void
    {
        $itinerary = $this->generate();

        $museum = $itinerary->items->firstWhere('destination_id', $this->ids['museum']);
        $this->assertNotNull($museum, 'The museum is open on Monday, Day 3.');
        $this->assertSame(3, $museum->day_number, 'It is closed on the weekend.');
        [$start, $end] = $this->minutes($museum->time_slot);
        $this->assertGreaterThanOrEqual(480, $start);
        $this->assertLessThanOrEqual(1020, $end);
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_no_stops_overlap_at_any_pace(): void
    {
        foreach (['relaxed', 'moderate', 'packed'] as $pace) {
            $itinerary = $this->generate(['travel_pace' => $pace]);
            foreach ($this->days($itinerary) as $day => $stops) {
                $end = null;
                foreach ($stops->reject(fn ($i) => str_starts_with((string) $i->notes, 'Lunch break')) as $stop) {
                    [$from, $to] = $this->minutes($stop->time_slot);
                    if ($end !== null) {
                        $this->assertGreaterThanOrEqual($end + (int) $stop->travel_time_from_prev, $from, "{$pace} day {$day}, stop {$stop->sequence} overlaps the one before.");
                    }
                    $end = $to;
                }
            }
            $this->assertSame([], $this->problems($itinerary), "{$pace} pace breaks a planning rule.");
        }
    }

    public function test_days_feature_different_destinations(): void
    {
        $byDay = $this->days($this->generate())->map(fn ($stops) => $stops->pluck('destination_id')->filter()->values());

        $all = $byDay->flatten();
        $this->assertSame($all->unique()->count(), $all->count(), 'No destination is visited on two days.');
        foreach ($byDay as $day => $ids) {
            if ($ids->count() > 1) {
                $kinds = Destination::with('category')->findMany($ids)->pluck('category.name');
                $this->assertGreaterThan(1, $kinds->unique()->count(), "Day {$day} offers only one kind of place.");
            }
        }
    }

    public function test_too_few_destinations_still_gives_a_plan_without_repeats(): void
    {
        Destination::whereKeyNot($this->ids['northBeach'])->update(['status' => 'draft']);
        $this->reply = ['title' => 'Short on places', 'days' => [['day_number' => 1, 'destinations' => $this->picks('northBeach', 'southBeach')]]];

        $itinerary = $this->generate();

        $visits = $itinerary->items->where('item_type', 'destination');
        $this->assertSame([$this->ids['northBeach']], $visits->pluck('destination_id')->all());
        foreach ([2, 3] as $day) {
            $this->assertTrue($itinerary->items->contains(fn ($i) => $i->day_number === $day && str_starts_with((string) $i->notes, 'Free day at Central Inn')), "Day {$day} is a free day at the stay.");
        }
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_a_late_listed_check_in_comes_before_dinner(): void
    {
        Accommodation::whereKey($this->ids['hotel'])->update(['operating_hours' => 'Check-in 4:00 PM / Check-out 11:00 AM']);

        $day1 = $this->dayOne('12:00');

        $this->assertStringStartsWith('Arrive at Central Inn.', $day1->first()->notes);
        $checkIn = $this->startsWith($day1, 'Check in at Central Inn')->first();
        $this->assertNotNull($checkIn);
        $this->assertGreaterThanOrEqual(16 * 60, $this->minutes($checkIn->time_slot)[0], 'Not before the listed 4:00 PM.');
        $this->assertLessThan($day1->last()->sequence, $checkIn->sequence, 'Checked in before dinner.');
        $this->assertEmpty($day1->filter(fn ($i) => $i->sequence < $checkIn->sequence && preg_match('/^(Back to|Free time at)/', (string) $i->notes)), 'No resting in a room that is not ready.');
        $this->assertSame([], $this->problems(Itinerary::with('items')->latest('id')->first()));
    }

    public function test_a_full_day_has_attractions_lunch_merienda_shopping_and_dinner_in_order(): void
    {
        $day3 = $this->days($this->generate())[3]->values();

        $this->assertGreaterThanOrEqual(3, $day3->where('item_type', 'destination')->count());
        $order = $day3->map(fn ($i) => match (true) {
            str_starts_with((string) $i->notes, 'Lunch') => 'lunch',
            str_starts_with((string) $i->notes, 'Afternoon merienda') => 'merienda',
            str_starts_with((string) $i->notes, 'Dinner') => 'dinner',
            $i->item_type === 'retail_shop' => 'shop',
            default => null,
        })->filter()->values()->all();
        $this->assertSame(['lunch', 'shop', 'merienda', 'dinner'], $order);
        $shop = $day3->firstWhere('item_type', 'retail_shop');
        $this->assertLessThanOrEqual($this->minutes($day3->last()->time_slot)[0], $this->minutes($shop->time_slot)[1], 'Shopping is over before dinner.');
        $this->assertSame([], $this->problems(Itinerary::with('items')->latest('id')->first()));
    }

    public function test_with_no_restaurants_every_lunch_is_at_the_stay_or_explained(): void
    {
        FoodBeverage::query()->update(['status' => 'draft']);

        $itinerary = $this->generate();

        $this->assertEmpty($itinerary->items->where('item_type', 'food_beverage'));
        foreach ($this->days($itinerary) as $day => $stops) {
            $handled = $stops->contains(fn ($i) => str_starts_with((string) $i->notes, 'Lunch at Central Inn'))
                || $stops->contains(fn ($i) => preg_match('/packed lunch|picnic|snacks/', (string) $i->preference_note));
            $this->assertTrue($handled, "Day {$day}: lunch is neither planned nor explained.");
        }
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_sunset_and_evening_spots_that_close_early_are_still_visited_in_opening_hours(): void
    {
        $sunset = $this->destination('Sunset Point', 6, 12.671, 123.878, '8:00 AM - 5:00 PM', 'Sunset watching');
        $firefly = $this->destination('Firefly Park', 6, 12.669, 123.882, '8:00 AM - 5:00 PM', 'Firefly watching');
        $this->reply = ['title' => 'Early closers', 'days' => [
            ['day_number' => 2, 'destinations' => [['destination_id' => $sunset], ['destination_id' => $firefly]]],
        ]];

        $itinerary = $this->generate();

        foreach ([$sunset, $firefly] as $id) {
            $visit = $itinerary->items->firstWhere('destination_id', $id);
            $this->assertNotNull($visit, Destination::find($id)->name.' is not dropped for closing before sunset.');
            [$start, $end] = $this->minutes($visit->time_slot);
            $this->assertGreaterThanOrEqual(480, $start);
            $this->assertLessThanOrEqual(1020, $end);
        }
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_a_far_souvenir_shop_never_pushes_out_lunch(): void
    {
        RetailShop::query()->update(['latitude' => 13.03, 'longitude' => 123.88]); // ~40 km away

        $day3 = $this->days($this->generate())[3];

        $this->assertEmpty($day3->where('item_type', 'retail_shop'), 'No 40 km drive for souvenirs.');
        $this->assertNotEmpty($this->startsWith($day3, 'Lunch'));
        $this->assertTrue($day3->every(fn ($i) => (int) $i->travel_time_from_prev <= 60));
    }

    /** The only stay moved ~15 km (about 30 minutes) from everything, which the Day 1 estimate can't know. */
    private function farStay(): void
    {
        Accommodation::whereKey($this->ids['hotel'])->update(['latitude' => 12.535, 'longitude' => 123.880]);
    }

    public function test_a_day_one_stop_that_does_not_fit_moves_to_a_later_day(): void
    {
        $this->farStay();
        $this->reply = ['title' => 'Late start', 'days' => [
            ['day_number' => 1, 'destinations' => $this->picks('ecoPark')],
            ['day_number' => 2, 'destinations' => $this->picks('viewpoint')],
            ['day_number' => 3, 'destinations' => $this->picks('southBeach')],
        ]];

        // Arriving at 3:00 PM, the park (closes 5 PM) can't get a proper visit after the 30-minute drive.
        $itinerary = $this->generate(['arrival_time' => '15:00']);

        $park = $itinerary->items->firstWhere('destination_id', $this->ids['ecoPark']);
        $this->assertNotNull($park, 'The AI picked the park, so it stays in the trip.');
        $this->assertGreaterThan(1, $park->day_number);
        $this->assertStringContainsString('Moved here from Day 1', $park->preference_note);
        $this->assertSame('Picked ecoPark.', $park->reason, "The AI's reason goes with it.");
        foreach (['viewpoint', 'southBeach'] as $key) {
            $this->assertTrue($itinerary->items->contains('destination_id', $this->ids[$key]), "The AI's {$key} isn't pushed out.");
        }
        $ids = $itinerary->items->pluck('destination_id')->filter();
        $this->assertSame($ids->unique()->count(), $ids->count());
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_a_stop_that_fits_no_day_is_left_out_and_logged(): void
    {
        $this->farStay();
        $this->reply = ['title' => 'One evening', 'days' => [['day_number' => 1, 'destinations' => $this->picks('ecoPark')]]];
        Log::spy();

        $itinerary = $this->generate(['arrival_time' => '15:00', 'num_days' => 1, 'end_date' => $this->saturday()->toDateString()]);

        $this->assertEmpty($itinerary->items->where('item_type', 'destination'), 'No time and no later day for the park.');
        Log::shouldHaveReceived('warning')->with('Itinerary destinations that fit no day', ['destinations' => ['Eco Park']])->once();
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_no_stop_is_left_out_at_any_pace_or_arrival_time(): void
    {
        config(['services.itinerary.daily_limit' => 100]);
        Log::spy();

        foreach (['relaxed', 'moderate', 'packed'] as $pace) {
            foreach ([null, '06:00', '08:00', '11:00', '12:00', '13:00', '14:00', '14:30', '15:00', '16:00'] as $arrival) {
                $itinerary = $this->generate(['travel_pace' => $pace] + ($arrival ? ['arrival_time' => $arrival] : []));
                $this->assertSame([], $this->problems($itinerary), "{$pace} pace, arrival {$arrival}.");
            }
        }
        Log::shouldNotHaveReceived('warning', ['Itinerary destinations that fit no day', Mockery::any()]);
    }

    public function test_removing_a_stop_leaves_a_valid_itinerary_with_up_to_date_notes(): void
    {
        $itinerary = $this->generate();
        $removed = $itinerary->items->firstWhere('destination_id', $this->ids['northBeach']);
        $this->assertNotNull($removed);
        $day = $removed->day_number;
        $next = $itinerary->items->where('day_number', $day)->firstWhere('sequence', $removed->sequence + 1);
        $this->assertStringContainsString('North Beach', $next->notes, 'Before: the next stop refers to North Beach.');

        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$itinerary->id}", ['delete_item_ids' => [$removed->id]])->assertOk();

        $itinerary = Itinerary::with('items')->find($itinerary->id);
        $this->assertNull($itinerary->items->firstWhere('id', $removed->id));
        $this->assertFalse($itinerary->items->contains('destination_id', $this->ids['northBeach']));
        $this->assertSame([], $this->problems($itinerary), 'The rest of the trip still follows the planning rules.');
        $next = $itinerary->items->firstWhere('id', $next->id);
        $this->assertStringNotContainsString('North Beach', $next->notes, 'Its generated wording now follows the stop before it.');
        $this->assertStringContainsString("about {$next->travel_time_from_prev} min", $next->notes);
        // The remaining stops keep their order (deleting doesn't renumber them, which is harmless:
        // everything sorts by sequence).
        $starts = $itinerary->items->where('day_number', $day)->sortBy('sequence')
            ->reject(fn ($i) => str_starts_with((string) $i->notes, 'Lunch break'))
            ->map(fn ($i) => $this->minutes($i->time_slot)[0])->values()->all();
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts, 'Stops stay in time order.');
    }

    public function test_the_rule_check_catches_a_misplaced_or_too_tight_lunch_break(): void
    {
        $visit = ['day_number' => 1, 'sequence' => 1, 'item_type' => 'destination', 'destination_id' => $this->ids['viewpoint'], 'time_slot' => '9:00 AM – 1:00 PM', 'notes' => '', 'travel_time_from_prev' => 0];
        $lunch = fn (int $seq, string $slot, int $travel) => ['day_number' => 1, 'sequence' => $seq, 'item_type' => 'food_beverage', 'destination_id' => null, 'time_slot' => $slot, 'notes' => 'Lunch break during your visit.', 'travel_time_from_prev' => $travel];
        $check = fn (array $rows) => app(ItineraryService::class)->scheduleProblems($rows, Destination::all(), []);

        $this->assertSame([], $check([$visit, $lunch(2, '12:00 PM – 12:50 PM', 5)]), 'A proper lunch break.');
        $this->assertNotSame([], $check([$visit, $lunch(2, '12:00 PM – 12:55 PM', 10)]), 'No time to get back.');
        $snack = ['day_number' => 1, 'sequence' => 2, 'item_type' => 'food_beverage', 'destination_id' => null, 'time_slot' => '1:10 PM – 1:40 PM', 'notes' => 'Coffee stop.', 'travel_time_from_prev' => 10];
        $this->assertNotSame([], $check([$visit, $snack, $lunch(3, '12:00 PM – 12:30 PM', 5)]), 'A lunch break after another stop.');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
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
 * The tourist's stay choice before generating, with a faked AI reply over two towns:
 * - North: a hotel with priced rooms (two Standard ₱1,500, one Deluxe ₱2,500, an occupied
 *   Suite, an unpriced Standard) and an inn without any rooms, a beach, a park and restaurants.
 * - South: a resort, a church and restaurants. Plus a pending (unapproved) hotel in North.
 */
class ItineraryStayChoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private int $north;

    private int $south;

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
        $this->north = DB::table('municipalities')->insertGetId(['name' => 'Northtown', 'created_at' => now(), 'updated_at' => now()]);
        $this->south = DB::table('municipalities')->insertGetId(['name' => 'Southtown', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1 => 'Beach', 4 => 'Historical', 6 => 'Park'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->ids = [
            'beach' => $this->destination('North Beach', 1, $this->north, 12.676, 123.880, 'Swimming'),
            'park' => $this->destination('North Park', 6, $this->north, 12.668, 123.878, 'Strolling'),
            'church' => $this->destination('South Church', 4, $this->south, 12.470, 123.880, 'Heritage tour', 'indoor'),
            'hotel' => Accommodation::create($this->listing('North Hotel', $this->north, 12.670, 123.880) + ['type' => 'Hotel', 'price_range' => 'moderate', 'status' => 'approved'])->id,
            'inn' => Accommodation::create($this->listing('North Inn', $this->north, 12.672, 123.884) + ['type' => 'Inn', 'price_range' => 'budget', 'status' => 'approved'])->id,
            'resort' => Accommodation::create($this->listing('South Resort', $this->south, 12.471, 123.881) + ['type' => 'Resort', 'price_range' => 'luxury', 'status' => 'approved'])->id,
            'pending' => Accommodation::create($this->listing('Pending Hotel', $this->north, 12.671, 123.881) + ['type' => 'Hotel', 'price_range' => 'moderate', 'status' => 'pending'])->id,
        ];
        foreach ([['101', 'Standard', 1500, 'unoccupied'], ['102', 'Standard', 1500, 'unoccupied'], ['201', 'Deluxe', 2500, 'unoccupied'], ['301', 'Suite', 5000, 'occupied'], ['103', 'Standard', null, 'unoccupied']] as [$number, $category, $price, $occupancy]) {
            $this->ids["room{$number}"] = AccommodationRoom::create([
                'accommodation_id' => $this->ids['hotel'], 'room_number' => $number, 'category' => $category, 'price' => $price,
                'occupancy_status' => $occupancy, 'status' => 'approved',
            ])->id;
        }
        $this->ids['resortRoom'] = AccommodationRoom::create([
            'accommodation_id' => $this->ids['resort'], 'room_number' => 'R1', 'category' => 'Deluxe', 'price' => 6000, 'occupancy_status' => 'unoccupied', 'status' => 'approved',
        ])->id;
        foreach (range(0, 5) as $n) {
            foreach ([[$this->north, 12.666], [$this->south, 12.466]] as [$town, $lat]) {
                FoodBeverage::create($this->listing("Eatery {$town}-{$n}", $town, $lat + $n * 0.002, 123.879) + [
                    'cuisine_type' => 'Filipino', 'price_range' => 'moderate', 'status' => 'approved', 'operating_hours' => '6:00 AM - 10:00 PM',
                ]);
            }
        }

        $this->reply = ['title' => 'Two towns', 'days' => [
            ['day_number' => 1, 'destinations' => $this->picks('park')],
            ['day_number' => 2, 'destinations' => $this->picks('beach')],
            ['day_number' => 3, 'destinations' => $this->picks('church')],
        ]];
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($this->reply)]]]]],
        ])]);
    }

    private function listing(string $name, int $town, float $lat, float $lng): array
    {
        return ['name' => $name, 'description' => "{$name} is a test listing.", 'municipality_id' => $town, 'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng];
    }

    private function destination(string $name, int $category, int $town, float $lat, float $lng, string $activity, string $type = 'outdoor'): int
    {
        $id = Destination::create($this->listing($name, $town, $lat, $lng) + [
            'category_id' => $category, 'operating_hours' => '6:00 AM - 6:00 PM', 'entrance_fee' => 20, 'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        DB::table('activities')->insert(['destination_id' => $id, 'name' => $activity, 'type' => $type, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function picks(string ...$keys): array
    {
        return array_map(fn ($key) => ['destination_id' => $this->ids[$key], 'reason' => "Picked {$key}."], $keys);
    }

    private function request(array $overrides = []): array
    {
        $start = now()->addDays(3);

        return $overrides + [
            'municipality_ids' => [$this->north, $this->south],
            'num_days' => 3,
            'interests' => ['nature', 'culture', 'relaxation'],
            'travel_pace' => 'moderate',
            'budget' => 'moderate',
            'travelers' => 2,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(2)->toDateString(),
        ];
    }

    private function send(array $overrides = [])
    {
        return $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $this->request($overrides));
    }

    private function generate(array $overrides = []): Itinerary
    {
        $this->send($overrides)->assertCreated();

        return Itinerary::with(['items', 'stays', 'rooms'])->latest('id')->firstOrFail();
    }

    private function problems(Itinerary $itinerary): array
    {
        $rows = $itinerary->items->map(fn ($i) => $i->only(['day_number', 'sequence', 'item_type', 'destination_id', 'time_slot', 'notes', 'travel_time_from_prev']))->all();

        return app(ItineraryService::class)->scheduleProblems($rows, Destination::all(), ['start_date' => $itinerary->start_date]);
    }

    private function stayIn(Itinerary $itinerary, int $town)
    {
        return $itinerary->stays->firstWhere('municipality_id', $town);
    }

    public function test_the_chosen_stay_is_the_stay_for_its_town_and_other_towns_keep_theirs(): void
    {
        $itinerary = $this->generate(['accommodation_needed' => true, 'accommodation_id' => $this->ids['inn']]);

        $north = $this->stayIn($itinerary, $this->north);
        $this->assertSame($this->ids['inn'], $north->accommodation_id, 'The tourist picked the inn over the closer hotel.');
        $this->assertStringStartsWith('The stay you chose in Northtown', $north->reason);
        $this->assertSame([1, 2], $north->night_days);
        // The inn lists no rooms: its cost is the usual band for its price level, as before.
        $this->assertGreaterThan(0, (float) $north->cost_max);
        $this->assertTrue($itinerary->rooms->isEmpty());
        // Southtown is only the last day: its stay is still suggested, with no nights booked.
        $this->assertSame($this->ids['resort'], $this->stayIn($itinerary, $this->south)->accommodation_id);
        $this->assertSame([], $this->stayIn($itinerary, $this->south)->night_days);
        // Check-in on Day 1 and check-out on the last morning are at the chosen stay.
        $this->assertSame($this->ids['inn'], $itinerary->items->where('day_number', 1)->sortBy('sequence')->first()->accommodation_id);
        $this->assertTrue($itinerary->items->contains(fn ($i) => $i->day_number === 3 && $i->accommodation_id === $this->ids['inn'] && str_starts_with((string) $i->notes, 'Check out')));
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_the_chosen_room_is_booked_and_costed_for_every_night(): void
    {
        $itinerary = $this->generate(['accommodation_id' => $this->ids['hotel'], 'room_id' => $this->ids['room102']]);

        $this->assertSame([$this->ids['room102']], $itinerary->rooms->pluck('id')->all());
        $north = $this->stayIn($itinerary, $this->north);
        $this->assertEquals(1500 * 2, (float) $north->cost_max, 'One Standard room × 2 nights.');
        $this->assertEquals(1500 * 2, (float) $itinerary->accommodation_cost_max, 'Counted once.');
        $this->assertEquals(
            (float) $itinerary->accommodation_cost_max + $itinerary->items->sum(fn ($i) => (float) $i->cost_max),
            (float) $itinerary->estimated_cost_max
        );

        // Four travelers: both Standard rooms (2 + 2 beds).
        $four = $this->generate(['accommodation_id' => $this->ids['hotel'], 'room_id' => $this->ids['room101'], 'travelers' => 4]);
        $this->assertEqualsCanonicalizing([$this->ids['room101'], $this->ids['room102']], $four->rooms->pluck('id')->all());
        $this->assertEquals(1500 * 2 * 2, (float) $four->accommodation_cost_max);
    }

    public function test_room_type_capacity_is_enforced(): void
    {
        // Two bookable Standard rooms sleep 4: not 5.
        $this->send(['accommodation_id' => $this->ids['hotel'], 'room_id' => $this->ids['room101'], 'travelers' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('room_id')
            ->assertJsonFragment(['room_id' => ["North Hotel's Standard rooms sleep at most 4 of your 5 travelers. Choose a bigger room type or fewer travelers."]]);
        $this->send(['accommodation_id' => $this->ids['hotel'], 'room_id' => $this->ids['room201'], 'travelers' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->send(['accommodation_id' => $this->ids['hotel'], 'room_id' => $this->ids['room201'], 'travelers' => 2])->assertCreated();
    }

    public function test_invalid_stays_and_rooms_are_rejected(): void
    {
        $this->send(['accommodation_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('accommodation_id');
        $this->send(['accommodation_id' => $this->ids['pending']])->assertUnprocessable()->assertJsonValidationErrors('accommodation_id');
        // A stay outside the selected towns.
        $this->send(['municipality_ids' => [$this->north], 'num_days' => 2, 'accommodation_id' => $this->ids['resort']])
            ->assertUnprocessable()->assertJsonValidationErrors('accommodation_id');
        // Another stay's room, an occupied room, an unpriced room, a room without a stay.
        $this->send(['accommodation_id' => $this->ids['hotel'], 'room_id' => $this->ids['resortRoom']])->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->send(['accommodation_id' => $this->ids['hotel'], 'room_id' => $this->ids['room301']])->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->send(['accommodation_id' => $this->ids['hotel'], 'room_id' => $this->ids['room103']])->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->send(['room_id' => $this->ids['room101']])->assertUnprocessable()->assertJsonValidationErrors('room_id');
        $this->send(['accommodation_needed' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('accommodation_needed');
        $this->assertSame(0, Itinerary::count());
    }

    public function test_no_accommodation_means_no_stay_no_rooms_and_no_room_costs(): void
    {
        // A stay picked earlier is ignored once the tourist says they don't need one.
        $itinerary = $this->generate(['accommodation_needed' => false, 'accommodation_id' => $this->ids['hotel'], 'room_id' => $this->ids['room101'], 'arrival_time' => '09:00']);

        $this->assertTrue($itinerary->stays->isEmpty());
        $this->assertTrue($itinerary->rooms->isEmpty());
        $this->assertNull($itinerary->accommodation_id);
        $this->assertEquals(0, (float) $itinerary->accommodation_cost_max);
        $this->assertFalse($itinerary->items->contains('item_type', 'accommodation'), 'No check-in, rest, check-out or meal at a stay.');
        $this->assertEquals($itinerary->items->sum(fn ($i) => (float) $i->cost_max), (float) $itinerary->estimated_cost_max);
        $this->assertStringContainsString("You said you don't need accommodation", $itinerary->accommodation_note);

        // Still a practical trip: every day starts at its first stop, with meals around it.
        foreach ([1, 2, 3] as $day) {
            $stops = $itinerary->items->where('day_number', $day)->sortBy('sequence')->values();
            $this->assertTrue($stops->contains('item_type', 'destination'), "Day {$day} has its visit.");
            $this->assertTrue($stops->contains(fn ($i) => str_starts_with((string) $i->notes, 'Lunch')), "Day {$day} has lunch.");
            $this->assertTrue($stops->contains(fn ($i) => str_starts_with((string) $i->notes, 'Dinner')), "Day {$day} has dinner.");
        }
        $this->assertTrue($itinerary->items->contains(fn ($i) => $i->day_number === 2 && str_starts_with((string) $i->notes, 'Breakfast near North Beach')));
        $this->assertStringContainsString('You arrive around 9:00 AM', $itinerary->items->where('day_number', 1)->sortBy('sequence')->first()->preference_note);
        $this->assertSame([], $this->problems($itinerary));
    }

    public function test_no_accommodation_on_a_day_without_stops_still_plans_meals_in_town(): void
    {
        $this->reply['days'] = [
            ['day_number' => 1, 'destinations' => $this->picks('park')],
            ['day_number' => 2, 'destinations' => []],
            ['day_number' => 3, 'destinations' => $this->picks('church')],
        ];
        // Only the park and beach exist in Northtown for these interests; keep Day 2 empty.
        Destination::whereKey($this->ids['beach'])->update(['status' => 'draft']);
        $itinerary = $this->generate(['accommodation_needed' => false]);

        // The planner may reorder days to cover both towns; whichever day has no stop is planned
        // around its town's center.
        $free = $itinerary->items->groupBy('day_number')->first(fn ($day) => ! $day->contains('item_type', 'destination'));
        $this->assertNotNull($free, 'One day has no stop.');
        $this->assertTrue($free->contains(fn ($i) => preg_match('/^Breakfast near the center of (Northtown|Southtown)\./', (string) $i->notes)));
        $this->assertTrue($free->contains(fn ($i) => str_starts_with((string) $i->notes, 'Lunch')));
        $this->assertTrue($free->contains(fn ($i) => str_starts_with((string) $i->notes, 'Dinner')));
        $this->assertFalse($itinerary->items->contains('item_type', 'accommodation'));
    }

    public function test_leaving_the_choice_out_keeps_the_automatic_stays(): void
    {
        $itinerary = $this->generate();

        $this->assertSame($this->ids['hotel'], $this->stayIn($itinerary, $this->north)->accommodation_id, 'The nearest stay, as before.');
        $this->assertNotEmpty($itinerary->rooms);
        $this->assertGreaterThan(0, (float) $itinerary->accommodation_cost_max);
        // "Yes" without a pick is the same.
        $yes = $this->generate(['accommodation_needed' => true]);
        $this->assertSame($this->ids['hotel'], $this->stayIn($yes, $this->north)->accommodation_id);
    }

    public function test_an_itinerary_without_accommodation_can_still_be_edited(): void
    {
        $itinerary = $this->generate(['accommodation_needed' => false]);
        $dinner = $itinerary->items->first(fn ($i) => $i->day_number === 1 && str_starts_with((string) $i->notes, 'Dinner'));

        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$itinerary->id}", ['delete_item_ids' => [$dinner->id]])->assertOk();

        $fresh = Itinerary::with(['stays', 'rooms', 'items'])->find($itinerary->id);
        $this->assertTrue($fresh->stays->isEmpty(), 'No stay is invented for it.');
        $this->assertEquals(0, (float) $fresh->accommodation_cost_max);
        $this->assertEquals($fresh->items->sum(fn ($i) => (float) $i->cost_max), (float) $fresh->estimated_cost_max);
        $this->actingAs($this->tourist)->getJson("/api/v1/itineraries/{$itinerary->id}")->assertOk()->assertJsonPath('stays', []);
        $this->actingAs($this->tourist)->getJson("/api/v1/itineraries/{$itinerary->id}/eligible-listings")->assertOk();
    }

    public function test_guests_can_choose_too(): void
    {
        $this->postJson('/api/v1/itineraries/guest-generate', $this->request(['accommodation_needed' => false]))
            ->assertCreated()->assertJsonPath('preview.stays', []);
        $this->postJson('/api/v1/itineraries/guest-generate', $this->request(['accommodation_id' => $this->ids['hotel'], 'room_id' => $this->ids['room101']]))
            ->assertCreated();
        $this->postJson('/api/v1/itineraries/guest-generate', $this->request(['accommodation_id' => $this->ids['hotel'], 'room_id' => $this->ids['resortRoom']]))
            ->assertUnprocessable()->assertJsonValidationErrors('room_id');
    }

    public function test_the_stay_picker_lists_rooms_without_counting_a_view(): void
    {
        $views = Accommodation::find($this->ids['hotel'])->view_count;
        $list = $this->getJson("/api/v1/accommodations?municipality_id={$this->north}&with_rooms=1&per_page=50")->assertOk()->json('data');

        $hotel = collect($list)->firstWhere('id', $this->ids['hotel']);
        $this->assertCount(5, $hotel['rooms'], 'Approved rooms only, all of them listed with their status.');
        $this->assertNotContains($this->ids['pending'], collect($list)->pluck('id')->all());
        $this->assertSame($views, Accommodation::find($this->ids['hotel'])->view_count);
        // Without the flag the list is unchanged.
        $this->assertArrayNotHasKey('rooms', $this->getJson("/api/v1/accommodations?municipality_id={$this->north}")->json('data.0'));
    }
}

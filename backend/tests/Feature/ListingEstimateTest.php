<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\ListingEstimate;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use App\Services\ItineraryService;
use Database\Seeders\ListingEstimateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Estimates for missing listing details: kept in listing_estimates (never in the listings'
 * own columns), shown as approximate, and used by the planner only where a real value is missing.
 */
class ListingEstimateTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private int $town;

    private array $ids = [];

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
        $this->tourist = User::create(['role_id' => $roleId, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password')]);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Testville', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1 => 'Beach', 4 => 'Historical', 5 => 'Church'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->ids = [
            // No hours, no fee: both get estimates.
            'beach' => $this->destination('Long Beach', 1, 12.6712345678, 123.8801234567, null, null),
            // Has its own hours and fee: no estimates.
            'listedBeach' => $this->destination('Listed Beach', 1, 12.6800001, 123.8800001, '7:00 AM - 5:00 PM', 35),
            'museum' => $this->destination('Town Museum', 4, 12.6720000, 123.8810000, null, null),
            'monument' => $this->destination('Rizal Monument', 4, 12.6730000, 123.8820000, null, null),
            'church' => $this->destination('St. Peter Church', 5, 12.6740000, 123.8830000, null, null),
            'hotel' => Accommodation::create($this->listing('Plain Inn', 12.6705, 123.8805) + ['type' => 'Inn', 'price_range' => 'budget', 'status' => 'approved'])->id,
            'listedHotel' => Accommodation::create($this->listing('Listed Hotel', 12.6709, 123.8809) + ['type' => 'Hotel', 'price_range' => 'moderate', 'status' => 'approved', 'operating_hours' => 'Check-in 3:00 PM / Check-out 11:00 AM'])->id,
            'eatery' => FoodBeverage::create(array_merge($this->listing('Casa Test', 12.6710, 123.8810), ['description' => 'Serving local food and catering.', 'price_range' => 'moderate', 'status' => 'approved']))->id,
            'shop' => RetailShop::create($this->listing('Pasalubong Test', 12.6711, 123.8811) + ['status' => 'approved'])->id,
        ];
        AccommodationRoom::create(['accommodation_id' => $this->ids['listedHotel'], 'room_number' => '1', 'category' => 'Standard', 'price' => 2000, 'status' => 'approved']);

        $reply = ['title' => 'Testville', 'days' => [['day_number' => 1, 'destinations' => [['destination_id' => $this->ids['beach'], 'reason' => 'Sea.']]]]];
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($reply)]]]]]])]);
    }

    private function listing(string $name, float $lat, float $lng): array
    {
        return ['name' => $name, 'description' => "{$name} test listing.", 'municipality_id' => $this->town, 'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng];
    }

    private function destination(string $name, int $category, float $lat, float $lng, ?string $hours, ?float $fee): int
    {
        return Destination::create($this->listing($name, $lat, $lng) + [
            'category_id' => $category, 'operating_hours' => $hours, 'entrance_fee' => $fee, 'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
    }

    private function estimates(string $class, int $id): array
    {
        return ListingEstimate::where(['estimable_type' => (new $class)->getMorphClass(), 'estimable_id' => $id])->pluck('value', 'field')->all();
    }

    /** Every row of the listing tables, exactly as stored. */
    private function snapshot(): array
    {
        return collect(['destinations', 'accommodations', 'food_beverages', 'retail_shops', 'accommodation_rooms'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->all();
    }

    public function test_the_seeder_only_adds_estimates_never_changes_listings_and_is_repeatable(): void
    {
        $before = $this->snapshot();

        $this->seed(ListingEstimateSeeder::class);
        $count = ListingEstimate::count();
        $this->seed(ListingEstimateSeeder::class);

        $this->assertSame($before, $this->snapshot(), 'No listing column, coordinate or room changed.');
        $this->assertSame($count, ListingEstimate::count(), 'Running it again adds nothing.');
        $this->assertSame(0, ListingEstimate::select('estimable_type', 'estimable_id', 'field')->groupBy('estimable_type', 'estimable_id', 'field')->havingRaw('count(*) > 1')->count());
    }

    public function test_estimates_fill_only_what_is_missing_with_category_based_values(): void
    {
        $this->seed(ListingEstimateSeeder::class);

        $this->assertEquals(['operating_hours' => '6:00 AM - 6:00 PM', 'entrance_fee' => '20-50'], $this->estimates(Destination::class, $this->ids['beach']));
        $this->assertEquals([], $this->estimates(Destination::class, $this->ids['listedBeach']), 'Its own hours and fee stand.');
        $this->assertEquals(['operating_hours' => '8:00 AM - 5:00 PM', 'entrance_fee' => '20-50'], $this->estimates(Destination::class, $this->ids['museum']));
        $this->assertEquals(['operating_hours' => '6:00 AM - 6:00 PM', 'entrance_fee' => '0'], $this->estimates(Destination::class, $this->ids['monument']), 'Open-air heritage: daylight hours, free.');
        $this->assertSame('0', $this->estimates(Destination::class, $this->ids['church'])['entrance_fee']);

        $this->assertEquals(['check_in' => '2:00 PM', 'check_out' => '12:00 PM', 'nightly_rate' => '800-1500'], $this->estimates(Accommodation::class, $this->ids['hotel']));
        $this->assertEquals([], $this->estimates(Accommodation::class, $this->ids['listedHotel']), 'Listed check-in and real room prices stand.');

        $this->assertEquals(['operating_hours' => '9:00 AM - 9:00 PM', 'cuisine_type' => 'Filipino', 'meal_price' => '250-500'], $this->estimates(FoodBeverage::class, $this->ids['eatery']));
        $this->assertEquals(['item_price' => '200-800'], $this->estimates(RetailShop::class, $this->ids['shop']));
        $this->assertSame(0, ListingEstimate::where('field', 'contact_number')->count(), 'No contact numbers are ever made up.');
    }

    public function test_a_real_value_entered_later_replaces_the_estimate(): void
    {
        $this->seed(ListingEstimateSeeder::class);
        Destination::whereKey($this->ids['beach'])->update(['operating_hours' => '5:00 AM - 7:00 PM', 'entrance_fee' => 25]);

        $this->seed(ListingEstimateSeeder::class);

        $this->assertEquals([], $this->estimates(Destination::class, $this->ids['beach']));
    }

    public function test_public_pages_get_the_estimates_but_not_how_they_were_made(): void
    {
        $this->seed(ListingEstimateSeeder::class);

        $estimates = $this->getJson("/api/v1/destinations/{$this->ids['beach']}")->assertOk()->json('destination.estimates');
        $this->assertEqualsCanonicalizing([['field' => 'operating_hours', 'value' => '6:00 AM - 6:00 PM'], ['field' => 'entrance_fee', 'value' => '20-50']],
            collect($estimates)->map(fn ($e) => ['field' => $e['field'], 'value' => $e['value']])->all());
        $this->assertArrayNotHasKey('basis', $estimates[0], 'The internal basis is never exposed.');
        $this->assertNull($this->getJson("/api/v1/destinations/{$this->ids['beach']}")->json('destination.operating_hours'), 'The real field stays empty.');

        $this->assertNotEmpty($this->getJson("/api/v1/accommodations/{$this->ids['hotel']}")->assertOk()->json('estimates'));
        $this->assertNotEmpty($this->getJson("/api/v1/food-beverages/{$this->ids['eatery']}")->assertOk()->json('estimates'));
        $this->assertNotEmpty($this->getJson("/api/v1/retail-shops/{$this->ids['shop']}")->assertOk()->json('estimates'));
    }

    public function test_the_planner_uses_estimates_where_real_values_are_missing_and_says_so(): void
    {
        $this->seed(ListingEstimateSeeder::class);
        Accommodation::whereKey($this->ids['listedHotel'])->update(['status' => 'draft']);
        $start = now()->addDays(3);

        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', [
            'municipality_id' => $this->town, 'num_days' => 1, 'interests' => ['nature'], 'travel_pace' => 'moderate', 'budget' => 'budget',
            'travelers' => 2, 'start_date' => $start->toDateString(), 'end_date' => $start->toDateString(),
        ])->assertCreated();
        $items = Itinerary::latest('id')->firstOrFail()->items;

        $visit = $items->firstWhere('destination_id', $this->ids['beach']);
        $this->assertSame('Est. entrance fee × 2', $visit->cost_note);
        $this->assertEquals([40, 100], [(float) $visit->cost_min, (float) $visit->cost_max]);
        $this->assertStringContainsString('Hours shown are typical, not confirmed', $visit->preference_note);
        $this->assertLessThanOrEqual(18 * 60, (int) explode(':', date('G:i', strtotime(trim(explode('–', $visit->time_slot)[1]))))[0] * 60, 'Within the estimated 6 PM closing.');

        // The inn lists no check-in: Day 1 waits for its usual one, and says that's typical.
        $arrival = $items->sortBy('sequence')->first();
        $this->assertStringStartsWith('Arrive at Plain Inn.', $arrival->notes);
        $this->assertStringContainsString('Check-in is usually from 2:00 PM (typical, not listed)', $arrival->preference_note);
    }

    public function test_without_estimates_the_planner_behaves_as_before(): void
    {
        $start = now()->addDays(3);
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', [
            'municipality_id' => $this->town, 'num_days' => 1, 'interests' => ['nature'], 'travel_pace' => 'moderate', 'budget' => 'budget',
            'start_date' => $start->toDateString(), 'end_date' => $start->toDateString(),
        ])->assertCreated();

        $visit = Itinerary::latest('id')->firstOrFail()->items->firstWhere('destination_id', $this->ids['beach']);
        $this->assertSame('Entrance fee not listed', $visit->cost_note);
        $this->assertStringContainsString("Hours aren't listed", $visit->preference_note);
    }

    /** A 1-day trip for 2 visiting the beach, with estimates seeded; returns [itinerary, the beach visit]. */
    private function tripWithEstimates(): array
    {
        $this->seed(ListingEstimateSeeder::class);
        $start = now()->addDays(3);
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', [
            'municipality_id' => $this->town, 'num_days' => 1, 'interests' => ['nature'], 'travel_pace' => 'moderate', 'budget' => 'budget',
            'travelers' => 2, 'start_date' => $start->toDateString(), 'end_date' => $start->toDateString(),
        ])->assertCreated();
        $itinerary = Itinerary::latest('id')->firstOrFail();

        return [$itinerary, $itinerary->items->firstWhere('destination_id', $this->ids['beach'])];
    }

    public function test_a_swapped_in_destination_uses_its_estimated_fee_and_a_real_fee_list_still_wins(): void
    {
        [$itinerary, $visit] = $this->tripWithEstimates();
        $url = "/api/v1/itineraries/{$itinerary->id}";

        // The museum has no fee and no fee list: its estimate (₱20–50) applies, not "not listed".
        $this->actingAs($this->tourist)->putJson($url, ['edit_items' => [['id' => $visit->id, 'listing_id' => $this->ids['museum']]]])->assertOk();
        $visit->refresh();
        $this->assertSame('Est. entrance fee × 2', $visit->cost_note);
        $this->assertEquals([40, 100], [(float) $visit->cost_min, (float) $visit->cost_max]);

        // The church has an estimate too (free), but once it lists a fee, the list is used.
        DB::table('destination_fees')->insert(['destination_id' => $this->ids['church'], 'label' => 'Adult', 'amount' => 30, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->tourist)->putJson($url, ['edit_items' => [['id' => $visit->id, 'listing_id' => $this->ids['church']]]])->assertOk();
        $visit->refresh();
        $this->assertSame('Entrance fee (Adult) × 2', $visit->cost_note);
        $this->assertEquals(60, (float) $visit->cost_max);
    }

    public function test_an_edited_visit_stays_within_estimated_opening_hours(): void
    {
        [$itinerary, $visit] = $this->tripWithEstimates();
        $url = "/api/v1/itineraries/{$itinerary->id}";

        // After dinner, past the museum's typical 5 PM closing: refused, saying the hours are typical.
        $this->actingAs($this->tourist)->putJson($url, ['edit_items' => [['id' => $visit->id, 'listing_id' => $this->ids['museum'], 'time_slot' => '7:45 PM – 9:00 PM']]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.edit_items.0', 'Town Museum is open 8:00 AM – 5:00 PM (typical hours, not confirmed by the site). Pick a time within those hours.');
        $this->assertSame($this->ids['beach'], $visit->refresh()->destination_id, 'Nothing was saved.');

        // In its existing (daytime) slot it's accepted, and its activities stay inside the slot and the hours.
        $this->actingAs($this->tourist)->putJson($url, ['edit_items' => [['id' => $visit->id, 'listing_id' => $this->ids['museum']]]])->assertOk();
        $service = app(ItineraryService::class);
        [$from, $to] = $service->slotMinutes($visit->refresh()->time_slot);
        $this->assertGreaterThanOrEqual(480, $from);
        $this->assertLessThanOrEqual(1020, $to);
        foreach ($visit->activities as $activity) {
            [$a, $b] = $service->slotMinutes($activity['time']);
            $this->assertTrue($a >= $from && $b <= $to, "{$activity['name']} runs outside the visit.");
        }
    }
}

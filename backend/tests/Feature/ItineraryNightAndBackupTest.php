<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Places best visited at night, and each day's Plan B for rain or delays, with a faked AI reply
 * in one town: a boardwalk open 24 hours whose activity is an evening stroll (plus a morning
 * swim), a beach, a museum the tourist's interests don't include, a hotel and restaurants.
 */
class ItineraryNightAndBackupTest extends TestCase
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
        $this->tourist = User::create([
            'role_id' => $roleId, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password'),
        ]);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Bayview', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1 => 'Beach', 4 => 'Historical', 6 => 'Park'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->ids['boardwalk'] = $this->destination('Bay Boardwalk', 1, 12.676, 123.880, 'Open 24 hours', [
            ['Swimming', 'outdoor', 'Morning'],
            ['Evening stroll', 'outdoor', 'Evening'],
        ]);
        $this->ids['beach'] = $this->destination('Long Beach', 1, 12.668, 123.878, '6:00 AM - 6:00 PM', [['Sunbathing', 'outdoor', null]]);
        $this->ids['park'] = $this->destination('Town Park', 6, 12.670, 123.883, '6:00 AM - 6:00 PM', [['Strolling', 'outdoor', null]]);
        $this->ids['museum'] = $this->destination('Bay Museum', 4, 12.671, 123.881, '8:00 AM - 5:00 PM', [['Gallery tour', 'indoor', null]]);
        $this->ids['hotel'] = Accommodation::create($this->listing('Bay Hotel', 12.669, 123.879) + ['type' => 'Hotel', 'price_range' => 'moderate', 'status' => 'approved'])->id;
        foreach (range(0, 5) as $n) {
            FoodBeverage::create($this->listing("Eatery {$n}", 12.666 + $n * 0.002, 123.879) + [
                'cuisine_type' => 'Filipino', 'price_range' => 'moderate', 'status' => 'approved', 'operating_hours' => '6:00 AM - 10:00 PM',
            ]);
        }

        $reply = ['title' => 'Bayview', 'days' => [
            ['day_number' => 1, 'destinations' => [['destination_id' => $this->ids['park'], 'reason' => 'Park.']]],
            ['day_number' => 2, 'destinations' => [
                ['destination_id' => $this->ids['beach'], 'reason' => 'Beach.'],
                ['destination_id' => $this->ids['boardwalk'], 'reason' => 'Lively at night.'],
            ]],
            ['day_number' => 3, 'destinations' => []],
        ]];
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($reply)]]]]],
        ])]);
    }

    private function listing(string $name, float $lat, float $lng): array
    {
        return ['name' => $name, 'description' => "{$name} is a test listing.", 'municipality_id' => $this->town, 'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng];
    }

    private function destination(string $name, int $category, float $lat, float $lng, string $hours, array $activities): int
    {
        $id = Destination::create($this->listing($name, $lat, $lng) + [
            'category_id' => $category, 'operating_hours' => $hours, 'entrance_fee' => 20, 'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        foreach ($activities as [$activity, $type, $best]) {
            DB::table('activities')->insert(['destination_id' => $id, 'name' => $activity, 'type' => $type, 'best_time_to_visit' => $best, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    private function generate(): Itinerary
    {
        $start = now()->addDays(3);
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', [
            'municipality_ids' => [$this->town],
            'num_days' => 3,
            'interests' => ['relaxation'],
            'travel_pace' => 'moderate',
            'budget' => 'moderate',
            'travelers' => 2,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(2)->toDateString(),
            'accommodation_needed' => true,
            'accommodation_id' => $this->ids['hotel'],
        ])->assertCreated();

        return Itinerary::with('items')->latest('id')->firstOrFail();
    }

    private function minutes(string $range): array
    {
        return array_map(function ($part) {
            $t = Carbon::createFromFormat('g:i A', trim($part));

            return $t->hour * 60 + $t->minute;
        }, explode('–', $range));
    }

    public function test_a_place_best_at_night_is_visited_in_the_evening_without_daytime_activities(): void
    {
        $itinerary = $this->generate();
        $visit = $itinerary->items->firstWhere('destination_id', $this->ids['boardwalk']);
        $this->assertNotNull($visit);

        $names = collect($visit->activities)->pluck('name')->all();
        $this->assertContains('Evening stroll', $names);
        $this->assertNotContains('Swimming', $names, 'A morning swim is not put in an evening visit.');
        $stroll = collect($visit->activities)->firstWhere('name', 'Evening stroll');
        $this->assertGreaterThanOrEqual(1080, $this->minutes($stroll['time'])[0], 'The evening stroll starts at 6 PM or later.');
        $lastVisit = $itinerary->items->where('day_number', $visit->day_number)->where('item_type', 'destination')->sortBy('sequence')->last();
        $this->assertSame($visit->id, $lastVisit->id, 'It is the last place visited that day.');
    }

    public function test_each_day_offers_an_indoor_plan_b_and_a_stop_to_skip(): void
    {
        $itinerary = $this->generate();
        $plans = $this->actingAs($this->tourist)->getJson("/api/v1/itineraries/{$itinerary->id}")->assertOk()->json('backup_options');

        $this->assertNotEmpty($plans);
        $inTrip = $itinerary->items->pluck('destination_id')->filter()->all();
        foreach ($plans as $day => $plan) {
            foreach ($plan['places'] as $place) {
                $this->assertNotContains($place['id'], $place['type'] === 'destination' ? $inTrip : [], "Day {$day}: a backup place is not already in the trip.");
            }
        }
        $day2 = $plans['2'];
        $this->assertSame('Bay Museum', $day2['places'][0]['name'], 'The nearby indoor museum is the first Plan B.');
        $this->assertStringContainsString('easiest stop to shorten or skip', (string) $day2['flexible']);
    }

    public function test_a_shared_itinerary_shows_the_plan_b_too(): void
    {
        $itinerary = $this->generate();
        $itinerary->forceFill(['share_token' => str_repeat('a', 48)])->save();

        $this->getJson('/api/v1/shared-itineraries/'.str_repeat('a', 48))->assertOk()->assertJsonPath('backup_options.2.places.0.name', 'Bay Museum');
    }
}

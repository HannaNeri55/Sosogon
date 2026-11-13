<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The last day as it really goes: breakfast at the stay, then check out (by the listed
 * check-out time when the day is free), and no meal back at the stay after checking out.
 * One town with no restaurants at all, so every meal would otherwise fall back to the stay.
 */
class ItineraryCheckOutTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private int $town;

    private array $ids = [];

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
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Quiettown', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1 => 'Beach', 6 => 'Park'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->ids['park'] = $this->destination('Town Park', 6, 12.670, 123.883);
        $this->ids['beach'] = $this->destination('Long Beach', 1, 12.668, 123.878);
        $this->ids['inn'] = Accommodation::create([
            'name' => 'Quiet Inn', 'description' => 'A test inn.', 'municipality_id' => $this->town, 'address' => 'Test St.',
            'latitude' => 12.669, 'longitude' => 123.879, 'type' => 'Inn', 'price_range' => 'moderate', 'status' => 'approved',
            'check_in_time' => '2:00 PM', 'check_out_time' => '12:00 noon',
        ])->id;

        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode($this->reply)]]]]],
        ])]);
    }

    private function destination(string $name, int $category, float $lat, float $lng): int
    {
        $id = Destination::create([
            'name' => $name, 'description' => "{$name} is a test listing.", 'municipality_id' => $this->town, 'address' => 'Test St.',
            'latitude' => $lat, 'longitude' => $lng, 'category_id' => $category, 'operating_hours' => '6:00 AM - 6:00 PM',
            'entrance_fee' => 20, 'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        DB::table('activities')->insert(['destination_id' => $id, 'name' => "Strolling at {$name}", 'type' => 'outdoor', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function generate(array $lastDay): Itinerary
    {
        $this->reply = ['title' => 'Quiettown', 'days' => [
            ['day_number' => 1, 'destinations' => [['destination_id' => $this->ids['park'], 'reason' => 'Park.']]],
            ['day_number' => 2, 'destinations' => $lastDay],
        ]];
        $start = now()->addDays(3);
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', [
            'municipality_ids' => [$this->town],
            'num_days' => 2,
            'interests' => ['relaxation'],
            'travel_pace' => 'moderate',
            'budget' => 'moderate',
            'travelers' => 2,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
            'accommodation_needed' => true,
            'accommodation_id' => $this->ids['inn'],
        ])->assertCreated();

        return Itinerary::with('items')->latest('id')->firstOrFail();
    }

    private function startOf(string $slot): int
    {
        $t = Carbon::createFromFormat('g:i A', trim(explode('–', $slot)[0]));

        return $t->hour * 60 + $t->minute;
    }

    private function lastDay(Itinerary $itinerary)
    {
        return $itinerary->items->where('day_number', 2)->sortBy('sequence')->values();
    }

    public function test_breakfast_at_the_stay_comes_before_checking_out(): void
    {
        $day = $this->lastDay($this->generate([['destination_id' => $this->ids['beach'], 'reason' => 'Beach.']]));

        $breakfast = $day->search(fn ($i) => str_starts_with((string) $i->notes, 'Breakfast at Quiet Inn'));
        $checkout = $day->search(fn ($i) => str_starts_with((string) $i->notes, 'Check out of Quiet Inn'));
        $this->assertNotFalse($breakfast);
        $this->assertNotFalse($checkout);
        $this->assertLessThan($checkout, $breakfast, 'Breakfast at the inn, then check out.');
        $this->assertStringContainsString('Check-out is by 12:00 PM.', $day[$checkout]->reason);
    }

    public function test_no_meal_back_at_the_stay_after_checking_out(): void
    {
        $day = $this->lastDay($this->generate([['destination_id' => $this->ids['beach'], 'reason' => 'Beach.']]));
        $checkout = $day->search(fn ($i) => str_starts_with((string) $i->notes, 'Check out of'));

        foreach ($day->slice($checkout + 1) as $item) {
            $this->assertFalse((bool) preg_match('/^(Lunch|Dinner|Afternoon merienda) at Quiet Inn/', (string) $item->notes), "After check-out: {$item->notes}");
        }
        $this->assertTrue($day->contains(fn ($i) => str_contains((string) $i->preference_note, 'bring a packed lunch')
            || str_contains((string) $i->preference_note, 'eat on the way')), 'Says where to eat instead.');
    }

    public function test_a_free_last_day_checks_out_at_the_listed_time(): void
    {
        // Only the park is left to visit, on Day 1, so Day 2 has no stops.
        Destination::whereKey($this->ids['beach'])->update(['status' => 'draft']);
        $day = $this->lastDay($this->generate([]));

        $free = $day->first(fn ($i) => str_starts_with((string) $i->notes, 'Free time at Quiet Inn'));
        $this->assertNotNull($free, 'A free morning at the inn before check-out.');
        $checkout = $day->first(fn ($i) => str_starts_with((string) $i->notes, 'Check out of Quiet Inn'));
        $this->assertSame(660, $this->startOf($checkout->time_slot), 'Check out at 11:00 AM, before the 12:00 noon check-out and in time for lunch at 11:30.');
        $breakfast = $day->first(fn ($i) => str_starts_with((string) $i->notes, 'Breakfast'));
        $this->assertLessThan($checkout->sequence, $breakfast->sequence);
    }
}

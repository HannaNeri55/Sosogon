<?php

namespace Tests\Feature;

use App\Contracts\ItineraryAI;
use App\Models\Accommodation;
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
 * Among stays of the type the tourist asked for, the planner weighs closeness to the day's
 * places, the tourist's budget and genuine guest ratings (at least five, no demo or hidden
 * reviews). Baytown's places are at 12.670, 123.880; its stays are all inns, placed north of
 * them at the distance each test needs (0.01° of latitude is about 1.1 km).
 */
class ItineraryStayRankingTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private int $town;

    private array $ids = [];

    private int $raters = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_maps.api_key' => '', 'services.itinerary.daily_limit' => 1000]);
        Http::preventStrayRequests();
        $ai = new class implements ItineraryAI
        {
            public array $reply = [];

            public function generate(string $prompt): string
            {
                return json_encode(['title' => 'Bay trip', 'days' => $this->reply]);
            }
        };
        $this->app->instance(ItineraryAI::class, $ai);
        $this->app->forgetInstance(ItineraryService::class);

        $role = Role::create(['name' => 'tourist', 'description' => 'Tourist']);
        $this->tourist = User::create(['role_id' => $role->id, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password')]);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Baytown', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('categories')->insert(['id' => 6, 'name' => 'Park', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $park = Destination::create([
            'name' => 'Bay Park', 'description' => 'Bay Park is a test listing.', 'category_id' => 6, 'municipality_id' => $this->town,
            'address' => 'Test St.', 'latitude' => 12.670, 'longitude' => 123.880, 'operating_hours' => '6:00 AM - 6:00 PM',
            'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        DB::table('activities')->insert(['destination_id' => $park, 'name' => 'Strolling', 'type' => 'outdoor', 'created_at' => now(), 'updated_at' => now()]);
        $ai->reply = [
            ['day_number' => 1, 'destinations' => [['destination_id' => $park, 'reason' => 'A stroll.']]],
            ['day_number' => 2, 'destinations' => []],
        ];
    }

    private function stay(string $key, float $km, string $price = 'moderate', array $categories = ['Inn']): void
    {
        $this->ids[$key] = Accommodation::create([
            'name' => ucfirst($key).' Inn', 'description' => 'A test listing.', 'municipality_id' => $this->town, 'address' => 'Test St.',
            'latitude' => 12.670 + $km / 111.2, 'longitude' => 123.880, 'type' => $categories[0], 'classifications' => $categories,
            'price_range' => $price, 'status' => 'approved',
        ])->id;
    }

    /** $scores as reviews of the stay, each by a different guest. */
    private function reviews(string $key, array $scores, array $extra = []): void
    {
        foreach ($scores as $score) {
            $guest = User::create(['role_id' => $this->tourist->role_id, 'name' => 'Guest', 'email' => 'guest'.(++$this->raters).'@example.com', 'password' => 'x']);
            DB::table('ratings')->insert($extra + ['user_id' => $guest->id, 'rateable_type' => Accommodation::class, 'rateable_id' => $this->ids[$key],
                'score' => $score, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function generate(string $budget = 'moderate', ?string $type = null): Itinerary
    {
        $start = now()->addDays(3);
        $id = $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', array_filter([
            'municipality_ids' => [$this->town], 'num_days' => 2, 'interests' => ['nature', 'relaxation'], 'travel_pace' => 'moderate',
            'budget' => $budget, 'travelers' => 2, 'accommodation_needed' => true, 'accommodation_type' => $type,
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDay()->toDateString(),
        ], fn ($v) => $v !== null))->assertCreated()->json('itinerary.id');

        return Itinerary::with('stays')->find($id);
    }

    public function test_a_well_rated_stay_a_little_farther_beats_the_nearest_one(): void
    {
        $this->stay('near', 0.5);
        $this->stay('loved', 3);
        $this->reviews('loved', [5, 5, 5, 4, 5, 5]);

        $itinerary = $this->generate();

        $this->assertSame($this->ids['loved'], (int) $itinerary->accommodation_id);
        $this->assertStringContainsString('Rated 4.8/5 by 6 guests.', (string) $itinerary->stays->first()->reason);
        $this->assertStringContainsString('In your Moderate price range.', (string) $itinerary->stays->first()->reason);
    }

    public function test_a_poorly_rated_nearest_stay_gives_way(): void
    {
        $this->stay('near', 0.5);
        $this->reviews('near', [2, 2, 1, 3, 2]);
        $this->stay('plain', 3);

        $this->assertSame($this->ids['plain'], (int) $this->generate()->accommodation_id);
    }

    public function test_fewer_than_five_demo_or_hidden_reviews_never_count(): void
    {
        $this->stay('near', 0.5);
        $this->stay('fewReviews', 3);
        $this->reviews('fewReviews', [5, 5, 5, 5]);
        $this->stay('demoReviews', 3.1);
        $this->reviews('demoReviews', [5, 5, 5, 5, 5, 5], ['is_demo' => true]);
        $this->stay('hiddenReviews', 3.2);
        $this->reviews('hiddenReviews', [5, 5, 5, 5, 5, 5], ['hidden_at' => now()]);

        $itinerary = $this->generate();

        $this->assertSame($this->ids['near'], (int) $itinerary->accommodation_id);
        $this->assertStringNotContainsString('Rated', (string) $itinerary->stays->first()->reason);
    }

    public function test_the_tourist_s_budget_outweighs_a_short_extra_drive(): void
    {
        $this->stay('nearPricey', 0.5, 'moderate');
        $this->stay('cheap', 2.5, 'budget');

        $itinerary = $this->generate('budget');

        $this->assertSame($this->ids['cheap'], (int) $itinerary->accommodation_id);
        $this->assertStringContainsString('In your Budget price range.', (string) $itinerary->stays->first()->reason);
    }

    public function test_a_great_rating_never_means_a_long_drive_every_day(): void
    {
        $this->stay('near', 0.5);
        $this->stay('farAway', 12);
        $this->reviews('farAway', [5, 5, 5, 5, 5, 5, 5]);

        $this->assertSame($this->ids['near'], (int) $this->generate()->accommodation_id);
    }

    public function test_the_stay_type_still_comes_before_ratings_and_budget(): void
    {
        $this->stay('lovedInn', 0.5, 'budget');
        $this->reviews('lovedInn', [5, 5, 5, 5, 5]);
        $this->stay('hotel', 4, 'moderate', ['Hotel']);

        $this->assertSame($this->ids['hotel'], (int) $this->generate('budget', 'hotel')->accommodation_id);
    }
}

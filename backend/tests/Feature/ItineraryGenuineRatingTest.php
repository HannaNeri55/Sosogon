<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Rating;
use App\Models\Role;
use App\Models\User;
use App\Services\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Only genuine visitor ratings rank destinations in the itinerary planner and reach the AI:
 * seeded demo reviews (shown publicly where SHOW_DEMO_REVIEWS allows, as in this test
 * environment) and reviews hidden after a report never do. Ratings themselves are untouched.
 */
class ItineraryGenuineRatingTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private array $towns = [];

    private int $roleId;

    private int $reviewers = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.model' => 'test-model',
            'services.gemini.fallback_models' => [],
            'services.google_maps.api_key' => '',
            'services.itinerary.daily_limit' => 100,
        ]);

        $this->roleId = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        $this->tourist = User::create([
            'role_id' => $this->roleId, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password'),
        ]);
        foreach (['north' => 'Northtown', 'south' => 'Southtown'] as $key => $name) {
            $this->towns[$key] = DB::table('municipalities')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('categories')->insert(['id' => 7, 'name' => 'Eco-Tourism', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);

        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => fn () => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['title' => 'Trip', 'days' => [['day_number' => 1, 'destinations' => []]]])]]]]],
        ])]);
    }

    private function destination(string $name, string $town = 'north', float $lat = 12.670, float $lng = 123.880): Destination
    {
        $destination = Destination::create([
            'name' => $name, 'description' => "{$name} is a test place.", 'municipality_id' => $this->towns[$town], 'address' => 'Test St.',
            'latitude' => $lat, 'longitude' => $lng, 'category_id' => 7, 'operating_hours' => '6:00 AM - 6:00 PM',
            'created_by' => $this->tourist->id, 'status' => 'published',
        ]);
        DB::table('activities')->insert(['destination_id' => $destination->id, 'name' => 'Nature walk', 'type' => 'outdoor', 'created_at' => now(), 'updated_at' => now()]);

        return $destination;
    }

    /**
     * Adds ratings from new reviewers (one each, as the unique index requires), then stores the
     * listing's public aggregates the way the app does: every visible rating, demo ones included.
     */
    private function rate(Destination $destination, array $scores, bool $demo = false, bool $hidden = false): void
    {
        foreach ($scores as $score) {
            $user = User::create([
                'role_id' => $this->roleId, 'name' => 'Reviewer', 'email' => 'reviewer'.(++$this->reviewers).'@example.com', 'password' => bcrypt('password'),
            ]);
            $rating = Rating::create(['user_id' => $user->id, 'rateable_type' => Destination::class, 'rateable_id' => $destination->id, 'score' => $score, 'comment' => $demo ? 'Demo Review: Nice.' : 'Nice.']);
            $rating->forceFill(['is_demo' => $demo, 'hidden_at' => $hidden ? now() : null])->save();
        }
        $destination->recalculateRating();
    }

    private function service(): ItineraryService
    {
        return app(ItineraryService::class);
    }

    /** Destinations as the planner loads them for a trip in the north town. */
    private function eligible()
    {
        return (new ReflectionMethod($this->service(), 'getEligibleDestinations'))
            ->invoke($this->service(), ['municipality_ids' => [$this->towns['north']], 'interests' => ['nature']])
            ->keyBy('name');
    }

    private function score(Destination $destination): int
    {
        return (new ReflectionMethod($this->service(), 'priorityScore'))->invoke($this->service(), $destination);
    }

    private function generate(array $extra = []): Itinerary
    {
        $start = now()->addDays(3);
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $extra + [
            'num_days' => 1,
            'interests' => ['nature'],
            'travel_pace' => 'moderate',
            'budget' => 'moderate',
            'start_date' => $start->toDateString(),
            'end_date' => $start->toDateString(),
            'accommodation_needed' => false,
        ])->assertCreated();

        return Itinerary::with('municipalities')->latest('id')->firstOrFail();
    }

    /** The prompt sent to the (faked) AI. */
    private function prompt(): string
    {
        $request = Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage'))->last()[0];

        return $request->data()['contents'][0]['parts'][0]['text'];
    }

    public function test_demo_ratings_do_not_raise_a_destinations_rank(): void
    {
        $plain = $this->destination('Plain Park');
        $demo = $this->destination('Demo Park');
        $this->rate($demo, [5, 5, 5, 5, 5, 5], demo: true);

        // The public aggregates include the demo reviews; the planner must not.
        $this->assertSame(6, (int) $demo->fresh()->total_reviews);
        $places = $this->eligible();
        $this->assertSame($this->score($places['Plain Park']), $this->score($places['Demo Park']));
        $this->assertSame(0, $this->score($places['Demo Park']));
    }

    public function test_genuine_ratings_still_raise_the_rank_by_the_existing_rule(): void
    {
        $plain = $this->destination('Plain Park');
        $loved = $this->destination('Loved Park');
        // Average 4.6 from five genuine ratings: (4.6 − 3.5) × 20 = 22 points.
        $this->rate($loved, [5, 5, 5, 4, 4]);

        $places = $this->eligible();
        $this->assertSame(22, $this->score($places['Loved Park']) - $this->score($places['Plain Park']));
    }

    public function test_only_the_genuine_ratings_of_a_mixed_destination_count(): void
    {
        $mixed = $this->destination('Mixed Park');
        // Genuine average 4.0 (+10); ten demo five-star reviews would lift it to 4.67 (+23).
        $this->rate($mixed, [4, 4, 4, 4, 4]);
        $this->rate($mixed, array_fill(0, 10, 5), demo: true);

        $this->assertSame(10, $this->score($this->eligible()['Mixed Park']));
    }

    public function test_too_few_genuine_ratings_neither_promote_nor_penalise(): void
    {
        $few = $this->destination('Few Reviews Park');
        $this->rate($few, [5, 5, 5, 5]);
        $poor = $this->destination('Poorly Rated Park');
        $this->rate($poor, [1, 2, 2, 1, 2]);
        $hidden = $this->destination('Hidden Reviews Park');
        $this->rate($hidden, [5, 5, 5, 5, 5], hidden: true);

        $places = $this->eligible();
        $this->assertSame(0, $this->score($places['Few Reviews Park']), 'Four genuine ratings are not enough to count.');
        $this->assertSame(0, $this->score($places['Poorly Rated Park']), 'Low ratings add nothing but take nothing away.');
        $this->assertSame(0, $this->score($places['Hidden Reviews Park']), 'Reviews hidden after a report do not count.');
    }

    public function test_other_ranking_factors_are_unchanged(): void
    {
        $featured = $this->destination('Featured Park');
        $featured->forceFill(['is_featured' => true, 'tourism_priority' => 'popular', 'view_count' => 230])->save();
        $this->rate($featured, [5, 5, 5, 5, 5, 5], demo: true);

        // Popular 60 + featured 40 + views 15 (capped); nothing from the demo reviews.
        $this->assertSame(115, $this->score($this->eligible()['Featured Park']));
    }

    public function test_a_destination_loaded_elsewhere_is_ranked_the_same_way(): void
    {
        $loved = $this->destination('Loved Park');
        $this->rate($loved, [5, 5, 5, 4, 4]);
        $this->rate($loved, [1, 1, 1], demo: true);

        // A plain model (no planner aggregates loaded) counts its genuine ratings on demand.
        $this->assertSame(22, $this->score(Destination::findOrFail($loved->id)));
        $this->assertSame($this->score($this->eligible()['Loved Park']), $this->score(Destination::findOrFail($loved->id)));
    }

    public function test_the_ai_is_told_genuine_ratings_only(): void
    {
        $loved = $this->destination('Loved Park');
        $this->rate($loved, [5, 5, 5, 4, 4]);
        $this->rate($loved, [5, 5, 5, 5, 5, 5, 5, 5], demo: true);
        $demo = $this->destination('Demo Park', lat: 12.672);
        $this->rate($demo, [5, 5, 5, 5, 5, 5], demo: true);

        $this->generate(['municipality_ids' => [$this->towns['north']]]);

        $prompt = $this->prompt();
        $this->assertStringContainsString('Loved Park | Town: Northtown', $prompt);
        $this->assertStringContainsString('rated 4.6/5 by 5 visitors', $prompt);
        $this->assertStringNotContainsString('by 13 visitors', $prompt);
        $this->assertSame(1, substr_count($prompt, 'visitors'), 'The demo-only destination gets no visitor rating.');
    }

    public function test_demo_ratings_do_not_decide_which_towns_a_trip_visits(): void
    {
        // Left to SOSOGON, a one-day trip goes to the town with the better-ranked attraction.
        // North's has only demo five-star reviews; South's has five genuine ones averaging 4.6.
        $this->rate($this->destination('Demo Falls', 'north', 12.670, 123.880), array_fill(0, 10, 5), demo: true);
        $this->rate($this->destination('Loved Lake', 'south', 12.900, 123.880), [5, 5, 5, 4, 4]);

        $itinerary = $this->generate(['municipality_ids' => []]);

        $this->assertSame([$this->towns['south']], $itinerary->municipalities->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_planning_leaves_every_rating_untouched(): void
    {
        $loved = $this->destination('Loved Park');
        $this->rate($loved, [5, 4, 4, 5, 3]);
        $this->rate($loved, [5, 5, 5], demo: true);
        $before = Rating::orderBy('id')->get(['id', 'score', 'is_demo', 'hidden_at', 'comment'])->toArray();
        $aggregates = $loved->fresh()->only(['average_rating', 'total_reviews']);

        $this->generate(['municipality_ids' => [$this->towns['north']]]);

        $this->assertSame($before, Rating::orderBy('id')->get(['id', 'score', 'is_demo', 'hidden_at', 'comment'])->toArray());
        $this->assertSame($aggregates, $loved->fresh()->only(['average_rating', 'total_reviews']));
    }
}

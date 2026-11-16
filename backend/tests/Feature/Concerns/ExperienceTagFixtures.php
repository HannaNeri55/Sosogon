<?php

namespace Tests\Feature\Concerns;

use App\Contracts\ItineraryAI;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use App\Services\InterestMatcher;
use App\Services\ItineraryService;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;

/**
 * A small province for the Phase 2D experience-tag tests (SQLite, built per test; never real data),
 * with every reviewed / unreviewed case:
 * - Alpha: an unreviewed beach; an unreviewed waterfall with a draft "heritage" tag; a reviewed
 *   mountain with no tags; a reviewed park with only an unrecognized tag; a reviewed Beach-category
 *   place tagged only "heritage"; a reviewed resort tagged beach + resort; a reviewed island
 *   tagged museum + an unrecognized tag; a reviewed hill tagged only viewpoint; an unpublished
 *   reviewed beach tagged beach; a restaurant and a souvenir shop.
 * - Bravo: unreviewed ruins and chapel; an unreviewed eco park with a draft "beach" tag; a reviewed
 *   eco spring tagged spring_river.
 * - Charlie: an unreviewed island and resort; a reviewed eco road tagged scenic_route.
 * - Delta: only a reviewed eco mangrove tagged forest_mangrove (its last Adventure place).
 * The AI is a deterministic fake: it takes the first two listed IDs for each day.
 */
trait ExperienceTagFixtures
{
    /** The category map before Phase 2D, copied here so the legacy rule is checked independently. */
    protected const LEGACY_INTEREST_CATEGORIES = [
        'nature' => [1, 2, 3, 7, 8],
        'beaches' => [1, 8],
        'culture' => [4, 5],
        'history' => [4, 5],
        'adventure' => [2, 3, 7, 8],
        'relaxation' => [1, 6, 8, 9],
        'food' => [],
        'shopping' => [],
    ];

    protected const ALL_INTERESTS = ['nature', 'beaches', 'culture', 'history', 'adventure', 'relaxation', 'food', 'shopping'];

    protected User $tourist;

    protected array $towns = [];

    /** Fixture destinations by key => id. */
    protected array $places = [];

    /** The fake AI's prompts, in order. */
    protected object $ai;

    protected function buildExperienceFixtures(): void
    {
        config([
            'services.google_maps.api_key' => '',
            'services.itinerary.daily_limit' => 100000,
        ]);
        Http::preventStrayRequests();
        $this->ai = new class implements ItineraryAI
        {
            public array $prompts = [];

            public function generate(string $prompt): string
            {
                $this->prompts[] = $prompt;
                preg_match_all('/^ID:(\d+) \|/m', $prompt, $ids);
                preg_match('/Create a (\d+)-day/', $prompt, $days);
                $ids = array_map('intval', $ids[1]);
                $plan = [];
                for ($n = 1; $n <= (int) ($days[1] ?? 1); $n++) {
                    $picks = array_slice($ids, ($n - 1) * 2, 2);
                    $plan[] = ['day_number' => $n, 'title' => "Day {$n}", 'highlight_id' => $picks[0] ?? 0,
                        'destinations' => array_map(fn ($id) => ['destination_id' => $id, 'reason' => 'A fixture pick.'], $picks)];
                }

                return json_encode(['title' => 'Fixture trip', 'days' => $plan]);
            }
        };
        $this->app->instance(ItineraryAI::class, $this->ai);
        $this->app->forgetInstance(ItineraryService::class);

        $role = Role::firstOrCreate(['name' => 'tourist'], ['description' => 'Tourist']);
        $this->tourist = User::create(['role_id' => $role->id, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password')]);
        foreach (['alpha' => 'Alpha', 'bravo' => 'Bravo', 'charlie' => 'Charlie', 'delta' => 'Delta'] as $key => $name) {
            $this->towns[$key] = DB::table('municipalities')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([1 => 'Beach', 2 => 'Mountain', 3 => 'Waterfall', 4 => 'Historical', 5 => 'Church', 6 => 'Park', 7 => 'Eco-Tourism', 8 => 'Island', 9 => 'Resort'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }
        // A test-only tag the interest map doesn't know.
        DB::table('experience_tags')->insert(['slug' => 'zz_unmapped', 'name' => 'Unmapped test tag', 'group' => 'Test', 'description' => 'Not in the interest map.',
            'sort_order' => 99, 'created_at' => now(), 'updated_at' => now()]);

        $this->place('plainBeach', 'Plain Beach', 1, 'alpha', 12.670, 123.880);
        $this->place('draftFalls', 'Draft Falls', 3, 'alpha', 12.672, 123.881);
        $this->tag('draftFalls', ['heritage']);
        $this->place('bareHill', 'Reviewed Bare Hill', 2, 'alpha', 12.674, 123.879);
        $this->review('bareHill');
        $this->place('oddPark', 'Reviewed Odd Park', 6, 'alpha', 12.671, 123.883);
        $this->tag('oddPark', ['zz_unmapped']);
        $this->review('oddPark');
        $this->place('churchBeach', 'Reviewed Church Beach', 1, 'alpha', 12.669, 123.878);
        $this->tag('churchBeach', ['heritage']);
        $this->review('churchBeach');
        $this->place('resortCove', 'Reviewed Resort Cove', 9, 'alpha', 12.668, 123.882);
        $this->tag('resortCove', ['beach', 'resort']);
        $this->review('resortCove');
        $this->place('mixedIsland', 'Reviewed Mixed Island', 8, 'alpha', 12.666, 123.884);
        $this->tag('mixedIsland', ['zz_unmapped', 'museum']);
        $this->review('mixedIsland');
        $this->place('viewHill', 'Reviewed Viewpoint Hill', 2, 'alpha', 12.675, 123.885);
        $this->tag('viewHill', ['viewpoint']);
        $this->review('viewHill');
        $this->place('hiddenBeach', 'Hidden Reviewed Beach', 1, 'alpha', 12.667, 123.879, ['status' => 'draft']);
        $this->tag('hiddenBeach', ['beach']);
        $this->review('hiddenBeach');

        $this->place('ruins', 'Bravo Ruins', 4, 'bravo', 12.750, 123.950);
        $this->place('chapel', 'Bravo Chapel', 5, 'bravo', 12.752, 123.951);
        $this->place('ecoPark', 'Bravo Eco Park', 7, 'bravo', 12.751, 123.953);
        $this->tag('ecoPark', ['beach']);
        $this->place('spring', 'Bravo Spring', 7, 'bravo', 12.753, 123.949);
        $this->tag('spring', ['spring_river']);
        $this->review('spring');

        $this->place('island', 'Charlie Island', 8, 'charlie', 12.600, 123.800);
        $this->place('resort', 'Charlie Resort', 9, 'charlie', 12.602, 123.801);
        $this->place('road', 'Charlie Road', 7, 'charlie', 12.601, 123.803);
        $this->tag('road', ['scenic_route']);
        $this->review('road');

        $this->place('mangrove', 'Delta Mangrove', 7, 'delta', 12.800, 124.000);
        $this->tag('mangrove', ['forest_mangrove']);
        $this->review('mangrove');

        $listing = fn (string $name, float $lat, float $lng) => ['name' => $name, 'description' => "{$name} is a test listing.", 'municipality_id' => $this->towns['alpha'],
            'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng, 'status' => 'approved'];
        FoodBeverage::create($listing('Alpha Kainan', 12.6705, 123.8805) + ['cuisine_type' => 'Filipino', 'price_range' => 'moderate', 'operating_hours' => '6:00 AM - 10:00 PM']);
        RetailShop::create($listing('Alpha Pasalubong', 12.6710, 123.8790));
    }

    protected function place(string $key, string $name, int $category, string $town, float $lat, float $lng, array $extra = []): int
    {
        $id = Destination::create($extra + [
            'name' => $name, 'description' => "{$name} is a test listing.", 'category_id' => $category, 'municipality_id' => $this->towns[$town],
            'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng, 'operating_hours' => '6:00 AM - 6:00 PM',
            'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        DB::table('activities')->insert(['destination_id' => $id, 'name' => 'Look around', 'type' => 'outdoor', 'created_at' => now(), 'updated_at' => now()]);

        return $this->places[$key] = $id;
    }

    protected function tag(string $key, array $slugs): void
    {
        foreach (DB::table('experience_tags')->whereIn('slug', $slugs)->pluck('id') as $tagId) {
            DB::table('destination_experience_tag')->insert(['destination_id' => $this->places[$key], 'experience_tag_id' => $tagId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function review(string $key): void
    {
        DB::table('destinations')->where('id', $this->places[$key])->update(['experiences_reviewed_at' => now(), 'experiences_reviewed_by' => $this->tourist->id]);
    }

    /** Clears every review mark (tags stay, as drafts). */
    protected function unreviewAll(): void
    {
        DB::table('destinations')->update(['experiences_reviewed_at' => null, 'experiences_reviewed_by' => null]);
    }

    protected function matchByTags(bool $on): void
    {
        config(['services.itinerary.match_by_tags' => $on]);
    }

    /** All 255 non-empty combinations of the eight interests. */
    protected function interestCombinations(): array
    {
        $combos = [];
        for ($mask = 1; $mask < 2 ** count(self::ALL_INTERESTS); $mask++) {
            $combos[] = array_values(array_filter(self::ALL_INTERESTS, fn ($interest, $i) => $mask & (1 << $i), ARRAY_FILTER_USE_BOTH));
        }

        return $combos;
    }

    protected function townIds(array $keys): array
    {
        return array_map(fn ($key) => $this->towns[$key], $keys);
    }

    /** What the pre-Phase-2D rule selects: published, in the towns, category in the frozen map. */
    protected function legacyIds(array $interests, array $townKeys): array
    {
        $categories = array_unique(array_merge(...array_map(fn ($i) => self::LEGACY_INTEREST_CATEGORIES[$i], $interests)));
        if ($categories === []) {
            return [];
        }

        return Destination::where('status', 'published')->whereIn('municipality_id', $this->townIds($townKeys))->whereIn('category_id', $categories)
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** The planner's eligible pool (getEligibleDestinations) for these interests and towns. */
    protected function pool(array $interests, array $townKeys)
    {
        $service = app(ItineraryService::class);

        return (new ReflectionMethod($service, 'getEligibleDestinations'))
            ->invoke($service, ['municipality_ids' => $this->townIds($townKeys), 'interests' => $interests]);
    }

    protected function eligibleIds(array $interests, array $townKeys): array
    {
        return $this->pool($interests, $townKeys)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /** chooseTowns() for these interests: its result, or the message it stops with. */
    protected function chosenTowns(array $interests, int $days = 3): array|string
    {
        $service = app(ItineraryService::class);
        try {
            return (new ReflectionMethod($service, 'chooseTowns'))->invoke($service, ['num_days' => $days, 'interests' => $interests]);
        } catch (\Throwable $e) {
            return get_class($e).': '.$e->getMessage();
        }
    }

    /** Every place the picker endpoint lists (all pages), as sorted IDs. */
    protected function pickerIds(array $interests, array $townKeys, int $perPage = 100): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $ids = [];
        $page = 1;
        do {
            $response = $this->getJson('/api/v1/itinerary-places?'.http_build_query([
                'municipality_ids' => $this->townIds($townKeys), 'interests' => $interests, 'page' => $page, 'per_page' => $perPage,
            ]))->assertOk();
            $ids = array_merge($ids, array_column($response->json('data'), 'id'));
            $last = $response->json('last_page');
            $page++;
        } while ($page <= $last);
        sort($ids);

        return $ids;
    }

    protected function matcher(): InterestMatcher
    {
        return app(InterestMatcher::class);
    }

    /** Generates a trip through the API; returns the response. */
    protected function generateTrip(array $interests, array $townKeys = ['alpha'], array $extra = [])
    {
        $start = now()->addDays(3);

        return $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', $extra + [
            'municipality_ids' => $this->townIds($townKeys),
            'num_days' => 2,
            'interests' => $interests,
            'travel_pace' => 'moderate',
            'budget' => 'moderate',
            'accommodation_needed' => false,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
        ]);
    }
}

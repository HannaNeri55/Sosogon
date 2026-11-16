<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Itinerary;
use App\Services\InterestMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Mockery;
use Tests\Feature\Concerns\ExperienceTagFixtures;
use Tests\TestCase;

/**
 * Phase 2D: interests select reviewed destinations through their confirmed experience tags
 * (InterestMatcher), behind services.itinerary.match_by_tags. With the flag off, or with no
 * destination reviewed, every result is exactly the old category matching.
 */
class ItineraryExperienceMatchingTest extends TestCase
{
    use ExperienceTagFixtures;
    use RefreshDatabase;

    private const EVERY_TOWN = ['alpha', 'bravo', 'charlie', 'delta'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExperienceFixtures();
    }

    public function test_the_flag_is_off_by_default_and_the_category_map_is_unchanged(): void
    {
        $this->assertFalse(config('services.itinerary.match_by_tags'));
        $this->assertFalse($this->matcher()->byTags());
        $this->assertSame(self::LEGACY_INTEREST_CATEGORIES, InterestMatcher::INTEREST_CATEGORIES);
    }

    public function test_each_reviewed_and_unreviewed_case_matches_by_the_documented_rule(): void
    {
        $this->matchByTags(true);
        $in = fn (string $interest, string $key) => in_array($this->places[$key], $this->eligibleIds([$interest], self::EVERY_TOWN), true);

        // Unreviewed, no tags: by category (Beach).
        $this->assertTrue($in('beaches', 'plainBeach'));
        $this->assertFalse($in('culture', 'plainBeach'));
        // Unreviewed with a draft tag (heritage): still by category (Waterfall), the tag ignored.
        $this->assertTrue($in('adventure', 'draftFalls'));
        $this->assertFalse($in('culture', 'draftFalls'));
        // Reviewed, no tags: category fallback (Mountain).
        $this->assertTrue($in('nature', 'bareHill'));
        $this->assertTrue($in('adventure', 'bareHill'));
        // Reviewed, only an unrecognized tag: category fallback (Park).
        $this->assertTrue($in('relaxation', 'oddPark'));
        $this->assertFalse($in('nature', 'oddPark'));
        // Reviewed, recognized tag that doesn't match: excluded, no fallback to its Beach category.
        $this->assertFalse($in('beaches', 'churchBeach'));
        $this->assertFalse($in('nature', 'churchBeach'));
        $this->assertTrue($in('culture', 'churchBeach'));
        $this->assertTrue($in('history', 'churchBeach'));
        // Reviewed, recognized matching tags: included, though its Resort category isn't Beaches or Nature.
        $this->assertTrue($in('beaches', 'resortCove'));
        $this->assertTrue($in('nature', 'resortCove'));
        $this->assertTrue($in('relaxation', 'resortCove'));
        $this->assertFalse($in('adventure', 'resortCove'));
        // Reviewed, recognized + unrecognized tags: the recognized one (museum) only; its Island category no longer counts.
        $this->assertTrue($in('culture', 'mixedIsland'));
        foreach (['nature', 'beaches', 'adventure', 'relaxation'] as $interest) {
            $this->assertFalse($in($interest, 'mixedIsland'), $interest);
        }
        // The approved reductions: viewpoint, spring_river and forest_mangrove alone aren't Adventure.
        $this->assertTrue($in('nature', 'viewHill'));
        $this->assertFalse($in('adventure', 'viewHill'));
        $this->assertTrue($in('relaxation', 'spring'));
        $this->assertFalse($in('adventure', 'spring'));
        $this->assertFalse($in('adventure', 'mangrove'));
        $this->assertTrue($in('adventure', 'road'), 'scenic_route is Adventure');
        $this->assertTrue($in('nature', 'road'), 'and Nature');
        // Unpublished: never, whatever its tags.
        foreach (self::ALL_INTERESTS as $interest) {
            $this->assertFalse($in($interest, 'hiddenBeach'), $interest);
        }
    }

    public function test_reviewed_destinations_without_a_recognized_tag_fall_back_and_are_logged_once(): void
    {
        $this->matchByTags(true);
        Log::spy();

        $pool = $this->pool(['nature', 'relaxation'], ['alpha']);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => str_contains($message, 'recognized experience tag')
            && $context === [InterestMatcher::FALLBACK_NO_TAGS => [$this->places['bareHill']], InterestMatcher::FALLBACK_UNRECOGNIZED_ONLY => [$this->places['oddPark']]]);
        $reasons = $pool->mapWithKeys(fn ($d) => [$d->id => $this->matcher()->fallbackReason($d)]);
        $this->assertSame(InterestMatcher::FALLBACK_NO_TAGS, $reasons[$this->places['bareHill']]);
        $this->assertSame(InterestMatcher::FALLBACK_UNRECOGNIZED_ONLY, $reasons[$this->places['oddPark']]);
        $this->assertNull($reasons[$this->places['plainBeach']], 'unreviewed');
        $this->assertNull($reasons[$this->places['resortCove']], 'matched by its tags');
        // Nothing about the records changed.
        $this->assertNotNull(Destination::find($this->places['bareHill'])->experiences_reviewed_at);
        $this->assertSame(0, DB::table('destination_experience_tag')->where('destination_id', $this->places['bareHill'])->count());
    }

    public function test_the_tag_branch_never_bypasses_the_published_or_town_filters(): void
    {
        $this->matchByTags(true);

        // Beaches in Bravo: the reviewed Alpha places with a beach tag stay out (other town), and
        // the unpublished reviewed beach never appears anywhere.
        $this->assertSame([], array_intersect($this->eligibleIds(['beaches'], ['bravo']), [$this->places['resortCove'], $this->places['hiddenBeach']]));
        foreach ($this->interestCombinations() as $interests) {
            $this->assertNotContains($this->places['hiddenBeach'], $this->eligibleIds($interests, self::EVERY_TOWN));
            $this->assertSame([], array_diff($this->eligibleIds($interests, ['charlie']), $this->legacyIds(['nature', 'beaches', 'culture', 'adventure', 'relaxation'], ['charlie'])));
        }
    }

    public function test_sql_and_php_matching_agree_for_every_interest_combination(): void
    {
        $this->matchByTags(true);
        $matcher = $this->matcher();
        $everyone = Destination::query()->select('id', 'category_id', 'municipality_id', 'status')->tap(fn ($q) => $matcher->prepare($q))->get();

        foreach ([self::EVERY_TOWN, ['alpha'], ['bravo', 'charlie']] as $townKeys) {
            $towns = $this->townIds($townKeys);
            foreach ($this->interestCombinations() as $interests) {
                $php = $everyone->filter(fn ($d) => $d->status === 'published' && in_array((int) $d->municipality_id, $towns, true)
                    && $matcher->interestsOf($d, $interests) !== [])->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

                $this->assertSame($php, $this->eligibleIds($interests, $townKeys), implode('+', $interests).' in '.implode(',', $townKeys));
            }
        }
    }

    public function test_multiple_interests_select_a_place_matching_any_of_them(): void
    {
        $this->matchByTags(true);

        $union = array_values(array_unique(array_merge($this->eligibleIds(['beaches'], self::EVERY_TOWN), $this->eligibleIds(['culture'], self::EVERY_TOWN))));
        sort($union);
        $this->assertSame($union, $this->eligibleIds(['beaches', 'culture'], self::EVERY_TOWN));
        $this->assertContains($this->places['churchBeach'], $union, 'heritage tag: Culture');
        $this->assertContains($this->places['resortCove'], $union, 'beach tag: Beaches');
    }

    public function test_food_and_shopping_keep_their_special_handling(): void
    {
        $this->matchByTags(true);

        foreach ([['food'], ['shopping'], ['food', 'shopping']] as $interests) {
            $this->assertSame([], $this->eligibleIds($interests, self::EVERY_TOWN));
            $this->assertSame([], $this->matcher()->sightseeing($interests));
        }
        $this->assertSame($this->eligibleIds(['beaches'], self::EVERY_TOWN), $this->eligibleIds(['beaches', 'food', 'shopping'], self::EVERY_TOWN));

        // A food-only trip is built from restaurants without asking the AI, as before.
        $this->generateTrip(['food'])->assertCreated();
        $this->assertSame([], $this->ai->prompts);
        $this->assertSame([], Itinerary::latest('id')->first()->items->where('item_type', 'destination')->all());
    }

    public function test_top_ups_and_replacements_come_only_from_the_eligible_pool(): void
    {
        // An iconic Beach-category place tagged only "heritage": the planner's highlight and top-up
        // steps would add it to any Beaches trip in Alpha by category.
        Destination::whereKey($this->places['churchBeach'])->update(['tourism_priority' => 'iconic']);

        $this->matchByTags(false);
        $this->generateTrip(['beaches'])->assertCreated();
        $this->assertContains($this->places['churchBeach'], $this->visited(), 'By category it is a Beaches highlight.');

        $this->matchByTags(true);
        $this->generateTrip(['beaches'])->assertCreated();
        $visited = $this->visited();
        $this->assertNotContains($this->places['churchBeach'], $visited, 'Reviewed as heritage only: never brought back in.');
        $this->assertNotContains($this->places['mixedIsland'], $visited);
        $this->assertNotContains($this->places['hiddenBeach'], $visited);
        $this->assertSame([], array_diff($visited, $this->eligibleIds(['beaches'], ['alpha'])), 'Every stop is from the eligible pool.');
    }

    public function test_a_reviewed_place_matching_only_by_tag_is_offered_to_the_ai(): void
    {
        // Beaches in Alpha: the resort cove (Resort category) is Beaches only through its beach tag.
        $this->matchByTags(false);
        $this->generateTrip(['beaches'])->assertCreated();
        $this->assertStringNotContainsString('ID:'.$this->places['resortCove'].' |', end($this->ai->prompts));

        $this->matchByTags(true);
        $this->generateTrip(['beaches'])->assertCreated();
        $this->assertStringContainsString('ID:'.$this->places['resortCove'].' |', end($this->ai->prompts));
        $this->assertStringNotContainsString('ID:'.$this->places['churchBeach'].' |', end($this->ai->prompts));
    }

    public function test_the_prompt_lists_confirmed_experiences_only_for_reviewed_destinations(): void
    {
        $this->matchByTags(true);
        $this->generateTrip(['nature', 'beaches', 'culture', 'relaxation'])->assertCreated();
        $prompt = end($this->ai->prompts);
        $line = fn (string $key) => collect(explode("\n", $prompt))->first(fn ($l) => str_starts_with($l, 'ID:'.$this->places[$key].' |'));

        $this->assertStringContainsString('| Category: Resort | Experiences (confirmed by the tourism office): Beach, Resort (day use) |', $line('resortCove'));
        $this->assertStringContainsString('| Experiences (confirmed by the tourism office): Museum |', $line('mixedIsland'), 'Only the recognized tag.');
        $this->assertStringNotContainsString('Unmapped', $line('mixedIsland'));
        $this->assertStringNotContainsString('Experiences', $line('draftFalls'), 'Draft tags are never confirmed experiences.');
        $this->assertStringNotContainsString('Experiences', $line('bareHill'), 'Reviewed without tags: nothing invented.');
        $this->assertStringNotContainsString('Experiences', $line('oddPark'), 'Only unrecognized tags: nothing claimed.');
        $this->assertStringContainsString('"Experiences", where a place lists them, were confirmed by the tourism office', $prompt);
        $this->assertStringContainsString('| Category: Beach |', $line('plainBeach'), 'Category stays.');
    }

    public function test_destinations_must_be_loaded_with_the_review_column_and_their_tags(): void
    {
        $this->matchByTags(true);
        $matcher = $this->matcher();

        $withoutColumn = Destination::select('id', 'category_id')->find($this->places['resortCove']);
        $this->assertThrows(fn () => $matcher->interestsOf($withoutColumn, ['beaches']), LogicException::class);

        $withoutTags = Destination::select('id', 'category_id', 'experiences_reviewed_at')->find($this->places['resortCove']);
        $this->assertThrows(fn () => $matcher->interestsOf($withoutTags, ['beaches']), LogicException::class);

        // With the flag off nothing extra is needed.
        $this->matchByTags(false);
        $this->assertSame(['beaches'], $matcher->interestsOf(Destination::select('id', 'category_id')->find($this->places['plainBeach']), ['beaches']));
    }

    public function test_every_tag_is_eager_loaded_without_a_query_per_destination(): void
    {
        $this->matchByTags(true);
        $pool = $this->pool(self::ALL_INTERESTS, self::EVERY_TOWN);

        $mixed = $pool->firstWhere('id', $this->places['mixedIsland']);
        $this->assertTrue($mixed->relationLoaded('experienceTags'));
        $this->assertEqualsCanonicalizing(['museum', 'zz_unmapped'], $mixed->experienceTags->pluck('slug')->all(), 'Unrecognized tags are loaded too.');
        $this->assertNull($this->matcher()->fallbackReason($mixed));

        $queries = fn () => $this->countQueries(fn () => $this->pool(self::ALL_INTERESTS, self::EVERY_TOWN)->each(fn ($d) => $this->matcher()->interestsOf($d, self::ALL_INTERESTS)));
        $before = $queries();
        foreach (range(1, 6) as $n) {
            $this->place("extra{$n}", "Extra Cove {$n}", 9, 'alpha', 12.67 + $n / 1000, 123.88);
            $this->tag("extra{$n}", ['beach', 'pool']);
            $this->review("extra{$n}");
        }
        $this->assertSame($before, $queries(), 'Six more reviewed, tagged places: no extra queries.');
    }

    public function test_with_the_flag_off_matching_never_reads_tags_and_stays_category_based(): void
    {
        $this->matchByTags(false);
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = $query->sql;
        });

        foreach ($this->interestCombinations() as $interests) {
            $this->assertSame($this->legacyIds($interests, self::EVERY_TOWN), $this->eligibleIds($interests, self::EVERY_TOWN), implode('+', $interests));
        }
        $this->assertContains($this->places['churchBeach'], $this->eligibleIds(['beaches'], self::EVERY_TOWN), 'Reviewed places still match by category.');
        $this->assertNotContains($this->places['resortCove'], $this->eligibleIds(['beaches'], self::EVERY_TOWN));
        $this->chosenTowns(['nature', 'culture']);
        $this->pickerIds(['beaches', 'culture'], self::EVERY_TOWN);
        $this->generateTrip(['nature', 'culture'])->assertCreated();
        $this->assertStringNotContainsString('Experiences', end($this->ai->prompts));

        $this->assertNotEmpty($sql);
        $this->assertSame([], array_values(array_filter($sql, fn ($q) => str_contains($q, 'experience_tag'))), 'No query reads the tag tables.');
        // The same category constraint as before Phase 2D.
        $legacy = Destination::published()->whereIn('category_id', [1, 8]);
        $now = $this->matcher()->constrain(Destination::published(), ['beaches']);
        $this->assertSame($legacy->toSql(), $now->toSql());
        $this->assertSame($legacy->getBindings(), $now->getBindings());
    }

    public function test_with_the_flag_on_and_nothing_reviewed_every_result_equals_the_legacy_rule(): void
    {
        // The fixture's tags stay as drafts: they must be ignored.
        $this->unreviewAll();
        $this->assertGreaterThan(0, DB::table('destination_experience_tag')->count());
        Log::spy();
        $tripsWithStops = 0;

        foreach ($this->interestCombinations() as $interests) {
            $label = implode('+', $interests);
            $results = [];
            foreach ([false, true] as $on) {
                $this->matchByTags($on);
                $pool = $this->pool($interests, self::EVERY_TOWN);
                $this->ai->prompts = [];
                $response = $this->generateTrip($interests);
                $itinerary = $response->status() === 201 ? Itinerary::with('items')->find($response->json('itinerary.id')) : null;
                $results[$on ? 'tags' : 'categories'] = [
                    'eligible' => $pool->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
                    'interests' => $pool->mapWithKeys(fn ($d) => [$d->id => $this->matcher()->interestsOf($d, $interests)])->sortKeys()->all(),
                    'towns' => $this->chosenTowns($interests),
                    'picker' => $this->pickerIds($interests, self::EVERY_TOWN),
                    'prompt_ids' => array_map(fn ($p) => preg_match_all('/^ID:(\d+) \|/m', $p, $m) ? $m[1] : [], $this->ai->prompts),
                    'prompts' => $this->ai->prompts,
                    'status' => $response->status(),
                    'message' => $response->status() === 201 ? null : $response->json('message'),
                    'stops' => $itinerary?->items->where('item_type', 'destination')->sortBy(['day_number', 'sequence'])
                        ->map(fn ($i) => [$i->day_number, $i->sequence, (int) $i->destination_id, $i->time_slot])->values()->all(),
                ];
            }

            $this->assertSame($results['categories'], $results['tags'], $label);
            $tripsWithStops += $results['tags']['stops'] ? 1 : 0;
            $this->assertSame($this->legacyIds($interests, self::EVERY_TOWN), $results['tags']['eligible'], $label);
            $this->assertSame($results['tags']['eligible'], $results['tags']['picker'], $label);
            foreach ($results['tags']['interests'] as $id => $matched) {
                $category = (int) Destination::find($id)->category_id;
                $this->assertSame(array_values(array_filter($interests, fn ($i) => in_array($category, self::LEGACY_INTEREST_CATEGORIES[$i], true))), $matched, "{$label} #{$id}");
            }
        }

        // Every combination with Nature, Beaches, Adventure or Relaxation (240 of 255) has Alpha
        // destinations: those trips were really planned and compared stop by stop.
        $this->assertSame(240, $tripsWithStops);
        Log::shouldNotHaveReceived('warning', [Mockery::on(fn ($message) => str_contains((string) $message, 'recognized experience tag')), Mockery::any()]);
    }

    private function visited(): array
    {
        return Itinerary::with('items')->latest('id')->first()->items->where('item_type', 'destination')
            ->pluck('destination_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    private function countQueries(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Concerns\ExperienceTagFixtures;
use Tests\TestCase;

/**
 * The trip form's place picker (GET /api/v1/itinerary-places): open to guests, the same places
 * itinerary generation would accept (InterestMatcher), paginated, and nothing internal exposed.
 */
class ItineraryPlacePickerTest extends TestCase
{
    use ExperienceTagFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExperienceFixtures();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function picker(array $interests, array $townKeys, array $extra = [])
    {
        return $this->getJson('/api/v1/itinerary-places?'.http_build_query($extra + ['municipality_ids' => $this->townIds($townKeys), 'interests' => $interests]));
    }

    private function countQueries(callable $run): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $run();

        return $count;
    }

    public function test_guests_get_the_places_matching_their_towns_and_interests(): void
    {
        $response = $this->picker(['beaches'], ['alpha'])->assertOk();

        $this->assertTrue($response->json('sightseeing'));
        $ids = array_column($response->json('data'), 'id');
        sort($ids);
        $this->assertSame($this->legacyIds(['beaches'], ['alpha']), $ids);
        $this->assertSame(['current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url', 'links', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total'],
            collect($response->json())->except(['sightseeing', 'unmatched_interests'])->keys()->sort()->values()->all());
        $this->assertSame([], $response->json('unmatched_interests'));
        $this->assertContains('throttle:60,1', Route::getRoutes()->match(request()->create('/api/v1/itinerary-places'))->gatherMiddleware());
    }

    public function test_it_lists_exactly_what_generation_would_accept(): void
    {
        $this->matchByTags(true);
        foreach ([['beaches'], ['culture'], ['adventure'], ['nature', 'relaxation'], ['history', 'food'], ['beaches', 'culture', 'shopping']] as $interests) {
            foreach ([['alpha'], ['alpha', 'bravo', 'charlie', 'delta']] as $townKeys) {
                $this->assertSame($this->eligibleIds($interests, $townKeys), $this->pickerIds($interests, $townKeys), implode('+', $interests));
            }
        }
        $this->assertContains($this->places['resortCove'], $this->pickerIds(['beaches'], ['alpha']), 'Reviewed, beach tag, Resort category.');
        $this->assertNotContains($this->places['churchBeach'], $this->pickerIds(['beaches'], ['alpha']), 'Reviewed as heritage only.');
    }

    public function test_the_response_shows_only_what_the_form_needs(): void
    {
        $this->matchByTags(true);
        DB::table('destination_images')->insert(['destination_id' => $this->places['resortCove'], 'image_path' => 'destinations/cove.jpg', 'thumbnail_path' => 'destinations/cove-thumb.jpg',
            'is_primary' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $response = $this->picker(self::ALL_INTERESTS, ['alpha', 'bravo', 'charlie', 'delta'], ['per_page' => 100])->assertOk();

        foreach ($response->json('data') as $item) {
            $this->assertSame(['id', 'name', 'category', 'municipality', 'images'], array_keys($item));
            $this->assertSame(['id', 'name'], array_keys($item['category']));
            $this->assertSame(['id', 'name'], array_keys($item['municipality']));
        }
        $cove = collect($response->json('data'))->firstWhere('id', $this->places['resortCove']);
        $this->assertSame('destinations/cove-thumb.jpg', $cove['images'][0]['thumbnail_path']);
        foreach (['experiences_reviewed', 'experience_tags', 'zz_unmapped', '"slug"', 'created_by', 'tourism_priority'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $response->getContent());
        }
    }

    public function test_requests_are_validated_and_bounded(): void
    {
        $thirty = array_merge($this->townIds(['alpha']), range(900001, 900029));
        $this->getJson('/api/v1/itinerary-places?'.http_build_query(['municipality_ids' => $thirty, 'interests' => ['beaches']]))->assertOk();
        $this->getJson('/api/v1/itinerary-places?'.http_build_query(['municipality_ids' => [...$thirty, 900030], 'interests' => ['beaches']]))
            ->assertStatus(422)->assertJsonValidationErrors('municipality_ids');

        $this->picker(['swimming'], ['alpha'])->assertStatus(422)->assertJsonValidationErrors('interests.0');
        $this->picker([], ['alpha'])->assertStatus(422)->assertJsonValidationErrors('interests');
        $this->picker(['beaches', 'beaches'], ['alpha'])->assertStatus(422)->assertJsonValidationErrors('interests.0');
        $this->picker(['beaches'], [])->assertStatus(422)->assertJsonValidationErrors('municipality_ids');
        $this->getJson('/api/v1/itinerary-places?'.http_build_query(['municipality_ids' => [0], 'interests' => ['beaches']]))->assertStatus(422);
        $this->picker(['beaches'], ['alpha'], ['q' => str_repeat('a', 101)])->assertStatus(422)->assertJsonValidationErrors('q');
    }

    public function test_it_names_the_interests_no_place_in_the_towns_matches(): void
    {
        // Delta has only an Eco-Tourism mangrove: nothing for Beaches, Culture or History there.
        $this->picker(['nature', 'beaches', 'culture', 'history', 'food'], ['delta'])->assertOk()
            ->assertJsonPath('unmatched_interests', ['beaches', 'culture', 'history']);
        $this->picker(['beaches', 'culture'], ['alpha', 'bravo'])->assertOk()->assertJsonPath('unmatched_interests', []);
        $this->picker(['food'], ['delta'])->assertOk()->assertJsonPath('unmatched_interests', []);
    }

    public function test_unknown_towns_and_food_or_shopping_list_nothing(): void
    {
        $this->getJson('/api/v1/itinerary-places?'.http_build_query(['municipality_ids' => [999999], 'interests' => ['beaches']]))
            ->assertOk()->assertJsonPath('total', 0)->assertJsonPath('data', []);

        foreach ([['food'], ['shopping'], ['food', 'shopping']] as $interests) {
            $this->picker($interests, ['alpha'])->assertOk()->assertJsonPath('sightseeing', false)->assertJsonPath('total', 0)->assertJsonPath('data', []);
        }
    }

    public function test_unpublished_places_never_appear(): void
    {
        foreach ([false, true] as $on) {
            $this->matchByTags($on);
            foreach ($this->interestCombinations() as $interests) {
                $this->assertNotContains($this->places['hiddenBeach'], $this->pickerIds($interests, ['alpha']));
            }
        }
    }

    public function test_pages_are_stable_bounded_and_complete(): void
    {
        $this->matchByTags(true);
        foreach (range(1, 120) as $n) {
            $this->place("more{$n}", sprintf('Beach %03d', 121 - $n), 1, 'alpha', 12.67, 123.88);
        }
        $all = $this->eligibleIds(['beaches'], ['alpha']);
        $this->assertGreaterThan(100, count($all));

        $this->assertCount(100, $this->picker(['beaches'], ['alpha'], ['per_page' => 500])->assertOk()->json('data'), 'per_page is clamped to 100.');
        $this->assertSame(50, $this->picker(['beaches'], ['alpha'])->json('per_page'), 'Default 50.');

        $first = $this->picker(['beaches'], ['alpha'], ['per_page' => 100, 'page' => 1])->assertOk();
        $second = $this->picker(['beaches'], ['alpha'], ['per_page' => 100, 'page' => 2])->assertOk();
        $this->assertSame(2, $first->json('last_page'));
        $this->assertSame(count($all), $first->json('total'));
        $one = array_column($first->json('data'), 'id');
        $two = array_column($second->json('data'), 'id');
        $this->assertSame([], array_intersect($one, $two), 'No place on two pages.');
        $union = array_merge($one, $two);
        sort($union);
        $this->assertSame($all, $union, 'All pages together are the eligible pool.');

        // Ordered by name, then ID.
        $rows = array_merge($first->json('data'), $second->json('data'));
        $sorted = $rows;
        usort($sorted, fn ($a, $b) => [$a['name'], $a['id']] <=> [$b['name'], $b['id']]);
        $this->assertSame(array_column($sorted, 'id'), array_column($rows, 'id'));
        $this->assertSame(array_column($rows, 'id'), $this->pickerIdsInOrder(), 'The same order every time.');
    }

    /** Page order across both pages, requested again: the same every time. */
    private function pickerIdsInOrder(): array
    {
        return array_merge(
            array_column($this->picker(['beaches'], ['alpha'], ['per_page' => 100, 'page' => 1])->json('data'), 'id'),
            array_column($this->picker(['beaches'], ['alpha'], ['per_page' => 100, 'page' => 2])->json('data'), 'id'),
        );
    }

    public function test_name_search_narrows_the_list(): void
    {
        $this->matchByTags(true);

        $ids = array_column($this->picker(['beaches', 'relaxation'], ['alpha'], ['q' => 'Cove'])->assertOk()->json('data'), 'id');
        $this->assertSame([$this->places['resortCove']], $ids);
        $this->assertSame([], $this->picker(['beaches'], ['alpha'], ['q' => 'Hidden'])->json('data'), 'Search never reaches unpublished places.');
    }

    public function test_query_count_does_not_grow_with_the_number_of_places(): void
    {
        $this->matchByTags(true);
        $few = $this->countQueries(fn () => $this->picker(['beaches'], ['alpha'], ['per_page' => 100])->assertOk());
        foreach (range(1, 60) as $n) {
            $this->place("more{$n}", "Beach {$n}", 1, 'alpha', 12.67, 123.88);
            DB::table('destination_images')->insert(['destination_id' => $this->places["more{$n}"], 'image_path' => "destinations/{$n}.jpg", 'thumbnail_path' => "destinations/{$n}-thumb.jpg", 'is_primary' => true, 'sort_order' => 1,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $many = $this->countQueries(fn () => $this->picker(['beaches'], ['alpha'], ['per_page' => 100])->assertOk());

        $this->assertSame($few, $many);
    }

    public function test_with_the_flag_off_the_picker_never_reads_tags(): void
    {
        $this->matchByTags(false);
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            $sql[] = $query->sql;
        });

        $ids = $this->pickerIds(['beaches', 'culture', 'relaxation'], ['alpha', 'bravo', 'charlie', 'delta']);

        $this->assertSame($this->legacyIds(['beaches', 'culture', 'relaxation'], ['alpha', 'bravo', 'charlie', 'delta']), $ids);
        $this->assertSame([], array_values(array_filter($sql, fn ($q) => str_contains($q, 'experience_tag'))));
    }
}

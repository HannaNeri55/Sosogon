<?php

namespace Tests\Unit;

use App\Services\InterestMatcher;
use Database\Seeders\ExperienceTagSeeder;
use PHPUnit\Framework\TestCase;

/**
 * The interest maps stay consistent with the tag vocabulary: no misspelled slug that would
 * silently match nothing, no tag left without an interest, Food and Shopping without destinations.
 */
class InterestMatcherMappingTest extends TestCase
{
    /** The interest-to-tag map approved for Phase 2D. */
    private const APPROVED = [
        'nature' => ['beach', 'island', 'waterfall', 'spring_river', 'viewpoint', 'hiking', 'forest_mangrove', 'farm', 'wildlife', 'scenic_route', 'camping'],
        'beaches' => ['beach', 'island'],
        'culture' => ['heritage', 'museum', 'religious'],
        'history' => ['heritage', 'museum', 'religious'],
        'adventure' => ['island', 'waterfall', 'water_sports', 'hiking', 'wildlife', 'scenic_route', 'camping'],
        'relaxation' => ['beach', 'island', 'pool', 'spring_river', 'resort', 'recreation'],
        'food' => [],
        'shopping' => [],
    ];

    private function seededSlugs(): array
    {
        return array_column(ExperienceTagSeeder::TAGS, 0);
    }

    public function test_the_tag_map_is_the_approved_one(): void
    {
        $this->assertSame(self::APPROVED, InterestMatcher::INTEREST_TAGS);
        $this->assertSame(array_keys(InterestMatcher::INTEREST_CATEGORIES), array_keys(InterestMatcher::INTEREST_TAGS));
    }

    public function test_every_seeded_slug_is_recognized_and_every_mapped_slug_is_seeded(): void
    {
        $seeded = $this->seededSlugs();
        $recognized = InterestMatcher::recognizedSlugs();

        $this->assertSame([], array_values(array_diff($seeded, $recognized)), 'Seeded tags no interest selects.');
        $this->assertSame([], array_values(array_diff($recognized, $seeded)), 'Mapped slugs that are not in the vocabulary (misspelled?).');
        $this->assertCount(18, array_unique($seeded));
    }

    public function test_all_slugs_are_lowercase_and_unique_per_interest(): void
    {
        foreach ([...$this->seededSlugs(), ...InterestMatcher::recognizedSlugs()] as $slug) {
            $this->assertSame(mb_strtolower($slug), $slug);
        }
        foreach (InterestMatcher::INTEREST_TAGS as $interest => $slugs) {
            $this->assertSame(array_values(array_unique($slugs)), $slugs, $interest);
        }
    }

    public function test_every_tag_selects_a_sightseeing_interest(): void
    {
        foreach ($this->seededSlugs() as $slug) {
            $interests = array_keys(array_filter(InterestMatcher::INTEREST_TAGS, fn ($slugs) => in_array($slug, $slugs, true)));
            $this->assertNotSame([], array_diff($interests, ['food', 'shopping']), "{$slug} selects no sightseeing interest.");
        }
    }

    public function test_food_and_shopping_select_no_destination_and_the_other_interests_do_in_both_maps(): void
    {
        $matcher = new InterestMatcher;
        foreach (InterestMatcher::INTEREST_CATEGORIES as $interest => $categories) {
            $sightseeing = ! in_array($interest, ['food', 'shopping'], true);
            $this->assertSame($sightseeing, $categories !== [], $interest);
            $this->assertSame($sightseeing, InterestMatcher::INTEREST_TAGS[$interest] !== [], $interest);
            $this->assertSame($sightseeing, $matcher->selectsDestinations($interest), $interest);
        }
        $this->assertFalse($matcher->selectsDestinations('unknown'));
    }
}

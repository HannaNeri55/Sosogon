<?php

namespace Tests\Feature;

use App\Models\ExperienceTag;
use App\Services\InterestMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\ExperienceTagFixtures;
use Tests\TestCase;

/**
 * `php artisan itinerary:interest-diff`: the read-only report comparing category matching with
 * experience-tag matching, the human review step before ITINERARY_MATCH_BY_TAGS is turned on.
 */
class ItineraryInterestDiffCommandTest extends TestCase
{
    use ExperienceTagFixtures;
    use RefreshDatabase;

    private const TABLES = ['destinations', 'destination_experience_tag', 'experience_tags', 'categories', 'municipalities'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExperienceFixtures();
    }

    /** Asserts some line of the output contains every one of $parts. */
    private function assertRow(string $output, array $parts): void
    {
        $found = collect(explode("\n", $output))->contains(fn ($line) => collect($parts)->every(fn ($part) => str_contains($line, (string) $part)));
        $this->assertTrue($found, 'No output line with: '.implode(' | ', $parts)."\n".$output);
    }

    private function section(string $output, string $title): string
    {
        $start = strpos($output, $title);
        $this->assertNotFalse($start, "Section {$title} missing.");
        $rest = substr($output, $start + strlen($title));
        // Up to the next section title (a line that doesn't start with a space, "+" or "|").
        preg_match('/\n(?=[A-Z])/', $rest, $next, PREG_OFFSET_CAPTURE);

        return $next ? substr($rest, 0, $next[0][1]) : $rest;
    }

    public function test_it_reports_every_difference_for_human_review_and_changes_nothing(): void
    {
        $before = collect(self::TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()]);
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (! preg_match('/^\s*select\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->assertSame(0, Artisan::call('itinerary:interest-diff'));
        $output = Artisan::output();

        $this->assertStringContainsString('ITINERARY_MATCH_BY_TAGS is currently OFF', $output);
        // Per interest: added and removed destinations, with their town.
        $changes = $this->section($output, 'Destination changes');
        $this->assertRow($changes, ['beaches', 'added', $this->places['resortCove'], 'Reviewed Resort Cove', 'Alpha', 'Beach, Resort (day use)']);
        $this->assertRow($changes, ['beaches', 'removed', 'Reviewed Church Beach', 'Alpha', 'Heritage site']);
        $this->assertRow($changes, ['beaches', 'removed', 'Reviewed Mixed Island', 'zz_unmapped (unrecognized)']);
        $this->assertRow($changes, ['adventure', 'removed', 'Reviewed Viewpoint Hill']);
        $this->assertRow($changes, ['adventure', 'removed', 'Bravo Spring', 'Bravo']);
        $this->assertRow($changes, ['adventure', 'removed', 'Delta Mangrove', 'Delta']);
        $this->assertRow($changes, ['culture', 'added', 'Reviewed Church Beach']);
        $this->assertRow($changes, ['relaxation', 'added', 'Bravo Spring']);
        $this->assertStringNotContainsString('Hidden Reviewed Beach', $output, 'Unpublished places are not part of the comparison.');
        $this->assertStringNotContainsString('Reviewed Bare Hill', $changes, 'A category fallback is no change.');

        // Per municipality, and the towns that would lose their last place for an interest.
        $towns = $this->section($output, 'Municipality changes');
        $this->assertRow($towns, ['culture', 'Alpha', '| 0', '| 2', '+2']);
        $this->assertRow($towns, ['adventure', 'Delta', '| 1', '| 0', '-1']);
        $this->assertRow($towns, ['relaxation', 'Bravo', '| 0', '| 1', '+1']);
        $lost = $this->section($output, 'Municipalities that would lose their last destination for an interest');
        $this->assertRow($lost, ['adventure', 'Delta', '1']);
        $this->assertStringNotContainsString('Alpha', $lost);

        $this->assertRow($this->section($output, 'Reviewed destinations with no tags'), ['Reviewed Bare Hill', 'Alpha', 'published']);
        $this->assertRow($this->section($output, 'Reviewed destinations with no recognized tag'), ['Reviewed Odd Park', 'zz_unmapped (unrecognized)']);
        $this->assertRow($this->section($output, 'Stored tags the interest map does not recognize'), ['zz_unmapped', 'Unmapped test tag', '2']);

        // Read-only.
        $this->assertSame([], $writes);
        $this->assertEquals($before, collect(self::TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()]));
        $this->assertFalse(config('services.itinerary.match_by_tags'));
    }

    public function test_with_nothing_reviewed_it_reports_no_difference(): void
    {
        $this->unreviewAll();
        $unmapped = ExperienceTag::where('slug', 'zz_unmapped')->value('id');
        DB::table('destination_experience_tag')->where('experience_tag_id', $unmapped)->delete();
        DB::table('experience_tags')->where('id', $unmapped)->delete();

        Artisan::call('itinerary:interest-diff');
        $output = Artisan::output();

        $this->assertStringContainsString("Destination changes\n  No destination changes.", str_replace("\r", '', $output));
        $this->assertStringContainsString("Municipality changes\n  No municipality changes.", str_replace("\r", '', $output));
    }

    public function test_the_seeded_vocabulary_is_fully_recognized(): void
    {
        $seeded = ExperienceTag::where('slug', '!=', 'zz_unmapped')->pluck('slug')->sort()->values()->all();
        $recognized = InterestMatcher::recognizedSlugs();
        sort($recognized);

        $this->assertCount(18, $seeded);
        $this->assertSame($recognized, $seeded);
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Destination;
use App\Models\ExperienceTag;
use App\Models\Municipality;
use App\Services\InterestMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only review gate before ITINERARY_MATCH_BY_TAGS is turned on: compares, for each sightseeing
 * interest, which published destinations category matching selects with which experience-tag
 * matching would select (InterestMatcher), by destination and by municipality, and lists the
 * reviewed destinations that would fall back to their category and the stored tags the interest
 * map doesn't know. It only reads the configured database: it never tags, reviews, reclassifies
 * or publishes anything, and never changes the flag.
 */
class ItineraryInterestDiff extends Command
{
    protected $signature = 'itinerary:interest-diff';

    protected $description = 'Compare category and experience-tag interest matching (read-only) before enabling ITINERARY_MATCH_BY_TAGS';

    public function handle(InterestMatcher $matcher): int
    {
        $byCategory = $matcher->usingTags(false);
        $byTags = $matcher->usingTags(true);
        $interests = array_keys(array_filter(InterestMatcher::INTEREST_CATEGORIES));
        $towns = Municipality::pluck('name', 'id');

        $this->line('Database: '.DB::connection()->getName().' / '.DB::connection()->getDatabaseName());
        $this->line('ITINERARY_MATCH_BY_TAGS is currently '.($matcher->byTags() ? 'ON' : 'OFF').'. This report compares both rules and changes nothing.');
        $this->line('Eligibility as in itinerary generation: published destinations; town selection also needs map coordinates.');

        $summary = [];
        $changes = [];
        $townRows = [];
        $lostLast = [];
        foreach ($interests as $interest) {
            $before = $this->matching($byCategory, $interest);
            $after = $this->matching($byTags, $interest);
            $added = $after->diffKeys($before);
            $removed = $before->diffKeys($after);
            $summary[] = [$interest, $before->count(), $after->count(), $added->count(), $removed->count()];

            foreach ([['added', $added], ['removed', $removed]] as [$change, $rows]) {
                foreach ($rows as $destination) {
                    $changes[] = [$interest, $change, $destination->id, $destination->name, $towns[$destination->municipality_id] ?? '—', $this->tagNames($destination)];
                }
            }

            $beforeByTown = $before->countBy('municipality_id');
            $afterByTown = $after->countBy('municipality_id');
            foreach ($beforeByTown->keys()->merge($afterByTown->keys())->unique()->sortBy(fn ($id) => $towns[$id] ?? '') as $town) {
                [$was, $now] = [$beforeByTown[$town] ?? 0, $afterByTown[$town] ?? 0];
                if ($was === $now && $added->where('municipality_id', $town)->isEmpty()) {
                    continue;
                }
                $townRows[] = [$interest, $towns[$town] ?? "#{$town}", $was, $now, sprintf('%+d', $now - $was)];
                if ($was > 0 && $now === 0) {
                    $lostLast[] = [$interest, $towns[$town] ?? "#{$town}", $was];
                }
            }
        }

        $this->newLine();
        $this->info('Destinations per interest');
        $this->table(['Interest', 'By category', 'By tags', 'Added', 'Removed'], $summary);

        $this->section('Destination changes', ['Interest', 'Change', 'ID', 'Destination', 'Municipality', 'Confirmed tags'], $changes, 'No destination changes.');
        $this->section('Municipality changes', ['Interest', 'Municipality', 'By category', 'By tags', 'Change'], $townRows, 'No municipality changes.');
        $this->section('Municipalities that would lose their last destination for an interest', ['Interest', 'Municipality', 'By category'], $lostLast, 'None.');

        $reviewed = Destination::whereNotNull('experiences_reviewed_at')->with('experienceTags:experience_tags.id,experience_tags.slug,experience_tags.name')
            ->orderBy('name')->get(['id', 'name', 'status', 'municipality_id', 'experiences_reviewed_at']);
        $row = fn ($d) => [$d->id, $d->name, $towns[$d->municipality_id] ?? '—', $d->status, $this->tagNames($d, false)];
        $this->section('Reviewed destinations with no tags (matched by category)', ['ID', 'Destination', 'Municipality', 'Status', 'Tags'],
            $reviewed->filter(fn ($d) => $d->experienceTags->isEmpty())->map($row)->values()->all(), 'None.');
        $this->section('Reviewed destinations with no recognized tag (matched by category)', ['ID', 'Destination', 'Municipality', 'Status', 'Tags'],
            $reviewed->filter(fn ($d) => $d->experienceTags->isNotEmpty() && $this->recognized($d) === [])->map($row)->values()->all(), 'None.');

        $unknown = ExperienceTag::withCount('destinations')->orderBy('slug')->get()
            ->reject(fn ($tag) => in_array(mb_strtolower($tag->slug), InterestMatcher::recognizedSlugs(), true))
            ->map(fn ($tag) => [$tag->slug, $tag->name, $tag->destinations_count])->values()->all();
        $this->section('Stored tags the interest map does not recognize', ['Slug', 'Name', 'Destinations using it'], $unknown, 'None.');

        $this->newLine();
        $this->line('Read-only: no destination, tag, review mark, category, priority, status or setting was changed.');

        return self::SUCCESS;
    }

    /** The published destinations one rule selects for $interest, keyed by ID. */
    private function matching(InterestMatcher $matcher, string $interest): Collection
    {
        return Destination::published()
            ->tap(fn ($query) => $matcher->constrain($query, [$interest]))
            ->with('experienceTags:experience_tags.id,experience_tags.slug,experience_tags.name')
            ->orderBy('name')
            ->get(['id', 'name', 'municipality_id', 'experiences_reviewed_at'])
            ->keyBy('id');
    }

    /** A reviewed destination's tag names (with unrecognized slugs marked), or "not reviewed". */
    private function tagNames(Destination $destination, bool $markUnreviewed = true): string
    {
        if ($markUnreviewed && $destination->experiences_reviewed_at === null) {
            return 'not reviewed';
        }

        return $destination->experienceTags
            ->map(fn ($tag) => in_array(mb_strtolower($tag->slug), InterestMatcher::recognizedSlugs(), true) ? $tag->name : "{$tag->slug} (unrecognized)")
            ->implode(', ') ?: '—';
    }

    private function recognized(Destination $destination): array
    {
        return array_intersect($destination->experienceTags->map(fn ($tag) => mb_strtolower($tag->slug))->all(), InterestMatcher::recognizedSlugs());
    }

    private function section(string $title, array $headers, array $rows, string $empty): void
    {
        $this->newLine();
        $this->info($title);
        $rows === [] ? $this->line("  {$empty}") : $this->table($headers, $rows);
    }
}

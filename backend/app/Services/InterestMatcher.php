<?php

namespace App\Services;

use App\Models\Destination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Which destinations a tourist's interests select: the one place that decides it, for the
 * itinerary planner, the trip form's place picker and the preferred-place check.
 *
 * By default (services.itinerary.match_by_tags off) a destination matches through its primary
 * category, exactly as the planner always has. With the flag on, a destination the tourism office
 * has reviewed (experiences_reviewed_at set) matches through its confirmed experience tags instead,
 * and only through them; every other destination keeps matching by category. A reviewed
 * destination falls back to its category only when it has no tag the map below knows (none at
 * all, or only unrecognized ones), and that is logged so it can be fixed.
 */
class InterestMatcher
{
    /**
     * Destination category IDs each interest selects (the legacy rule, used for every unreviewed
     * destination). Food and Shopping select no destination: they're planned from approved
     * restaurants and souvenir shops instead.
     */
    public const INTEREST_CATEGORIES = [
        'nature' => [1, 2, 3, 7, 8],      // Beach, Mountain, Waterfall, Eco-Tourism, Island
        'beaches' => [1, 8],              // Beach, Island
        'culture' => [4, 5],              // Historical, Church
        'history' => [4, 5],
        'adventure' => [2, 3, 7, 8],
        'relaxation' => [1, 6, 8, 9],     // Beach, Park, Island, Resort (day-use resorts)
        'food' => [],
        'shopping' => [],
    ];

    /**
     * Experience tags each interest selects, for reviewed destinations (approved in Phase 2D).
     * Every tag of the vocabulary (ExperienceTagSeeder) selects at least one sightseeing interest,
     * and Food and Shopping stay without tags.
     */
    public const INTEREST_TAGS = [
        'nature' => ['beach', 'island', 'waterfall', 'spring_river', 'viewpoint', 'hiking', 'forest_mangrove', 'farm', 'wildlife', 'scenic_route', 'camping'],
        'beaches' => ['beach', 'island'],
        'culture' => ['heritage', 'museum', 'religious'],
        'history' => ['heritage', 'museum', 'religious'],
        'adventure' => ['island', 'waterfall', 'water_sports', 'hiking', 'wildlife', 'scenic_route', 'camping'],
        'relaxation' => ['beach', 'island', 'pool', 'spring_river', 'resort', 'recreation'],
        'food' => [],
        'shopping' => [],
    ];

    /** Why a reviewed destination is matched by its category after all. */
    public const FALLBACK_NO_TAGS = 'no_tags';

    public const FALLBACK_UNRECOGNIZED_ONLY = 'unrecognized_only';

    /** Set by usingTags(): a fixed mode instead of the configuration (the interest diff report). */
    private ?bool $forcedMode = null;

    /** The same matcher, matching by tags ($on) or by category only, whatever the configuration says. */
    public function usingTags(bool $on): static
    {
        $copy = clone $this;
        $copy->forcedMode = $on;

        return $copy;
    }

    /** Whether reviewed destinations match through their experience tags (services.itinerary.match_by_tags). */
    public function byTags(): bool
    {
        return $this->forcedMode ?? (bool) config('services.itinerary.match_by_tags', false);
    }

    /** Every tag slug the interest map knows; any other tag never selects a destination. */
    public static function recognizedSlugs(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::INTEREST_TAGS))));
    }

    /** Whether an interest selects destinations at all (not Food or Shopping). */
    public function selectsDestinations(string $interest): bool
    {
        return (self::INTEREST_CATEGORIES[$interest] ?? []) !== [];
    }

    /**
     * The selected interests that select destinations, in the order given.
     *
     * @return array<int, string>
     */
    public function sightseeing(array $interests): array
    {
        return array_values(array_filter(array_values($interests), fn ($interest) => $this->selectsDestinations((string) $interest)));
    }

    /** The category IDs the selected interests select (legacy rule). */
    public function categoryIds(array $interests): array
    {
        return array_values(array_unique(array_merge(...array_map(
            fn ($interest) => self::INTEREST_CATEGORIES[$interest] ?? [],
            array_values($interests)
        ))));
    }

    /** The tag slugs the selected interests select. */
    public function tagSlugs(array $interests): array
    {
        return array_values(array_unique(array_merge(...array_map(
            fn ($interest) => self::INTEREST_TAGS[$interest] ?? [],
            array_values($interests)
        ))));
    }

    /**
     * Narrows a destinations query to the ones matching at least one selected interest. The
     * conditions are grouped, so the query's other constraints (published, towns…) always apply.
     * With no sightseeing interest nothing matches; callers handle Food and Shopping before this.
     */
    public function constrain(Builder $query, array $interests): Builder
    {
        $categoryIds = $this->categoryIds($interests);
        if ($categoryIds === []) {
            return $query->whereRaw('1 = 0');
        }
        if (! $this->byTags()) {
            return $query->whereIn('category_id', $categoryIds);
        }

        $slugs = $this->tagSlugs($interests);

        return $query->where(function ($match) use ($categoryIds, $slugs) {
            // Unreviewed, or reviewed without any recognized tag: by category.
            $match->where(function ($byCategory) use ($categoryIds) {
                $byCategory->whereIn('destinations.category_id', $categoryIds)
                    ->where(function ($unreviewed) {
                        $unreviewed->whereNull('destinations.experiences_reviewed_at')
                            ->orWhereNotExists(fn ($tags) => $this->withTag($tags, self::recognizedSlugs()));
                    });
            });
            // Reviewed with a recognized tag: by its tags only.
            if ($slugs !== []) {
                $match->orWhere(function ($byTags) use ($slugs) {
                    $byTags->whereNotNull('destinations.experiences_reviewed_at')
                        ->whereExists(fn ($tags) => $this->withTag($tags, $slugs));
                });
            }
        });
    }

    /** A subquery for "the destination has a tag with one of these slugs" (compared in lowercase). */
    private function withTag(QueryBuilder $query, array $slugs): QueryBuilder
    {
        return $query->select(DB::raw(1))
            ->from('destination_experience_tag as matched_pivot')
            ->join('experience_tags as matched_tag', 'matched_tag.id', '=', 'matched_pivot.experience_tag_id')
            ->whereColumn('matched_pivot.destination_id', 'destinations.id')
            ->whereIn(DB::raw('lower(matched_tag.slug)'), $slugs);
    }

    /**
     * What a destinations query needs loaded for interestsOf() and confirmedExperiences(): the
     * review column and every tag (not only recognized ones, or a destination with recognized and
     * unrecognized tags would look like one with unrecognized tags only). Nothing with the flag off.
     */
    public function prepare(Builder $query): Builder
    {
        if (! $this->byTags()) {
            return $query;
        }

        return $query->addSelect('destinations.experiences_reviewed_at')
            ->with('experienceTags:experience_tags.id,experience_tags.slug,experience_tags.name');
    }

    /**
     * The selected sightseeing interests a destination represents, by the same rule as constrain().
     *
     * @return array<int, string>
     */
    public function interestsOf(Destination $destination, array $interests): array
    {
        $selected = $this->sightseeing($interests);
        $recognized = $this->byTags() ? $this->recognizedTags($destination) : null;

        if ($recognized === null || $recognized === []) {
            return array_values(array_filter($selected, fn ($interest) => in_array((int) $destination->category_id, self::INTEREST_CATEGORIES[$interest], true)));
        }

        return array_values(array_filter($selected, fn ($interest) => array_intersect(self::INTEREST_TAGS[$interest], $recognized) !== []));
    }

    /**
     * Why a reviewed destination is matched by category after all (FALLBACK_NO_TAGS or
     * FALLBACK_UNRECOGNIZED_ONLY), or null when it isn't (unreviewed, matched by tags, or flag off).
     */
    public function fallbackReason(Destination $destination): ?string
    {
        // Unreviewed (null) or matched by its recognized tags: no fallback.
        if (! $this->byTags() || $this->recognizedTags($destination) !== []) {
            return null;
        }

        return $destination->experienceTags->isEmpty() ? self::FALLBACK_NO_TAGS : self::FALLBACK_UNRECOGNIZED_ONLY;
    }

    /**
     * Names of a reviewed destination's recognized tags, for telling the AI what the tourism office
     * confirmed. Empty for unreviewed destinations (their tags are drafts) and with the flag off.
     *
     * @return array<int, string>
     */
    public function confirmedExperiences(Destination $destination): array
    {
        if (! $this->byTags() || ($this->recognizedTags($destination) ?? []) === []) {
            return [];
        }

        return $destination->experienceTags
            ->filter(fn ($tag) => in_array(mb_strtolower((string) $tag->slug), self::recognizedSlugs(), true))
            ->pluck('name')->values()->all();
    }

    /** Logs, once, the reviewed destinations among $destinations that were matched by category after all. */
    public function warnAboutFallbacks(Collection $destinations): void
    {
        $fallbacks = $destinations->groupBy(fn ($destination) => $this->fallbackReason($destination) ?? '')->forget('')
            ->map(fn ($group) => $group->pluck('id')->map(fn ($id) => (int) $id)->values()->all());

        if ($fallbacks->isNotEmpty()) {
            Log::warning('Reviewed destinations without a recognized experience tag were matched by their category', $fallbacks->all());
        }
    }

    /**
     * A reviewed destination's recognized tag slugs (lowercase); [] when it has none; null when
     * it isn't reviewed. Throws when the query didn't load what this needs (see prepare()).
     */
    private function recognizedTags(Destination $destination): ?array
    {
        if (! array_key_exists('experiences_reviewed_at', $destination->getAttributes())) {
            throw new LogicException("Destination {$destination->id} was loaded without experiences_reviewed_at; use InterestMatcher::prepare().");
        }
        if ($destination->experiences_reviewed_at === null) {
            return null;
        }
        if (! $destination->relationLoaded('experienceTags')) {
            throw new LogicException("Destination {$destination->id} was loaded without its experience tags; use InterestMatcher::prepare().");
        }

        return array_values(array_intersect(
            $destination->experienceTags->map(fn ($tag) => mb_strtolower((string) $tag->slug))->all(),
            self::recognizedSlugs()
        ));
    }
}

<?php

namespace App\Services;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\RetailShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Data problems in public listings that make trips worse or mislead tourists: no map location or
 * opening hours the trip planner can read, no activities or activity lengths, an activity listed
 * twice, a name that looks mistyped, a description saying the place isn't a tourist spot,
 * placeholder contact details… Destinations are fixed by the Tourism Office; businesses by their
 * owners. Read-only: nothing is ever changed here.
 */
class ListingDataChecks
{
    public const TYPES = [
        'destination' => 'Destination',
        'accommodation' => 'Accommodation',
        'food_beverage' => 'Food & Beverage',
        'retail_shop' => 'Shop',
    ];

    public const SEVERITIES = ['high' => 'Fix first', 'medium' => 'Should fix', 'low' => 'Nice to fix'];

    /** Short words a listing name may keep in lowercase ("Museo de Sorsogon", "Isla de Blas"). */
    private const SMALL_WORDS = ['and', 'of', 'the', 'de', 'del', 'la', 'las', 'los', 'sa', 'ng', 'at', 'in', 'on', 'by', 'y', 'na', 'ni', 'for', 'to', 'n', 'a', 'an'];

    public function __construct(private ItineraryService $planner) {}

    /**
     * Every problem found in the listings of $townIds (all towns when null), most serious first.
     *
     * @param  array<int, int>|null  $townIds
     * @return Collection<int, array{type: string, id: int, name: string, municipality_id: ?int, municipality: ?string, severity: string, code: string, message: string, fixed_by: string}>
     */
    public function run(?array $townIds = null): Collection
    {
        $inTowns = fn (Builder $query) => $query->when($townIds !== null, fn ($q) => $q->whereIn('municipality_id', $townIds));

        $issues = collect();
        $destinations = Destination::published()->tap($inTowns)->with(['municipality:id,name', 'category:id,name', 'activities'])->withCount(['images', 'fees'])->get();
        // Approved stays, to spot a resort listed both ways but not linked.
        $stays = Accommodation::approved()->get(['id', 'name', 'municipality_id']);
        $linked = Destination::whereNotNull('accommodation_id')->pluck('accommodation_id')->map(fn ($id) => (int) $id)->all();
        foreach ($destinations as $place) {
            $issues = $issues->concat($this->destination($place, $stays->reject(fn ($stay) => in_array((int) $stay->id, $linked, true))));
        }
        $businesses = [
            'accommodation' => Accommodation::approved()->tap($inTowns)->with('municipality:id,name')->withCount(['images', 'rooms as priced_rooms_count' => fn ($q) => $q->where('status', 'approved')->where('price', '>', 0)]),
            'food_beverage' => FoodBeverage::approved()->tap($inTowns)->with('municipality:id,name')->withCount(['images', 'menuItems as approved_menu_count' => fn ($q) => $q->where('status', 'approved')]),
            'retail_shop' => RetailShop::approved()->tap($inTowns)->with('municipality:id,name')->withCount(['images', 'products as approved_products_count' => fn ($q) => $q->where('status', 'approved')]),
        ];
        foreach ($businesses as $type => $query) {
            foreach ($query->get() as $listing) {
                $issues = $issues->concat($this->business($type, $listing));
            }
        }

        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];

        return $issues->sortBy(fn ($i) => [$rank[$i['severity']], $i['municipality'] ?? '', $i['name'], $i['code']])->values();
    }

    private function destination(Destination $place, Collection $unlinkedStays): array
    {
        $issues = [];
        $add = function (string $severity, string $code, string $message) use (&$issues, $place) {
            $issues[] = $this->issue('destination', $place, $severity, $code, $message, 'admin');
        };

        if ($place->latitude === null || $place->longitude === null) {
            $add('high', 'no_location', 'No map location: trips can\'t include it and the map can\'t show it.');
        }
        if (trim((string) $place->operating_hours) === '') {
            $add('high', 'no_hours', 'No opening hours: trips use a guess for when it\'s open.');
        } elseif (! $this->planner->readsHours($place->operating_hours)) {
            $add('medium', 'unreadable_hours', "The opening hours \"{$place->operating_hours}\" can't be read by the trip planner. Write them like \"8:00 AM - 5:00 PM\".");
        }
        if (preg_match('/rather than an? [\w ]*(attraction|destination|spot)|not an? (tourist|sightseeing|tourism) (attraction|destination|spot)/i', (string) $place->description)) {
            $standing = $place->tourism_priority ? ' and is marked '.Str::of($place->tourism_priority)->replace('_', ' ')->title() : '';
            $add('high', 'not_an_attraction', "Its description says it isn't a tourist attraction, but it is published{$standing}. Unpublish it, or fix its category and priority.");
        }

        $activities = $place->activities;
        if ($activities->isEmpty()) {
            $add('high', 'no_activities', 'No activities listed: trips can\'t plan what to do there.');
        } else {
            $unlisted = $activities->filter(fn ($a) => ! ($a->duration_minutes > 0))->pluck('name');
            if ($unlisted->isNotEmpty()) {
                $add('medium', 'activity_no_length', 'Activities with no length: '.$this->list($unlisted).'. Trips have to guess how long they take.');
            }
            $twice = $activities->groupBy(fn ($a) => mb_strtolower(trim((string) $a->name)))->filter(fn ($group) => $group->count() > 1)->map(fn ($group) => $group->first()->name);
            if ($twice->isNotEmpty()) {
                $add('medium', 'duplicate_activity', 'Listed more than once: '.$this->list($twice->values()).'. Keep one of each.');
            }
            $noTime = $activities->filter(fn ($a) => blank($a->best_time_to_visit))->pluck('name');
            if ($noTime->isNotEmpty()) {
                $add('low', 'activity_no_best_time', 'No best time of day for: '.$this->list($noTime).'. Trips may schedule them at a poor time.');
            }
        }
        if ((int) $place->fees_count === 0 && $place->entrance_fee === null) {
            $add('low', 'no_fee', 'No entrance fee listed, not even "free": trips show an estimate.');
        }
        if (mb_strlen(trim(strip_tags((string) $place->description))) < 80) {
            $add('low', 'short_description', 'The description is very short: tourists learn little about the place.');
        }
        if ($place->accommodation_id === null && ($twin = $this->sameNameStay($place, $unlinkedStays))) {
            $add('medium', 'unlinked_twin', "It looks like the same place as the accommodation \"{$twin->name}\". If it is, link them in its edit form (\"Also an accommodation on SOSOGON\"), so trips don't send its overnight guests on a separate visit.");
        }
        $this->common($place, $add);

        return $issues;
    }

    /** An accommodation in the same municipality whose name is the destination's, apart from words like "resort" or "hotel" (see below). */
    private function sameNameStay(Destination $place, Collection $stays): ?Model
    {
        $core = fn (string $name) => trim(preg_replace(['/[^\p{L}\p{N} ]+/u', '/\b(the|resort|hotel|inn|beach|and|place|spring|springs)\b/u', '/\s+/'], ['', ' ', ' '], mb_strtolower($name)));
        $mine = $core((string) $place->name);
        if (mb_strlen($mine) < 4) {
            return null;
        }

        return $stays->first(fn ($stay) => (int) $stay->municipality_id === (int) $place->municipality_id
            && ($theirs = $core((string) $stay->name)) !== '' && mb_strlen($theirs) >= 4
            // The same name; or, for a resort, one name inside the other ("Isabel's" / "Isabel's Resort and Rooms").
            // A beach that has a resort named after it ("Subic Beach" / "Arcadia Crystal Subic Beach Resort") isn't one.
            && ($theirs === $mine || ($place->category?->name === 'Resort' && (str_contains($theirs, $mine) || str_contains($mine, $theirs)))));
    }

    private function business(string $type, Model $listing): array
    {
        $issues = [];
        $add = function (string $severity, string $code, string $message) use (&$issues, $type, $listing) {
            $issues[] = $this->issue($type, $listing, $severity, $code, $message, 'owner');
        };

        if ($listing->latitude === null || $listing->longitude === null) {
            $add('high', 'no_location', 'No map location: trips can\'t suggest it and the map can\'t show it.');
        }
        if (preg_match('/\b000-\d{4}\b/', (string) $listing->contact_number) || Str::contains((string) $listing->email.' '.$listing->website, ['example.com', 'example.org'])) {
            $add('high', 'placeholder_contact', 'Its contact details are placeholders (a 000 phone number or an example.com address), so tourists can\'t reach it.');
        }
        if ($type !== 'accommodation') {
            if (trim((string) $listing->operating_hours) === '') {
                $add('medium', 'no_hours', 'No opening hours: trips guess when it\'s open.');
            } elseif (! $this->planner->readsHours($listing->operating_hours)) {
                $add('medium', 'unreadable_hours', "The opening hours \"{$listing->operating_hours}\" can't be read by the trip planner.");
            }
        }
        match ($type) {
            'accommodation' => (int) $listing->priced_rooms_count === 0
                ? $add('high', 'no_priced_rooms', 'No approved room with a price: trips can\'t show what a night costs.') : null,
            'food_beverage' => $this->foodChecks($listing, $add),
            'retail_shop' => (int) $listing->approved_products_count === 0
                ? $add('low', 'no_products', 'No approved products: trips can\'t suggest what to buy here.') : null,
        };
        $this->common($listing, $add);

        return $issues;
    }

    private function foodChecks(FoodBeverage $place, callable $add): void
    {
        if (blank($place->cuisine_type)) {
            $add('medium', 'no_cuisine', 'No cuisine type: trips can\'t match it to a food preference (seafood, Bicolano…).');
        }
        if (blank($place->price_range)) {
            $add('medium', 'no_price_range', 'No price range: trips can\'t match it to the tourist\'s budget.');
        }
        if ((int) $place->approved_menu_count === 0) {
            $add('low', 'no_menu', 'No approved menu items: trips can\'t suggest dishes or prices.');
        }
    }

    /** Checks every kind of listing shares: the name and photos. */
    private function common(Model $listing, callable $add): void
    {
        $name = (string) $listing->name;
        $typos = collect(preg_split('/[\s\-–—,()\/&]+/u', $name, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn ($word) => mb_strlen($word) >= 3 && preg_match('/^\p{Ll}/u', $word) && ! in_array(mb_strtolower($word), self::SMALL_WORDS, true));
        // A missing space before a small word ("St. JamesThe greater"); styled brand names
        // ("LagoLand", "RedDoorz") are left alone.
        $joined = (bool) preg_match('/\p{Ll}(The|And|Of|De|Del|Sa|Ng|In|At)\b/u', $name);
        $noVowel = collect(preg_split('/[^\p{L}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY))
            ->first(fn ($word) => mb_strlen($word) >= 4 && ! preg_match('/[aeiouy]/i', $word));
        if ($joined || $noVowel !== null || preg_match('/\s{2,}/', $name)) {
            $why = match (true) {
                $joined => 'two words look joined together',
                $noVowel !== null => "\"{$noVowel}\" looks misspelled",
                default => 'it has extra spaces',
            };
            $add('medium', 'name_typo', "The name \"{$name}\" may have a typo: {$why}. Tourists see this name on every page and PDF.");
        } elseif ($typos->isNotEmpty()) {
            $add('low', 'name_capitals', "The name \"{$name}\" has words starting with a small letter (".$this->list($typos->values()).'). Capitalize them so it looks right on cards and PDFs.');
        }
        if ((int) $listing->images_count === 0) {
            $add('medium', 'no_photo', 'No photo: its card and trip stop show a blank image.');
        }
    }

    private function issue(string $type, Model $listing, string $severity, string $code, string $message, string $fixedBy): array
    {
        return [
            'type' => $type,
            'id' => (int) $listing->id,
            'name' => (string) $listing->name,
            'municipality_id' => $listing->municipality_id ? (int) $listing->municipality_id : null,
            'municipality' => $listing->municipality?->name,
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'fixed_by' => $fixedBy,
        ];
    }

    private function list(Collection $names): string
    {
        $names = $names->map(fn ($n) => (string) $n)->values();

        return $names->count() < 2 ? (string) $names->first() : $names->slice(0, -1)->implode(', ').' and '.$names->last();
    }
}

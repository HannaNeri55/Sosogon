<?php

namespace App\Services;

use App\Contracts\ItineraryAI;
use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\ItineraryStay;
use App\Models\Municipality;
use App\Models\RetailShop;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Builds a trip over one or more towns, with one recommended stay per town (central to the
 * destinations visited there); each night is spent at the stay of the town that day ends in.
 * The AI picks and orders each day's destinations; this service enforces the rules a local
 * planner would follow: a few attractions per day by pace, close together, varied (never two
 * swimming stops in a day), open that day and never repeated, topped up from unused nearby
 * places. It then lays out the day: arrival and check-in on Day 1, check-out when leaving a
 * stay, each destination's own listed activities, meals in their usual windows near where the
 * tourist is, rest at the stay instead of long gaps, and souvenir shopping on the last day.
 */
class ItineraryService
{
    /** A preference-matching listing wins if it is at most this much farther than the nearest one. */
    private const PREFERENCE_RADIUS_KM = 3;

    /** "Near" for breakfast (the stay), lunch (the destination) and dinner (where the day ends). */
    private const NEARBY_KM = 3;

    /** Largest detour accepted for a meal on the way between two places. */
    private const MAX_DETOUR_KM = 10;

    /** Souvenir shops this close to the day's destination are added that day (LAST_DAY_SHOP_KM on the last day). */
    private const SHOP_RADIUS_KM = 5;

    private const LAST_DAY_SHOP_KM = 15;

    /**
     * Destination categories each interest selects. Food and Shopping select none: they're
     * planned from approved restaurants and souvenir shops instead (see foodScore() and the
     * daily souvenir stop).
     */
    private const INTEREST_CATEGORIES = [
        'nature' => [1, 2, 3, 7, 8],      // Beach, Mountain, Waterfall, Eco-Tourism, Island
        'beaches' => [1, 8],              // Beach, Island
        'culture' => [4, 5],              // Historical, Church
        'history' => [4, 5],
        'adventure' => [2, 3, 7, 8],
        'relaxation' => [1, 6, 8, 9],     // Beach, Park, Island, Resort (day-use resorts)
        'food' => [],
        'shopping' => [],
    ];

    /** With Food selected, a verified restaurant this far away beats a meal at the stay. */
    private const FOOD_NEARBY_KM = 6;

    private const SOUVENIR_KEYWORDS = ['souvenir', 'pasalubong', 'craft', 'gift', 'otop', 'pili', 'native'];

    private const BREAKFAST_MINUTES = 45;

    private const MEAL_MINUTES = 60;

    private const SHOPPING_MINUTES = 60;

    private const MERIENDA_MINUTES = 30;

    /** Attractions per day by pace; the arrival day has one fewer. */
    private const DAY_STOPS = ['relaxed' => 2, 'moderate' => 3, 'packed' => 4];

    /** Farthest an attraction may be from the day's first one (minutes of travel). */
    private const MAX_DAY_LEG_MINUTES = 40;

    /** At most this many stops of one kind per day, so a day isn't beach after beach. */
    private const KIND_LIMITS = ['water' => 1, 'highland' => 1];

    /** Shortest sensible visit by kind (minutes), before the pace adjusts it. */
    private const MIN_VISIT = ['water' => 120, 'resort' => 120, 'highland' => 90, 'nature' => 60, 'culture' => 45];

    /** Day 1 is planned around this arrival (10:00 AM) when the tourist doesn't give one. */
    private const DEFAULT_ARRIVAL = 600;

    private const ARRIVAL_MINUTES = 45;

    private const CHECKOUT_MINUTES = 15;

    /** Time to rinse off and change after a swim or a hike, before moving on. */
    private const FRESHEN_UP_MINUTES = 20;

    /** A wait at least this long is spent resting at the stay instead. */
    private const IDLE_MINUTES = 120;

    /** Lunch starts no later than 1:30 PM; a later one isn't scheduled. */
    private const LATEST_LUNCH = 810;

    /** No dinner is planned when the day only gets going after 8:45 PM. */
    private const LATEST_DINNER = 1245;

    /**
     * Each travel pace's daily schedule: breakfast time, how long each activity takes, when a
     * visit usually ends (minutes after midnight), dinner time, and whether an afternoon
     * merienda stop is added. Sunrise activities are skipped on a relaxed pace.
     */
    private const PACES = [
        'relaxed' => ['breakfast' => '8:30 AM', 'activity' => 90, 'visit_end' => 990, 'dinner' => '6:00 PM', 'merienda' => false],
        'moderate' => ['breakfast' => '7:30 AM', 'activity' => 60, 'visit_end' => 1020, 'dinner' => '6:30 PM', 'merienda' => false],
        'packed' => ['breakfast' => '6:30 AM', 'activity' => 45, 'visit_end' => 1050, 'dinner' => '7:00 PM', 'merienda' => true],
    ];

    private const DISHES_PER_MEAL = 3;

    /** Menu items that are a snack, dessert or drink rather than a meal (a merienda, not a lunch). */
    private const LIGHT_DISH = '/coffee|\btea\b|latte|macchiato|americano|frapp|shake|smoothie|juice|lemonade|\bmilk\b|cookie|cake|bread\b|pastr|pandesal|turon|halo-halo|halaya|maja|kakanin|puto|bibingka|suman|hinagom|dessert|ice cream|donut|waffle|fries|cheese stick|takoyaki|siomai|siopao|sandwich|nachos|muffin|brownie/i';

    /** Menu items made for breakfast. */
    private const BREAKFAST_DISH = '/silog|breakfast|tapa\b|tocino|longganisa|\begg|omelet|pancake|champorado|arroz caldo|lugaw|goto|pandesal|toast/i';

    /** Menu items meant for sharing, not one traveler's portion. */
    private const SHARING_DISH = '/party tray|bilao|bucket|barkada|platter|family/i';

    private const SOUVENIRS_PER_SHOP = 3;

    /** Guests a room sleeps, by category (rooms have no capacity field); anything else sleeps 2. */
    private const ROOM_CAPACITY = ['Standard' => 2, 'Deluxe' => 2, 'Suite' => 4, 'Family Room' => 6];

    public const TIMES_OF_DAY = ['dawn', 'morning', 'midday', 'afternoon', 'sunset', 'evening', 'anytime'];

    /** When each time of day happens, in minutes after midnight; "anytime" fits anywhere in the visit. */
    private const WINDOWS = [
        'dawn' => [300, 390],       // 5:00 – 6:30 AM
        'morning' => [360, 690],    // 6:00 – 11:30 AM
        'midday' => [630, 840],     // 10:30 AM – 2:00 PM
        'afternoon' => [780, 1020], // 1:00 – 5:00 PM
        'sunset' => [990, 1080],    // 4:30 – 6:00 PM
        'evening' => [1080, 1260],  // 6:00 – 9:00 PM
    ];

    /** Google driving times between destinations ("fromId-toId" => matrix entry), when available. */
    private array $roadLegs = [];

    public function __construct(
        private ItineraryAI $ai,
        private GoogleMapsService $maps
    ) {}

    /**
     * $user is null for a guest; the itinerary is then saved without an owner and with
     * $claimTokenHash, so the guest can claim it after logging in.
     */
    public function generate(?User $user, array $preferences, ?string $claimTokenHash = null): Itinerary
    {
        $preferences['municipality_ids'] = $this->townIds($preferences);
        $destinations = $this->getEligibleDestinations($preferences);

        $this->roadLegs = [];
        $parsedItinerary = null;
        $aiResponse = null;

        // No destination matches, but Food or Shopping is selected: those are planned from
        // verified restaurants and souvenir shops, so the trip is built from them alone. There
        // are no destinations for the AI to choose, so it isn't asked.
        if ($destinations->isEmpty()) {
            $parsedItinerary = $this->experienceOnlyTrip($preferences);
        }

        // Google only measures the first few destinations, so take them from every town in turn.
        $distanceMatrix = $parsedItinerary ? [] : $this->maps->getDistanceMatrix($this->interleaveByTown($destinations));
        $this->roadLegs = collect($distanceMatrix)->where('source', 'google')
            ->mapWithKeys(fn ($leg) => ["{$leg['from']}-{$leg['to']}" => $leg])->all();
        $prompt = $parsedItinerary ? null : $this->buildPrompt($preferences, $destinations, $distanceMatrix);

        $maxAttempts = $parsedItinerary ? 0 : 2;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $aiResponse = $this->ai->generate($prompt);

            try {
                $parsedItinerary = $this->parseAIResponse($aiResponse, $destinations, $preferences);
                break;
            } catch (\Throwable $e) {
                $context = [
                    'user_id' => $user?->id,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'raw_response' => substr($aiResponse, 0, 4000),
                ];

                if ($attempt === $maxAttempts) {
                    Log::error('Itinerary AI response parsing failed after retry', $context);

                    throw new \Exception('AI returned invalid itinerary format.');
                }

                Log::warning('Itinerary AI response parsing failed, will retry', $context);
            }
        }

        $plan = $this->planStays($parsedItinerary, $destinations, $preferences);
        [$items, $dayTowns] = $this->buildDayItems($parsedItinerary['days'], $destinations, $plan, $preferences);
        $stays = $this->bookNights($plan['stays'], $dayTowns, $preferences);

        $problems = $this->scheduleProblems($items, $destinations, $preferences);
        if ($problems !== []) {
            Log::warning('Itinerary schedule problems', ['user_id' => $user?->id, 'problems' => $problems]);
        }

        // Total for the whole group: every stop (entrance fees, dishes, souvenirs) plus the rooms.
        $stayMin = array_sum(array_map(fn ($s) => $s['cost_min'] ?? 0, $stays));
        $stayMax = array_sum(array_map(fn ($s) => $s['cost_max'] ?? 0, $stays));
        $costMin = array_sum(array_map(fn ($item) => $item['cost_min'] ?? 0, $items)) + $stayMin;
        $costMax = array_sum(array_map(fn ($item) => $item['cost_max'] ?? 0, $items)) + $stayMax;
        // Older screens show one stay: the first one the tourist sleeps at.
        $main = collect($stays)->first(fn ($s) => $s['night_days'] !== [] && $s['accommodation']) ?? collect($stays)->first();
        if (! $this->needsStay($preferences)) {
            $main = ['accommodation' => null, 'reason' => null, 'note' => "You said you don't need accommodation, so this plan books no stay and adds no room costs. Each day starts at its first stop."];
        }

        // All-or-nothing so a failed item insert doesn't leave an empty itinerary behind.
        return DB::transaction(function () use ($user, $claimTokenHash, $preferences, $stays, $main, $parsedItinerary, $aiResponse, $items, $stayMin, $stayMax, $costMin, $costMax) {
            $itinerary = Itinerary::create([
                'user_id' => $user?->id,
                'claim_token' => $user ? null : $claimTokenHash,
                'municipality_id' => $preferences['municipality_ids'][0],
                'accommodation_id' => $main['accommodation']?->id,
                'accommodation_reason' => $main['reason'] ?? null,
                'accommodation_note' => $main['note'] ?? null,
                'accommodation_cost_min' => $stayMin,
                'accommodation_cost_max' => $stayMax,
                'title' => $parsedItinerary['title'],
                'num_days' => $preferences['num_days'],
                'interests' => $preferences['interests'],
                'travel_pace' => $preferences['travel_pace'],
                'budget' => $preferences['budget'],
                'travelers' => $this->travelers($preferences),
                'estimated_cost_min' => $costMin,
                'estimated_cost_max' => $costMax,
                'start_date' => $preferences['start_date'] ?? null,
                'end_date' => $preferences['end_date'] ?? null,
                'food_preference' => $preferences['food_preference'] ?? null,
                'accommodation_type' => $preferences['accommodation_type'] ?? null,
                'transport_preference' => $preferences['transport_preference'] ?? null,
                'additional_preferences' => $preferences['additional_preferences'] ?? null,
                'ai_response_raw' => $aiResponse,
                'total_distance_km' => $this->calculateTotalDistance($items),
            ]);

            foreach ($items as $item) {
                $dishes = Arr::pull($item, 'dishes', []);
                $souvenirs = Arr::pull($item, 'souvenirs', []);
                $row = ItineraryItem::create(['itinerary_id' => $itinerary->id] + $item);
                $row->dishes()->attach($dishes);
                $row->souvenirs()->attach($souvenirs);
            }
            foreach ($stays as $stay) {
                $itinerary->stays()->create([
                    'municipality_id' => $stay['municipality_id'],
                    'accommodation_id' => $stay['accommodation']?->id,
                    'night_days' => $stay['night_days'],
                    'reason' => $stay['reason'],
                    'note' => $stay['note'],
                    'cost_min' => $stay['cost_min'],
                    'cost_max' => $stay['cost_max'],
                    'sort' => $stay['sort'],
                ]);
            }
            $itinerary->rooms()->attach(collect($stays)->flatMap(fn ($s) => $s['rooms']->pluck('id'))->unique()->values()->all());
            $itinerary->municipalities()->attach($preferences['municipality_ids']);

            return $itinerary;
        });
    }

    /**
     * After a tourist edits an itinerary, brings everything that depends on the edit up to date:
     * - stops in $changedItemIds (a new place) get fresh dish/souvenir picks and costs;
     * - food and souvenir stops without picks get recommendations, unless the tourist just
     *   set their picks ($pickedItemIds, possibly to none);
     * - a stay's rooms are re-recommended when its accommodation changed ($changedStayIds), or
     *   when it has none and the tourist didn't just pick them ($pickedStayIds);
     * - every food and souvenir stop is re-costed from its picks, then the travel legs (each day
     *   starting from the previous night's stay), each stay's cost and the trip totals.
     */
    public function recalculate(Itinerary $itinerary, array $changedItemIds = [], array $pickedItemIds = [], array $changedStayIds = [], array $pickedStayIds = []): void
    {
        $itinerary->load(['stays.accommodation', 'rooms', 'items.destination', 'items.accommodation', 'items.foodBeverage', 'items.retailShop', 'items.dishes', 'items.souvenirs']);
        $preferences = ['travelers' => $itinerary->travelers, 'num_days' => $itinerary->num_days, 'budget' => $itinerary->budget];
        $travelers = $this->travelers($preferences);
        $stays = $itinerary->stays;

        foreach ($stays as $stay) {
            $accommodation = $stay->accommodation;
            $hasRooms = $accommodation && $itinerary->rooms->contains('accommodation_id', $accommodation->id);
            $recommend = in_array($stay->id, $changedStayIds, true) || (! $hasRooms && ! in_array($stay->id, $pickedStayIds, true));
            if ($accommodation && $stay->nights() > 0 && $recommend) {
                $this->setStayRooms($itinerary, $accommodation->id, $this->recommendRooms($accommodation, $preferences)[0]->pluck('id')->all());
            }
        }
        // Rooms at a place that's no longer one of the stays.
        $orphans = $itinerary->rooms()->get()->reject(fn ($r) => $stays->contains('accommodation_id', $r->accommodation_id));
        $itinerary->rooms()->detach($orphans->pluck('id')->all());
        $itinerary->load('rooms');

        foreach ($itinerary->items->groupBy('day_number') as $dayNumber => $dayItems) {
            $prev = $this->nightStay($stays, $dayNumber - 1)?->accommodation
                ?? $this->nightStay($stays, $dayNumber)?->accommodation
                ?? $stays->first()?->accommodation
                ?? $itinerary->accommodation;
            foreach ($dayItems->sortBy('sequence') as $item) {
                $place = $item->destination ?? $item->foodBeverage ?? $item->retailShop ?? $item->accommodation;
                if (! $place) {
                    continue;
                }

                $changed = in_array($item->id, $changedItemIds, true);
                $refill = $changed || ! in_array($item->id, $pickedItemIds, true);
                $fields = [
                    'distance_from_prev_km' => $prev ? round($this->kmBetween($prev, $place), 2) : null,
                    'travel_time_from_prev' => $prev ? $this->travelMinutes($prev, $place) : null,
                ];

                if ($place instanceof FoodBeverage) {
                    if ($changed || ($refill && $item->dishes->isEmpty())) {
                        $item->dishes()->sync($this->dishPlan($place, $preferences, $this->mealOf($item->notes))['dishes']);
                        $item->load('dishes');
                    }
                    $fields += $this->foodCost($place, $item->dishes, $travelers);
                } elseif ($place instanceof RetailShop) {
                    if ($changed || ($refill && $item->souvenirs->isEmpty())) {
                        $item->souvenirs()->sync($this->souvenirPlan($place, $preferences)['souvenirs']);
                        $item->load('souvenirs');
                    }
                    $fields += $this->shopCost($place, $item->souvenirs, $travelers);
                } elseif ($changed) {
                    if ($place instanceof Destination) {
                        // As at generation: an estimated fee when it has neither a fee list nor a fee.
                        $this->applyEstimates($place);
                    }
                    $fields += $place instanceof Destination
                        ? $this->entranceCost($place, $travelers)
                        : ['cost_min' => null, 'cost_max' => null, 'cost_note' => null];
                }
                // P4: the generated "<Meal> near …" and "Leave … by …" wording follows the stop before it.
                $notes = $this->refreshedTravelNotes($item->notes, $prev, $place, $item->time_slot);
                if ($notes !== $item->notes) {
                    $fields['notes'] = $notes;
                }
                $item->update($fields);

                // A lunch break is a round trip from the destination it happens during.
                if (! ($place instanceof FoodBeverage && Str::startsWith((string) $item->notes, 'Lunch break'))) {
                    $prev = $place;
                }
            }
        }

        $stayMin = 0;
        $stayMax = 0;
        foreach ($stays as $stay) {
            [$min, $max] = $this->roomsCost($stay->accommodation, $itinerary->rooms->where('accommodation_id', $stay->accommodation_id), $stay->nights(), $preferences);
            $stay->update(['cost_min' => $min, 'cost_max' => $max]);
            $stayMin += $min ?? 0;
            $stayMax += $max ?? 0;
        }
        $main = $stays->first(fn ($s) => $s->nights() > 0 && $s->accommodation_id) ?? $stays->first();

        $items = $itinerary->items()->get();
        $itinerary->update([
            'accommodation_cost_min' => $stays->isEmpty() ? $itinerary->accommodation_cost_min : $stayMin,
            'accommodation_cost_max' => $stays->isEmpty() ? $itinerary->accommodation_cost_max : $stayMax,
            'total_distance_km' => round($items->sum(fn ($i) => (float) $i->distance_from_prev_km), 2),
            'estimated_cost_min' => $items->sum(fn ($i) => (float) $i->cost_min) + ($stays->isEmpty() ? (float) $itinerary->accommodation_cost_min : $stayMin),
            'estimated_cost_max' => $items->sum(fn ($i) => (float) $i->cost_max) + ($stays->isEmpty() ? (float) $itinerary->accommodation_cost_max : $stayMax),
        ] + ($main ? [
            'accommodation_id' => $main->accommodation_id,
            'accommodation_reason' => $main->reason,
            'accommodation_note' => $main->note,
        ] : []));
    }

    /**
     * Gives an itinerary saved without stays (the older shape) one stay for every night. One
     * planned without accommodation (no stays and no accommodation) keeps having none.
     */
    public function ensureStays(Itinerary $itinerary): void
    {
        if ($itinerary->stays()->exists() || ! $itinerary->accommodation_id) {
            return;
        }

        $itinerary->stays()->create([
            'municipality_id' => $itinerary->municipality_id,
            'accommodation_id' => $itinerary->accommodation_id,
            'night_days' => range(1, max((int) $itinerary->num_days - 1, 1)),
            'reason' => $itinerary->accommodation_reason,
            'note' => $itinerary->accommodation_note,
            'cost_min' => $itinerary->accommodation_cost_min,
            'cost_max' => $itinerary->accommodation_cost_max,
            'sort' => 0,
        ]);
    }

    /** Books $roomIds as the rooms at $accommodationId, replacing the rooms picked there before. */
    public function setStayRooms(Itinerary $itinerary, int $accommodationId, array $roomIds): void
    {
        $itinerary->rooms()->detach(
            $itinerary->rooms()->where('accommodation_rooms.accommodation_id', $accommodationId)->pluck('accommodation_rooms.id')->all()
        );
        $itinerary->rooms()->attach(array_values(array_unique($roomIds)));
    }

    /** The stay the tourist sleeps at after day $night, if any. */
    private function nightStay(Collection $stays, int $night): ?ItineraryStay
    {
        return $night < 1 ? null : $stays->first(fn ($s) => in_array($night, $s->night_days ?? [], true));
    }

    /**
     * Published destinations in the selected towns whose category matches a selected interest.
     * Food and Shopping match no destination category (they're planned from restaurants and
     * shops). A preferred destination is only marked in the prompt and favoured when filling a
     * day, so it can never get past the town and interest filters.
     */
    private function getEligibleDestinations(array $preferences): Collection
    {
        $categoryIds = array_values(array_unique(array_merge(...array_map(
            fn ($interest) => self::INTEREST_CATEGORIES[$interest] ?? [],
            array_values($preferences['interests'])
        ))));
        if ($categoryIds === []) {
            return collect();
        }

        return Destination::published()
            ->whereIn('municipality_id', $preferences['municipality_ids'])
            ->whereIn('category_id', $categoryIds)
            ->with(['category:id,name', 'municipality:id,name', 'activities:id,destination_id,name,description,type,best_time_to_visit,duration_minutes,what_to_wear', 'estimates', 'fees', 'amenities:id,destination_id,name'])
            ->select('id', 'name', 'description', 'dining_details', 'meals_offered', 'visitor_guidelines', 'category_id', 'municipality_id', 'latitude', 'longitude', 'operating_hours', 'entrance_fee')
            ->get()
            ->each(fn ($d) => $this->applyEstimates($d));
    }

    /** The destination IDs the tourist asked for (preferred_destinations), as integers. */
    private function preferredIds(array $preferences): array
    {
        return array_values(array_unique(array_map('intval', $preferences['preferred_destinations'] ?? [])));
    }

    /**
     * A trip without destinations, for Food and/or Shopping when no selected interest matches
     * a destination: each day is spent in one of the towns (in order, a block of days each),
     * around meals at approved restaurants and the daily souvenir stop. Only when the towns
     * have an approved restaurant (Food) or souvenir shop (Shopping); otherwise the same
     * "nothing matches" message as before, never other attractions instead.
     *
     * @return array{title: string, days: array<int, array>}
     */
    private function experienceOnlyTrip(array $preferences): array
    {
        $towns = $preferences['municipality_ids'];
        $offered = array_filter([
            'Food' => in_array('food', $preferences['interests'], true)
                && FoodBeverage::approved()->whereIn('municipality_id', $towns)->whereNotNull('latitude')->whereNotNull('longitude')->exists(),
            'Shopping' => in_array('shopping', $preferences['interests'], true)
                && $this->souvenirShopQuery()->whereIn('municipality_id', $towns)->whereNotNull('latitude')->whereNotNull('longitude')->exists(),
        ]);
        if ($offered === []) {
            $where = count($towns) > 1 ? 'the selected municipalities' : 'this municipality';
            $what = array_intersect(['food', 'shopping'], $preferences['interests']) !== []
                ? 'destinations, restaurants or souvenir shops'
                : 'destinations';

            throw new \Exception("No {$what} in {$where} match your interests. Try adding more interests.");
        }

        $numDays = (int) $preferences['num_days'];
        $days = [];
        for ($n = 1; $n <= $numDays; $n++) {
            $days[$n] = $this->emptyDay() + ['town' => (int) $towns[intdiv(($n - 1) * count($towns), $numDays)]];
        }

        return [
            'title' => Str::limit("{$numDays}-Day ".implode(' & ', array_keys($offered))." Trip in {$this->townNames($preferences)}", 250),
            'days' => $days,
        ];
    }

    /** Whether a stay's check-in time is only the usual one (an estimate), not one it lists. */
    private function checkInEstimated(Accommodation $stay): bool
    {
        return ! preg_match('/check[\s-]?in/i', (string) $stay->operating_hours) && $stay->estimate('check_in') !== null;
    }

    /**
     * Fills a listing's missing opening hours (and a food place's cuisine) from its estimates,
     * in memory only (planner models are never saved), and for a destination without a fee
     * sets estimated_fee [min, max]. estimated_fields lists what was filled in.
     */
    private function applyEstimates(Model $place): void
    {
        $estimated = [];
        foreach ($place instanceof FoodBeverage ? ['operating_hours', 'cuisine_type'] : ['operating_hours'] as $field) {
            $value = trim((string) $place->getAttribute($field)) === '' ? $place->estimate($field) : null;
            if ($value !== null) {
                $place->setAttribute($field, $value);
                $estimated[] = $field;
            }
        }
        // Never instead of a real Fees & Rates list.
        if ($place instanceof Destination && $place->entrance_fee === null && ! $this->generalFee($place)
            && ($fee = $place->estimate('entrance_fee')) !== null) {
            $range = array_map('floatval', explode('-', $fee));
            $place->setAttribute('estimated_fee', [$range[0], $range[1] ?? $range[0]]);
        }
        $place->setAttribute('estimated_fields', $estimated);
    }

    /** A listing description cut to whole sentences within $limit characters (one line, for the prompt). */
    private function promptExcerpt(?string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));
        $excerpt = '';
        foreach (preg_split('/(?<=[.!?])\s+/', $text) as $sentence) {
            if (mb_strlen(trim("{$excerpt} {$sentence}")) > $limit) {
                break;
            }
            $excerpt = trim("{$excerpt} {$sentence}");
        }

        return $excerpt !== '' ? $excerpt : Str::limit($text, $limit);
    }

    /**
     * The approved stays, food places and souvenir shops in the selected towns (a few of each,
     * one short line apiece), so the AI knows what the planner can schedule around its
     * destinations. Context only: the planner picks these itself, never the AI.
     */
    private function otherPlacesContext(array $preferences): string
    {
        $towns = $preferences['municipality_ids'];
        $townNames = Municipality::whereIn('id', $towns)->pluck('name', 'id');
        $line = fn ($place, string $kind) => "- {$place->name} ({$townNames[$place->municipality_id]}; {$kind}): ".$this->promptExcerpt($place->description, 140);

        $groups = [
            'Places to stay' => ! $this->needsStay($preferences) ? collect() : Accommodation::approved()->whereIn('municipality_id', $towns)->with('roomTypes:id,accommodation_id,name,sort_order')->limit(8)->get(['id', 'name', 'municipality_id', 'type', 'price_range', 'description', 'dining_details'])
                ->map(fn ($a) => $line($a, trim("{$a->type}, {$a->price_range}", ', '))
                    .($a->dining_details ? ' Dining: '.$this->promptExcerpt($a->dining_details, 120) : '')
                    .($a->roomTypes->isNotEmpty() ? ' Room types: '.$a->roomTypes->pluck('name')->take(6)->implode(', ').'.' : '')),
            'Food places' => FoodBeverage::approved()->whereIn('municipality_id', $towns)->limit(8)->get(['name', 'municipality_id', 'cuisine_type', 'price_range', 'description', 'meals_served', 'specialties'])
                ->map(fn ($f) => $line($f, trim(($f->cuisine_type ?: 'food').", {$f->price_range}", ', '))
                    .($f->meals_served ? ' Serves: '.implode(', ', $f->meals_served).'.' : '')
                    .($f->specialties ? ' Known for: '.$this->promptExcerpt($f->specialties, 120) : '')),
            'Souvenir shops' => $this->souvenirShopQuery()->whereIn('municipality_id', $towns)->limit(8)->get(['name', 'municipality_id', 'description'])
                ->map(fn ($s) => $line($s, 'souvenirs')),
        ];
        $groups = array_filter($groups, fn ($lines) => $lines->isNotEmpty());
        if ($groups === []) {
            return '';
        }

        $text = "OTHER VERIFIED PLACES NEARBY (context only: the planner schedules stays, meals and shopping itself; never list these as destinations):\n";
        foreach ($groups as $title => $lines) {
            $text .= "{$title}:\n".$lines->implode("\n")."\n";
        }

        return "{$text}\n";
    }

    /**
     * The trip's town ids, in the order the tourist picked them. Accepts the older single
     * municipality_id too.
     *
     * @return array<int, int>
     */
    private function townIds(array $preferences): array
    {
        $ids = $preferences['municipality_ids'] ?? [$preferences['municipality_id'] ?? 1];

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** Destinations taken from each town in turn, so any first-N slice covers every town. */
    private function interleaveByTown(Collection $destinations): Collection
    {
        $groups = $destinations->groupBy('municipality_id')->map(fn ($g) => $g->values())->values();
        $interleaved = collect();
        for ($i = 0; $interleaved->count() < $destinations->count(); $i++) {
            foreach ($groups as $group) {
                if (isset($group[$i])) {
                    $interleaved->push($group[$i]);
                }
            }
        }

        return $interleaved;
    }

    private function buildPrompt(array $preferences, Collection $destinations, array $distanceMatrix): string
    {
        $travelPace = $preferences['travel_pace'];
        $numDays = $preferences['num_days'];
        $budget = $preferences['budget'];
        $interestsFormatted = $this->formatInterests($preferences['interests']);
        $destinationCount = $destinations->count();

        $preferred = $this->preferredIds($preferences);
        $destinationsList = $destinations->map(function ($d) use ($preferred) {
            $hours = ($d->operating_hours ?: 'Not listed').(in_array('operating_hours', $d->estimated_fields ?? [], true) ? ' (typical, unconfirmed)' : '');
            $listed = $this->generalFee($d);
            $fee = match (true) {
                $listed !== null => "PHP {$listed->amount} ({$listed->label})",
                $d->entrance_fee !== null => "PHP {$d->entrance_fee}",
                (bool) $d->estimated_fee => 'est. PHP '.implode('-', array_unique(array_map(fn ($v) => (int) $v, $d->estimated_fee))),
                default => 'not listed',
            };
            $activities = $d->activities->map(function ($a) {
                $details = implode(', ', array_filter([$a->type, $a->best_time_to_visit ? "best: {$a->best_time_to_visit}" : null]));

                return $a->name.($details !== '' ? " ({$details})" : '');
            })->implode('; ');

            $about = $this->promptExcerpt($d->description, 220);
            $amenities = $d->relationLoaded('amenities') ? $d->amenities->pluck('name')->take(8)->implode(', ') : '';
            $food = $d->dining_details
                ? $this->promptExcerpt($d->dining_details, 160).($d->meals_offered ? ' (meals: '.implode(', ', $d->meals_offered).')' : '')
                : '';

            return "ID:{$d->id} | {$d->name} | Town: {$d->municipality?->name} | Category: {$d->category->name} | Coords: {$d->latitude},{$d->longitude} | Hours: {$hours} | Fee: {$fee}"
                .($activities !== '' ? " | Activities: {$activities}" : '')
                .($amenities !== '' ? " | Amenities: {$amenities}" : '')
                .($food !== '' ? " | Food on site: {$food}" : '')
                .($d->visitor_guidelines ? ' | Visitor notes: '.$this->promptExcerpt($d->visitor_guidelines, 160) : '')
                .($about !== '' ? " | About: {$about}" : '')
                .(in_array((int) $d->id, $preferred, true) ? ' | The tourist asked for this place' : '');
        })->implode("\n");

        $matrixText = '';
        foreach ($distanceMatrix as $entry) {
            $matrixText .= "From ID:{$entry['from']} to ID:{$entry['to']}: {$entry['duration_min']} min, {$entry['distance_km']} km\n";
        }

        $townNames = $this->townNames($preferences);
        $townCount = count($preferences['municipality_ids']);
        $perDay = self::DAY_STOPS[$travelPace] ?? 3;
        $firstDay = $this->dayTarget(1, $preferences);
        $arrivalAt = $this->arrivalMinutes($preferences);
        $departAt = $this->departureMinutes($preferences);
        $lastDay = $this->dayTarget($numDays, $preferences);
        $roadTimes = ($distanceMatrix[0]['source'] ?? 'estimate') === 'google';

        $prompt = "You are an experienced local tour planner for {$townNames}, Sorsogon, Philippines.\n\n";
        $prompt .= "Create a {$numDays}-day travel itinerary. Each day visits up to {$perDay} tourist destinations ({$firstDay} on Day 1, the arrival day), listed in the order the tourist should visit them.\n";
        $prompt .= $firstDay === 0
            ? "The tourist arrives too late on Day 1 for sightseeing: give Day 1 an empty \"destinations\" list.\n"
            : '';
        if ($departAt !== null) {
            $prompt .= "The tourist leaves around {$this->formatMinutes($departAt)} on Day {$numDays}, so Day {$numDays} holds at most {$lastDay} destination(s)"
                .($lastDay === 0 ? ': give it an empty "destinations" list' : ', ones that are over well before then').".\n";
        }
        $prompt .= "\n";

        if (! empty($preferences['start_date'])) {
            $startDate = Carbon::parse($preferences['start_date']);
            $prompt .= "TRIP DATES: Day 1 is {$startDate->format('l, M j, Y')}";
            if ($numDays > 1) {
                $prompt .= ", ending Day {$numDays} on {$startDate->copy()->addDays($numDays - 1)->format('l, M j, Y')}";
            }
            $prompt .= ". Consider day-of-week where useful (e.g. weekend crowd levels, typical closing days).\n\n";
        }

        $prompt .= "TOURIST PREFERENCES:\n";
        $prompt .= "- Duration: {$numDays} day(s)\n";
        $prompt .= "- Interests: {$interestsFormatted}\n";
        $prompt .= "- Travel Pace: {$travelPace}\n";
        $prompt .= "- Budget: {$budget}\n";
        $prompt .= $arrivalAt !== null
            ? '- Arrival on Day 1: around '.$this->formatMinutes($arrivalAt)."\n"
            : '- Arrival on Day 1: not given (planned for around '.$this->formatMinutes(self::DEFAULT_ARRIVAL).")\n";
        if ($departAt !== null) {
            $prompt .= "- Departure on Day {$numDays}: around {$this->formatMinutes($departAt)}\n";
        }
        if (! empty($preferences['additional_preferences'])) {
            $prompt .= "- Additional notes from tourist: {$preferences['additional_preferences']}\n";
        }
        $sightseeing = array_map('ucfirst', $this->interestsOf($preferences, null));
        if (array_intersect(['food', 'shopping'], $preferences['interests']) !== []) {
            $prompt .= "- Food and Shopping interests are covered by the planner with verified restaurants and souvenir shops, not by destinations.\n";
        }
        $prompt .= "\n";
        $prompt .= "HOW THIS TRIP WORKS:\n";
        $prompt .= "- You ONLY choose each day's destinations, their order and why. The planner itself handles arrival".($this->needsStay($preferences) ? ' and check-in on Day 1' : ' on Day 1').", schedules each destination's listed activities, ".($this->needsStay($preferences) ? 'picks one accommodation per town' : 'books no accommodation (the tourist has their own arrangements)').", every meal (breakfast, lunch, an afternoon merienda and dinner at verified restaurants, so never spend a destination on a meal), rest breaks and souvenir shopping, and sets all clock times.\n";
        $prompt .= "- Travelling between towns takes most of a day, so a day never covers two towns.\n\n";
        $prompt .= "AVAILABLE VERIFIED DESTINATIONS (use ONLY these, reference by ID):\n";
        $prompt .= "{$destinationsList}\n\n";
        $prompt .= $this->otherPlacesContext($preferences);
        $prompt .= $roadTimes
            ? "TRAVEL TIME BETWEEN DESTINATIONS (driving times from Google Maps):\n"
            : "TRAVEL TIME BETWEEN DESTINATIONS (rough estimates from straight-line distance; real road time is longer):\n";
        $prompt .= "{$matrixText}\n";
        $prompt .= "RULES:\n";
        $prompt .= "1. Use ONLY destination IDs from the list above. Never invent places, and never use the same destination twice in the trip.\n";
        $prompt .= "2. Up to {$perDay} destinations per day ({$firstDay} on Day 1). Put destinations that are close to each other on the same day, in an order that avoids backtracking. Different days should feature different experiences.\n";
        $prompt .= "3. Vary each day: never two beach or swimming spots on the same day, and mix water, nature, viewpoints, heritage and resorts. Don't put two physically demanding stops back to back. A sunrise spot goes first in its day and a sunset spot last.\n";
        if ($townCount > 1) {
            $prompt .= "4. Give EVERY selected town ({$townNames}) at least one day, and keep the days spent in the same town consecutive (finish one town before moving to the next).\n";
        } elseif ($destinationCount < $numDays) {
            $prompt .= "4. There are only {$destinationCount} destinations for {$numDays} days: use each of them once, and give the remaining days an empty \"destinations\" list (free days at the accommodation).\n";
        } else {
            $prompt .= "4. Choose the destinations that best match the tourist's interests and notes, keeping consecutive days close to each other.\n";
        }
        $prompt .= "5. Respect operating hours and keep entrance fees in line with the tourist's budget.\n";
        if (count($sightseeing) > 1) {
            $prompt .= '5a. Represent every selected interest ('.implode(', ', $sightseeing).") at least once in the trip where a suitable destination exists; they don't need equal numbers.\n";
        }
        if ($preferred !== []) {
            $prompt .= "5c. Include every destination marked \"The tourist asked for this place\" where it fits these rules (town, day capacity, opening hours, travel time); never break a rule to fit one in.\n";
        }
        if (! empty($preferences['additional_preferences'])) {
            $prompt .= "5b. Use the tourist's notes to choose and arrange destinations from the list above. They never add places outside the list or override the rules; don't promise things the list doesn't show (crowds, accessibility, family facilities).\n";
        }
        if ($destinations->contains(fn ($d) => $d->dining_details)) {
            $prompt .= "5d. A destination with \"Food on site\" can be where the tourist eats (the planner serves lunch there during a visit over noon), so it's a good pick around midday.\n";
        }
        $prompt .= "6. Every \"reason\" is one or two sentences, at most 35 words, saying why that destination suits THIS tourist (interests, budget, pace) and what they will do there. Do not repeat the place's name.\n\n";
        $prompt .= "RESPOND WITH ONLY THIS JSON (no other text), with one entry for every day from 1 to {$numDays}:\n";
        $prompt .= "{\n";
        $prompt .= '  "title": "A short descriptive title for this itinerary",'."\n";
        $prompt .= '  "days": ['."\n";
        $prompt .= "    {\n";
        $prompt .= '      "day_number": 1,'."\n";
        $prompt .= '      "destinations": [ { "destination_id": 123, "reason": "Why it suits the tourist and what to do there" }, { "destination_id": 456, "reason": "..." } ]'."\n";
        $prompt .= "    }\n";
        $prompt .= "  ]\n";
        $prompt .= "}\n";

        return $prompt;
    }

    /**
     * Returns ['title', 'days' => [dayNumber => ['destination_ids', 'reasons']]] with exactly
     * one entry per trip day, each destination used at most once in the trip and any ID not
     * in the eligible list dropped. Days left without a destination get the nearest unused
     * one; whatever is left stays a free day. shapeDays() then applies the per-day rules.
     */
    private function parseAIResponse(string $response, Collection $destinations, array $preferences): array
    {
        $json = $response;
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $response, $matches)) {
            $json = $matches[1];
        }

        $parsed = json_decode(trim($json), true);

        if (! $parsed || ! isset($parsed['days'])) {
            $start = strpos($response, '{');
            $end = strrpos($response, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $parsed = json_decode(substr($response, $start, $end - $start + 1), true);
            }
        }

        if (! $parsed || ! isset($parsed['days']) || ! is_array($parsed['days'])) {
            throw new \Exception('AI returned invalid itinerary format.');
        }

        $numDays = (int) $preferences['num_days'];
        $validIds = $destinations->pluck('id')->map(fn ($id) => (int) $id)->all();

        $byDay = [];
        foreach ($parsed['days'] as $day) {
            $dayNumber = is_array($day) ? (int) ($day['day_number'] ?? 0) : 0;
            if ($dayNumber < 1 || $dayNumber > $numDays || isset($byDay[$dayNumber])) {
                continue;
            }
            // {"destinations": [{destination_id, reason}]}, or the older {destination_id, reason}.
            $entries = is_array($day['destinations'] ?? null)
                ? array_filter($day['destinations'], 'is_array')
                : [['destination_id' => $day['destination_id'] ?? null, 'reason' => $day['reason'] ?? null]];
            $byDay[$dayNumber] = array_map(fn ($e) => [
                'id' => isset($e['destination_id']) ? (int) $e['destination_id'] : null,
                'reason' => $this->cleanText($e['reason'] ?? null),
            ], array_values($entries));
        }

        $days = [];
        $used = [];
        for ($n = 1; $n <= $numDays; $n++) {
            $days[$n] = $this->emptyDay();
            foreach ($byDay[$n] ?? [] as $entry) {
                $id = $entry['id'];
                // Skip destinations we can't use or already used, and any in another town than the
                // day's first (a day never covers two towns, and coverEveryTown() must see where
                // each day really is); shapeDays() applies the other day rules.
                if ($id === null || ! in_array($id, $validIds, true) || in_array($id, $used, true)) {
                    continue;
                }
                $first = $days[$n]['destination_ids'][0] ?? null;
                if ($first !== null && (int) $destinations->firstWhere('id', $first)->municipality_id !== (int) $destinations->firstWhere('id', $id)->municipality_id) {
                    continue;
                }
                $used[] = $id;
                $days[$n]['destination_ids'][] = $id;
                $days[$n]['reasons'][$id] = $entry['reason'];
            }
        }

        $days = $this->coverEveryTown($days, $destinations, $preferences);

        // A day left without a destination gets the nearest unused one in the previous day's
        // town (so the trip doesn't hop between towns); whatever is left stays a free day.
        $byId = $destinations->keyBy(fn ($d) => (int) $d->id);
        foreach ($days as $n => $day) {
            if ($day['destination_ids'] !== []) {
                continue;
            }
            $used = array_merge(...array_values(array_column($days, 'destination_ids')));
            $previous = collect($days)->filter(fn ($d, $m) => $m < $n && $d['destination_ids'] !== [])->last();
            $town = $previous ? (int) $byId[$previous['destination_ids'][0]]->municipality_id : null;
            $unused = $destinations->reject(fn ($d) => in_array((int) $d->id, $used, true));
            $pool = $town !== null && $unused->contains('municipality_id', $town) ? $unused->where('municipality_id', $town) : $unused;
            $center = $this->centroid($destinations->whereIn('id', $used)) ?? $this->centroid($destinations);
            $pick = $pool->sortBy(fn ($d) => $center ? $this->km($center, $d) : 0)->first();
            if ($pick) {
                $days[$n]['destination_ids'][] = (int) $pick->id;
            }
        }

        $days = $this->shapeDays($this->groupDaysByTown($days, $destinations), $destinations, $preferences);

        $title = $this->cleanText($parsed['title'] ?? null);

        return [
            'title' => $title !== null ? Str::limit($title, 250) : "{$numDays}-Day Sorsogon Trip",
            'days' => $days,
        ];
    }

    private function emptyDay(): array
    {
        return ['destination_ids' => [], 'reasons' => []];
    }

    /**
     * Makes sure every selected town with a matching destination gets a day of its own (there
     * are at least as many days as towns): its destination nearest the trip goes on a free day,
     * else takes over the nearest day of a town that has several days.
     */
    private function coverEveryTown(array $days, Collection $destinations, array $preferences): array
    {
        $byId = $destinations->keyBy(fn ($d) => (int) $d->id);
        $townOf = fn (int $id) => (int) $byId[$id]->municipality_id;
        $chosen = fn () => array_merge(...array_values(array_column($days, 'destination_ids')));

        foreach ($preferences['municipality_ids'] as $town) {
            $usedIds = $chosen();
            if (collect($usedIds)->contains(fn ($id) => $townOf($id) === $town)) {
                continue;
            }
            $center = $this->centroid($destinations->whereIn('id', $usedIds)) ?? $this->centroid($destinations);
            $pick = $destinations->where('municipality_id', $town)
                ->reject(fn ($d) => in_array((int) $d->id, $usedIds, true))
                ->sortBy(fn ($d) => $center ? $this->km($center, $d) : 0)
                ->first();
            if (! $pick) {
                continue;
            }
            $pickId = (int) $pick->id;
            // How far a day's destinations are from the pick.
            $kmToPick = fn (array $day) => $day['destination_ids'] === []
                ? 0
                : min(array_map(fn ($id) => $this->kmBetween($byId[$id], $pick), $day['destination_ids']));

            $free = collect($days)->filter(fn ($d) => $d['destination_ids'] === [])->keys()->first();
            if ($free !== null) {
                $days[$free]['destination_ids'][] = $pickId;

                continue;
            }

            // Take over a day of a town that has several days (a day's town: its first destination's).
            $dayTown = fn (array $d) => $townOf($d['destination_ids'][0]);
            $townDays = collect($days)->filter(fn ($d) => $d['destination_ids'] !== [])->countBy($dayTown);
            $takeOver = collect($days)->filter(fn ($d) => $d['destination_ids'] !== [] && $townDays[$dayTown($d)] > 1)
                ->sortBy($kmToPick)->keys()->first();
            if ($takeOver !== null) {
                $days[$takeOver] = ['destination_ids' => [$pickId], 'reasons' => []];
            }
        }

        return $days;
    }

    /**
     * Puts each town's days next to each other (towns in the order they first appear), so the
     * tourist moves between towns once instead of back and forth. Free days go last.
     */
    private function groupDaysByTown(array $days, Collection $destinations): array
    {
        $byId = $destinations->keyBy(fn ($d) => (int) $d->id);
        $townOf = fn (array $day) => $day['destination_ids'] === [] ? null : (int) $byId[$day['destination_ids'][0]]->municipality_id;
        $firstSeen = [];
        foreach ($days as $day) {
            $town = $townOf($day);
            if ($town !== null && ! isset($firstSeen[$town])) {
                $firstSeen[$town] = count($firstSeen);
            }
        }

        $ordered = collect($days)
            ->map(fn ($day, $n) => ['day' => $day, 'n' => $n])
            ->sortBy(fn ($row) => [$firstSeen[$townOf($row['day'])] ?? PHP_INT_MAX, $row['n']])
            ->pluck('day')
            ->values()
            ->all();

        return array_combine(range(1, count($ordered)), $ordered);
    }

    /**
     * The per-day rules, applied to the AI's picks. A day keeps its first pick, then only picks
     * in the same town, open that day, within MAX_DAY_LEG_MINUTES of the first, not a second
     * water or highland stop (KIND_LIMITS), not a second sunrise or sunset stop, and no more
     * than the pace allows (dayTarget()); a first pick closed that day is dropped too. Unused
     * destinations then top up short days one stop per day per round, so extras spread over
     * the trip, each the closest fit to the day's stops, preferring a kind the day lacks and,
     * more strongly, a selected interest no stop in the trip represents yet.
     */
    private function shapeDays(array $days, Collection $destinations, array $preferences): array
    {
        $byId = $destinations->keyBy(fn ($d) => (int) $d->id);
        $fits = fn (array $ids, Destination $candidate, int $n) => $this->fitsDay($ids, $candidate, $n, $byId, $preferences);

        foreach ($days as $n => $day) {
            $kept = [];
            foreach ($day['destination_ids'] as $id) {
                if ($fits($kept, $byId[$id], $n)) {
                    $kept[] = $id;
                }
            }
            $days[$n]['destination_ids'] = $kept;
        }

        do {
            $added = false;
            foreach ($days as $n => $day) {
                $ids = $day['destination_ids'];
                if (count($ids) >= $this->dayTarget($n, $preferences)) {
                    continue;
                }
                $used = array_merge(...array_values(array_column($days, 'destination_ids')));
                // An empty day stays in the town of the day before it.
                $previous = collect($days)->filter(fn ($d, $m) => $m < $n && $d['destination_ids'] !== [])->last();
                $near = collect($ids ?: ($previous['destination_ids'] ?? []))->map(fn ($id) => $byId[$id]);
                $town = $near->isNotEmpty() ? (int) $near->first()->municipality_id : (int) $preferences['municipality_ids'][0];
                $kinds = collect($ids)->map(fn ($id) => $this->experienceKind($byId[$id]))->all();
                // Selected interests no stop in the trip represents yet.
                $uncovered = array_diff(
                    $this->interestsOf($preferences, null),
                    array_merge([], ...array_map(fn ($id) => $this->interestsOf($preferences, $byId[$id]), $used))
                );

                // Among the places that fit the day, the closest, preferring a kind the day lacks,
                // more strongly an interest the trip doesn't cover yet, and most a place the
                // tourist asked for.
                $preferred = $this->preferredIds($preferences);
                $pick = $destinations
                    ->reject(fn ($d) => in_array((int) $d->id, $used, true))
                    ->filter(fn ($d) => (int) $d->municipality_id === $town && $fits($ids, $d, $n))
                    ->sortBy(fn ($d) => ($near->isEmpty() ? 0 : $near->min(fn ($s) => $this->travelMinutes($s, $d)))
                        + (in_array($this->experienceKind($d), $kinds, true) ? 30 : 0)
                        - (array_intersect($uncovered, $this->interestsOf($preferences, $d)) !== [] ? 60 : 0)
                        - (in_array((int) $d->id, $preferred, true) ? 120 : 0))
                    ->first();
                if (! $pick) {
                    continue;
                }

                $days[$n]['destination_ids'][] = (int) $pick->id;
                $adds = array_intersect($uncovered, $this->interestsOf($preferences, $pick));
                $days[$n]['reasons'][(int) $pick->id] = (in_array((int) $pick->id, $preferred, true) ? 'One of the places you asked for. ' : '')
                    .($adds !== [] ? 'Brings your '.ucfirst(reset($adds)).' interest into the trip. ' : '')
                    .$this->addedReason($pick, $ids === [] ? null : $byId[end($ids)]);
                // Planner-added (not the AI's pick): may give way to an AI pick moved from an earlier day.
                $days[$n]['added'][] = (int) $pick->id;
                $added = true;
            }
        } while ($added);

        return $days;
    }

    /**
     * The selected sightseeing interests a destination represents (by its category), or with
     * null, all the selected ones that select destinations at all (not Food or Shopping).
     *
     * @return array<int, string>
     */
    private function interestsOf(array $preferences, ?Destination $destination): array
    {
        return array_values(array_filter(array_values($preferences['interests'] ?? []), fn ($interest) => $destination
            ? in_array((int) $destination->category_id, self::INTEREST_CATEGORIES[$interest] ?? [], true)
            : (self::INTEREST_CATEGORIES[$interest] ?? []) !== []));
    }

    /**
     * Whether day $n, already visiting $ids (keys of $byId), can also take $candidate under the
     * day rules: open that day; on Day 1, fits after the arrival; otherwise room at the pace,
     * same town as the day's first stop and within MAX_DAY_LEG_MINUTES of it, within
     * KIND_LIMITS, and not a second sunrise or sunset stop.
     */
    private function fitsDay(array $ids, Destination $candidate, int $n, Collection $byId, array $preferences): bool
    {
        $start = ! empty($preferences['start_date']) ? Carbon::parse($preferences['start_date']) : null;
        if ($this->isClosedOn($candidate, $start?->copy()->addDays($n - 1))) {
            return false;
        }
        // Day 1 only takes what fits between the arrival and the end of the day.
        if ($n === 1 && ! $this->fitsArrivalDay([...array_map(fn ($id) => $byId[$id], $ids), $candidate], $preferences, $start)) {
            return false;
        }
        if ($ids === []) {
            return $this->dayTarget($n, $preferences) > 0;
        }
        $stops = collect($ids)->map(fn ($id) => $byId[$id]);
        $kind = $this->experienceKind($candidate);

        return count($ids) < $this->dayTarget($n, $preferences)
            && (int) $candidate->municipality_id === (int) $stops->first()->municipality_id
            && $this->travelMinutes($stops->first(), $candidate) <= self::MAX_DAY_LEG_MINUTES
            && $stops->filter(fn ($d) => $this->experienceKind($d) === $kind)->count() < (self::KIND_LIMITS[$kind] ?? PHP_INT_MAX)
            && ! ($this->isAnchor($candidate) && $stops->contains(fn ($d) => $this->isAnchor($d)))
            && ($preferences['travel_pace'] === 'relaxed' || ! ($this->hasDawn($candidate) && $stops->contains(fn ($d) => $this->hasDawn($d))));
    }

    /**
     * Attractions a day holds at the tourist's pace. Day 1 holds one fewer, none when the
     * tourist arrives too late for any visit; fitsArrivalDay() decides which ones actually fit.
     * With a departure time the last day holds about one per two hours left before it, none
     * when there's no time for a visit; buildDayItems() leaves out whatever still runs later.
     */
    private function dayTarget(int $day, array $preferences): int
    {
        $pace = $preferences['travel_pace'] ?? 'moderate';
        $stops = self::DAY_STOPS[$pace] ?? 3;
        $target = $stops;
        if ($day === 1) {
            // The shortest visit (45 minutes) plus getting there.
            $target = $this->arrivalDayMinutes($preferences) >= min(self::MIN_VISIT) + 15 ? $stops - 1 : 0;
        }
        if ($day === (int) ($preferences['num_days'] ?? 0) && $this->departureMinutes($preferences) !== null) {
            $minutes = $this->departureDayMinutes($day, $preferences);
            $target = min($target, $minutes >= min(self::MIN_VISIT) + 15 ? max(1, intdiv($minutes, 120)) : 0);
        }

        return $target;
    }

    /**
     * Sightseeing time on the departure day: from after breakfast (or check-in on a one-day
     * trip) until the departure or the end of the visiting day, less lunch if it fits in.
     */
    private function departureDayMinutes(int $day, array $preferences): int
    {
        $pace = $preferences['travel_pace'] ?? 'moderate';
        $from = $day === 1
            ? ($this->arrivalMinutes($preferences) ?? self::DEFAULT_ARRIVAL) + self::ARRIVAL_MINUTES
            : $this->minutesOf($this->pace($pace)['breakfast']) + self::BREAKFAST_MINUTES + self::CHECKOUT_MINUTES;
        $until = min($this->departureMinutes($preferences), $this->visitEndTime($pace));
        $lunch = $from < self::LATEST_LUNCH && $until > 750 ? self::MEAL_MINUTES : 0;

        return max(0, $until - $from - $lunch);
    }

    /**
     * Whether $places, visited in this order, fit into Day 1: from the end of check-in, about
     * 15 minutes to the first one (the stay isn't chosen yet), each for its shortest acceptable
     * visit once it's open, with the travel and rinse-off time between them and lunch while
     * it's due; every visit over by closing time, the last by the end of the visiting day.
     */
    private function fitsArrivalDay(array $places, array $preferences, ?Carbon $date): bool
    {
        $pace = $preferences['travel_pace'] ?? 'moderate';
        $clock = ($this->arrivalMinutes($preferences) ?? self::DEFAULT_ARRIVAL) + self::ARRIVAL_MINUTES + 15;
        $lunchDue = $clock < self::LATEST_LUNCH;
        $prev = null;
        foreach ($places as $place) {
            if ($prev) {
                $clock += $this->travelMinutes($prev, $place)
                    + (in_array($this->experienceKind($prev), ['water', 'highland'], true) ? self::FRESHEN_UP_MINUTES : 0);
            }
            $hours = $this->openingHours($place->operating_hours, $date);
            $planned = $this->stretchVisit($this->plannedActivities($place, $place, $pace, $hours, true), $this->minimumVisit($place, $pace));
            $clock = max($clock, $hours[0] ?? 0) + min($this->minimumVisit($place, $pace), array_sum(array_column($planned, 'duration')));
            if ($hours && $clock > $hours[1]) {
                return false;
            }
            if ($lunchDue && $clock > 750) {
                $clock += self::MEAL_MINUTES + 10;
                $lunchDue = false;
            }
            $prev = $place;
        }

        return $clock <= $this->visitEnd($planned ?? [], $pace, null);
    }

    /** Sightseeing time on Day 1: from check-in after the arrival to the end of the visiting day, less lunch if it's still to come. */
    private function arrivalDayMinutes(array $preferences): int
    {
        $from = ($this->arrivalMinutes($preferences) ?? self::DEFAULT_ARRIVAL) + self::ARRIVAL_MINUTES;

        return $this->visitEndTime($preferences['travel_pace'] ?? 'moderate') - $from - ($from < self::LATEST_LUNCH ? self::MEAL_MINUTES : 0);
    }

    /**
     * A stay's check-in time in minutes after midnight: the one it lists ("Check-in: 1:00 PM…"),
     * else its usual (estimated, see checkInEstimated()) one, else null.
     */
    private function checkInMinutes(Accommodation $stay): ?int
    {
        $pattern = '/check[\s-]?in\b[^0-9]{0,10}(\d{1,2})(?::(\d{2}))?\s*([ap])\.?\s*m\b/i';
        if (! preg_match($pattern, (string) $stay->operating_hours, $m) && ! preg_match($pattern, 'check-in '.$stay->estimate('check_in'), $m)) {
            return null;
        }

        return ((int) $m[1] % 12 + (strtolower($m[3]) === 'p' ? 12 : 0)) * 60 + (int) $m[2];
    }

    /** Why the planner (not the AI) added a destination to a day. */
    private function addedReason(Destination $place, ?Destination $after): string
    {
        $kind = $this->kindLabel($this->experienceKind($place));
        if (! $after) {
            return "The closest unused {$kind} that matches your interests.";
        }

        $minutes = $this->travelMinutes($after, $place);
        $change = $this->experienceKind($after) !== $this->experienceKind($place) ? ', and a change of pace from the stop before it' : '';

        return 'A '.$kind.($minutes > 0 ? " about {$minutes} min from {$after->name}" : " right by {$after->name}")
            .", so it fits the day without backtracking{$change}.";
    }

    /**
     * What kind of experience a destination is, from its category and listed activities:
     * water (beaches, islands, falls, swimming), highland (mountains, hikes), culture
     * (heritage, churches), resort, or nature (everything else). A mountain whose listed
     * activities are all about the view (e.g. only sunset watching) is a viewpoint, not a hike.
     */
    private function experienceKind(Destination $destination): string
    {
        $category = mb_strtolower((string) $destination->category?->name);
        $doing = mb_strtolower($destination->activities->pluck('name')->implode(' '));
        $viewingOnly = $destination->activities->isNotEmpty()
            && $destination->activities->every(fn ($a) => preg_match('/sunset|sunrise|view|watch|stargaz|sightsee/i', $a->name));

        if (in_array($category, ['beach', 'island', 'waterfall'], true) || preg_match('/swim|snorkel|kayak|diving|dive|surf|island hopping|boat/', $doing)) {
            return 'water';
        }
        if (($category === 'mountain' && ! $viewingOnly) || preg_match('/hike|hiking|trek|climb|trail/', $doing)) {
            return 'highland';
        }
        if (in_array($category, ['historical', 'church', 'museum'], true)) {
            return 'culture';
        }

        return $category === 'resort' ? 'resort' : 'nature';
    }

    private function kindLabel(string $kind): string
    {
        return [
            'water' => 'beach or water spot',
            'highland' => 'highland spot',
            'culture' => 'heritage stop',
            'resort' => 'resort stop',
        ][$kind] ?? 'nature stop';
    }

    /** A destination with a sunrise activity. */
    private function hasDawn(Destination $destination): bool
    {
        return $destination->activities->contains(fn ($a) => $this->timeOfDay($a->name, $a->best_time_to_visit, 'anytime') === 'dawn');
    }

    /** Hours listed per weekday ("Mon-Fri: …") that leave out $date's weekday. */
    private function isClosedOn($place, ?Carbon $date): bool
    {
        if (! $date || ! preg_match_all('/\b(mon|tue|wed|thu|fri|sat|sun)\w*(?:\s*(?:-|–|to)\s*(mon|tue|wed|thu|fri|sat|sun)\w*)?\s*:/i', (string) $place->operating_hours, $segments, PREG_SET_ORDER)) {
            return false;
        }

        $days = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
        $today = $date->dayOfWeekIso;
        foreach ($segments as $segment) {
            $from = $days[strtolower($segment[1])];
            $to = $days[strtolower(($segment[2] ?? '') ?: $segment[1])];
            if ($from <= $to ? $today >= $from && $today <= $to : $today >= $from || $today <= $to) {
                return false;
            }
        }

        return true;
    }

    /**
     * The order to visit a day's destinations: a sunrise stop first (when it can be done) and
     * a sunset/evening stop last; the rest each the nearest to where the tourist is, unless
     * that repeats the kind of experience just had and another kind is left.
     */
    private function orderStops(Collection $stops, ?Accommodation $home, string $pace, bool $arrivalDay): Collection
    {
        $dawn = ! $arrivalDay && $pace !== 'relaxed' ? $stops->first(fn ($d) => $this->hasDawn($d)) : null;
        $anchor = $stops->first(fn ($d) => $d !== $dawn && $this->isAnchor($d));
        $rest = $stops->reject(fn ($d) => $d === $dawn || $d === $anchor)->values();

        $ordered = [];
        $from = $dawn ?? $home;
        $lastKind = $dawn ? $this->experienceKind($dawn) : null;
        while ($rest->isNotEmpty()) {
            $otherKinds = $rest->contains(fn ($d) => $this->experienceKind($d) !== $lastKind);
            $next = $rest->sortBy(fn ($d) => ($otherKinds && $this->experienceKind($d) === $lastKind ? 10000 : 0)
                + ($from ? $this->travelMinutes($from, $d) : 0))->first();
            $ordered[] = $next;
            $rest = $rest->reject(fn ($d) => $d === $next)->values();
            $from = $next;
            $lastKind = $this->experienceKind($next);
        }

        return collect([$dawn, ...$ordered, $anchor])->filter()->values();
    }

    /** Trimmed, length-capped text, or null when the AI gave nothing usable. */
    private function cleanText($value, int $limit = 500): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', $value));

        return $text === '' ? null : Str::limit($text, $limit);
    }

    /**
     * One stay per selected town: the accommodation nearest the middle of the destinations the
     * tourist visits there (else of the town's eligible ones, else the town itself), preferring
     * their type and budget. Also the towns' food places and souvenir shops that meals and
     * shopping are picked from. Nights and rooms are booked later by bookNights().
     *
     * @return array{stays: array<int, array>, food: Collection, shops: Collection}
     */
    private function planStays(array $parsed, Collection $destinations, array $preferences): array
    {
        $townIds = $preferences['municipality_ids'];
        $chosen = $destinations->whereIn('id', array_merge(...array_values(array_column($parsed['days'], 'destination_ids'))));
        $towns = Municipality::whereIn('id', $townIds)->get(['id', 'name', 'latitude', 'longitude'])->keyBy('id');
        $food = FoodBeverage::approved()->with('estimates')->whereIn('municipality_id', $townIds)
            ->whereNotNull('latitude')->whereNotNull('longitude')->get()
            ->each(fn ($f) => $this->applyEstimates($f));
        $shops = $this->souvenirShopQuery()->whereIn('municipality_id', $townIds)
            ->whereNotNull('latitude')->whereNotNull('longitude')->get();

        // The stay the tourist picked before generating (validated: approved, in one of the towns).
        $picked = ! empty($preferences['accommodation_id'])
            ? Accommodation::approved()->with(['municipality:id,name', 'estimates'])->find($preferences['accommodation_id'])
            : null;
        $stays = [];
        $centers = [];
        foreach ($townIds as $sort => $town) {
            // Without destinations there (a Food or Shopping trip), the middle of its restaurants and shops.
            $center = $this->centroid($chosen->where('municipality_id', $town))
                ?? $this->centroid($destinations->where('municipality_id', $town))
                ?? $this->centroid($food->where('municipality_id', $town)->concat($shops->where('municipality_id', $town)))
                ?? $this->centroid($towns->only([$town]));
            $name = $towns[$town]->name ?? 'this town';
            $centers[$town] = $center ? ['name' => $name, 'point' => $center] : null;
            // No accommodation needed: no stay anywhere, so no rooms and no room costs.
            if (! $this->needsStay($preferences)) {
                continue;
            }
            // The tourist's pick is the stay for its own town; other towns get the usual choice.
            [$stay, $reason, $note] = match (true) {
                $picked && (int) $picked->municipality_id === (int) $town => [
                    $picked,
                    "The stay you chose in {$name}".($center ? ', '.number_format($this->km($center, $picked), 1).' km from the center of the places you\'ll visit there' : '').'.',
                    null,
                ],
                (bool) $center => $this->chooseStay($center, $preferences, $town, $name),
                default => [null, null, null],
            };

            $stays[$town] = [
                'municipality_id' => $town,
                'town' => $name,
                'accommodation' => $stay,
                'reason' => $reason,
                'note' => $note,
                'night_days' => [],
                'rooms' => collect(),
                'cost_min' => null,
                'cost_max' => null,
                'sort' => $sort,
            ];
        }

        return [
            'stays' => $stays,
            'food' => $food,
            'shops' => $shops,
            'centers' => $centers,
        ];
    }

    /** Whether the tourist wants the planner to book a stay (true unless they said they don't need one). */
    private function needsStay(array $preferences): bool
    {
        return filter_var($preferences['accommodation_needed'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Rooms for the group at the tourist's chosen room: that room first, then more of the same
     * room type (approved, priced, not marked occupied), cheapest first, until everyone has a
     * bed. Returns the rooms and how many they sleep (less than $travelers when the type runs out).
     *
     * @return array{0: Collection, 1: int}
     */
    public function roomsForGroup(AccommodationRoom $room, int $travelers): array
    {
        $sameType = AccommodationRoom::approved()->where('accommodation_id', $room->accommodation_id)
            ->where('category', $room->category)->where('occupancy_status', 'unoccupied')->where('price', '>', 0)
            ->whereKeyNot($room->id)->orderBy('price')->orderBy('id')->get();
        $chosen = collect();
        $sleeps = 0;
        foreach ($sameType->prepend($room) as $candidate) {
            if ($sleeps >= $travelers) {
                break;
            }
            $chosen->push($candidate);
            $sleeps += self::ROOM_CAPACITY[$candidate->category] ?? 2;
        }

        return [$chosen, $sleeps];
    }

    /**
     * The approved accommodation nearest $center, in $town when it has any (the whole province
     * otherwise). Among those within PREFERENCE_RADIUS_KM of the nearest, one matching the
     * tourist's type and budget wins; when that isn't possible the note says so.
     *
     * @return array{0: ?Accommodation, 1: ?string, 2: ?string}
     */
    private function chooseStay(array $center, array $preferences, int $town, string $townName): array
    {
        $pool = Accommodation::approved()->with(['municipality:id,name', 'estimates'])->where('municipality_id', $town)
            ->whereNotNull('latitude')->whereNotNull('longitude')->get();
        $inTown = $pool->isNotEmpty();
        if (! $inTown) {
            $pool = Accommodation::approved()->with(['municipality:id,name', 'estimates'])
                ->whereNotNull('latitude')->whereNotNull('longitude')->get();
        }

        $typeMatches = $this->accommodationTypeMatcher($preferences);
        $budget = $this->budgetLevel($preferences);
        $pick = $this->pickPlace($pool, [], fn ($a) => $this->km($center, $a), null, fn () => true,
            fn ($a) => ($typeMatches === null || $typeMatches($a) ? 2 : 0) + ($a->price_range === $budget ? 1 : 0));

        if (! $pick) {
            return [null, null, null];
        }

        $stay = $pick['place'];
        $km = number_format($pick['km'], 1);
        $typeMatched = $typeMatches === null || $typeMatches($stay);
        // Whether any stay in the pool is listed under the type asked for (many are just "Accommodation").
        $typeListed = $typeMatches === null || $pool->contains(fn ($a) => $typeMatches($a));
        // A cheaper stay than the budget is no reason to apologize.
        $levels = ['budget' => 1, 'moderate' => 2, 'luxury' => 3];
        $budgetMatched = ($levels[$stay->price_range] ?? 0) <= ($levels[$budget] ?? 3);

        $reason = "Your base in {$townName}, {$km} km from the center of the places you'll visit there, so those days start and end with a short trip.";

        if ($typeMatched && $budgetMatched && $inTown) {
            return [$stay, $reason, null];
        }

        $typeLabel = $this->accommodationTypeLabel($preferences);
        $wanted = ($typeMatched || ! $typeListed ? 'accommodation' : $typeLabel)
            .($budgetMatched ? '' : ' in the '.ucfirst($budget).' price range');
        $unlisted = $typeMatched || $typeListed ? '' : "None of the verified stays in {$townName} is listed as a {$typeLabel} yet. ";
        $note = $inTown
            ? $unlisted.($wanted === 'accommodation' ? '' : "We're sorry — we couldn't find a verified {$wanted} near your destinations in {$townName}. ")
            : "We're sorry — there's no verified accommodation in {$townName} yet"
                .($typeMatched && $budgetMatched ? '' : ", and no {$wanted} close by").'. ';
        // A stay listed only as "Accommodation" doesn't say what kind it is.
        $kind = in_array(mb_strtolower(trim((string) $stay->type)), ['', 'accommodation'], true) ? 'verified stay whose type isn\'t specified' : $stay->type;
        $note .= "We recommended {$stay->name} instead: {$this->describeListing($kind, $stay->price_range)}, {$km} km from the center of your destinations in {$townName}"
            .($inTown ? '' : ' (in '.($stay->municipality?->name ?? 'a nearby town').')')
            .', so those days\' travel stays short.';

        return [$stay, $reason, $note];
    }

    /**
     * Books every night of the trip (after days 1 … num_days − 1, at least one) at the stay of
     * the town that day ends in, then each stay's rooms for the group and its cost for its
     * nights. A town where no night is spent keeps its stay as a suggestion, without rooms.
     *
     * @param  array<int, int>  $dayTowns  day number => town the day ends in
     * @return array<int, array>
     */
    private function bookNights(array $stays, array $dayTowns, array $preferences): array
    {
        $numDays = (int) $preferences['num_days'];
        foreach (range(1, max($numDays - 1, 1)) as $night) {
            $town = $dayTowns[$night] ?? array_key_first($stays);
            if (isset($stays[$town])) {
                $stays[$town]['night_days'][] = $night;
            }
        }

        $travelers = $this->travelers($preferences);
        foreach ($stays as $town => $stay) {
            $nights = count($stay['night_days']);
            if ($nights === 0) {
                $stays[$town]['note'] = trim(($stay['note'] ?? '')." You pass through {$stay['town']} during the day — this stay is here if you'd like to spend a night.");

                continue;
            }
            if (! $stay['accommodation']) {
                continue;
            }

            $pickedRoom = ! empty($preferences['room_id']) && (int) ($preferences['accommodation_id'] ?? 0) === (int) $stay['accommodation']->id
                ? AccommodationRoom::approved()->where('accommodation_id', $stay['accommodation']->id)->find($preferences['room_id'])
                : null;
            [$rooms, $sleeps] = $pickedRoom
                ? $this->roomsForGroup($pickedRoom, $travelers)
                : $this->recommendRooms($stay['accommodation'], $preferences);
            if ($rooms->isNotEmpty() && $sleeps < $travelers) {
                $stays[$town]['note'] = trim(($stay['note'] ?? '')." This stay has rooms for {$sleeps} of your {$travelers} travelers — contact them about extra beds.");
            }
            [$stays[$town]['cost_min'], $stays[$town]['cost_max']] = $this->roomsCost($stay['accommodation'], $rooms, $nights, $preferences);
            $stays[$town]['rooms'] = $rooms;
        }

        return array_values($stays);
    }

    /**
     * One itinerary_items row per stop, on a clock in minutes after midnight, and the town each
     * day is spent in (where that night is spent). A day runs:
     * - Day 1: arrival at the stay, at the tourist's arrival time (else around DEFAULT_ARRIVAL,
     *   which the stop says). Checking in waits for the stay's listed check-in time when it has
     *   one; with none, the stop says to check in if the room is ready. Breakfast only after an
     *   early arrival, lunch only if it's still lunchtime, no attraction that doesn't fit what's
     *   left of the day, and no dinner after a late-night arrival.
     *   Other days: breakfast near last night's stay (a packed one on a sunrise day), after
     *   checking out on the last day or when moving to another town's stay.
     * - The day's destinations in orderStops() order, each for its own listed activities,
     *   arriving after the travel and opening time; a sunset or evening one starts just in
     *   time. After a swim or a hike there's time to freshen up; a later stop that would run
     *   past the day's end is left out, and a visit is shortened to end by closing time.
     * - Lunch between 11:30 and 1:30 near where the tourist is (before, during or between
     *   visits), else the destination's picnic or a packed lunch; a merienda around 3 PM when
     *   the afternoon has room; rest at the stay instead of a long wait; souvenir shopping on
     *   the last day (every day for a shopping interest); dinner near where the day ends,
     *   after checking in at the new stay on a moving day.
     * No destination, food place or shop repeats; when no unused food place is left, the meal
     * is at the stay.
     *
     * @return array{0: array<int, array>, 1: array<int, int>}
     */
    private function buildDayItems(array $days, Collection $destinations, array $plan, array $preferences): array
    {
        $pace = $preferences['travel_pace'];
        $settings = $this->pace($pace);
        $numDays = (int) $preferences['num_days'];
        $travelers = $this->travelers($preferences);
        $startDate = ! empty($preferences['start_date']) ? Carbon::parse($preferences['start_date']) : null;
        $arrivalAt = $this->arrivalMinutes($preferences);
        $departAt = $this->departureMinutes($preferences);
        $shopDaily = in_array('shopping', $preferences['interests'] ?? [], true);
        // With Food selected, meals look a little farther for a verified restaurant before falling back to the stay.
        $mealKm = in_array('food', $preferences['interests'] ?? [], true) ? self::FOOD_NEARBY_KM : self::NEARBY_KM;
        // Food and/or Shopping without any sightseeing interest: days have no destinations by design.
        $sightseeing = array_filter($preferences['interests'] ?? [], fn ($i) => (self::INTEREST_CATEGORIES[$i] ?? []) !== []);
        $foodScore = fn ($f) => $this->foodScore($f, $preferences);
        // Whether the trip's towns list any place of the cuisine asked for; when none do, the
        // first meal says so once rather than every meal apologizing.
        $cuisine = $this->cuisineMatcher($preferences);
        $cuisineListed = $cuisine === null || $plan['food']->contains(fn ($f) => $cuisine($f));
        $cuisineExplained = false;
        $stayIn = fn (?int $town) => $town === null ? null : ($plan['stays'][$town]['accommodation'] ?? null);
        $usedFood = [];
        $usedShops = [];
        $items = [];
        $dayTowns = [];
        $lastTown = (int) $preferences['municipality_ids'][0];
        $byId = $destinations->keyBy(fn ($d) => (int) $d->id);
        // Stops that didn't fit their day, waiting for a later day: id => [day, reason].
        $carried = [];

        foreach ($days as $dayNumber => $day) {
            $date = $startDate?->copy()->addDays($dayNumber - 1);
            $arrivalDay = $dayNumber === 1;
            $lastDay = $numDays > 1 && $dayNumber === $numDays;
            // The departure day ends when the tourist leaves: nothing in it may run past then.
            $dayEnd = $dayNumber === $numDays ? $departAt : null;
            $endsInTime = fn (int $end) => $dayEnd === null || $end <= $dayEnd;
            $leaveNote = $dayEnd === null ? null : "You leave around {$this->formatMinutes($dayEnd)}";
            $picked = collect($day['destination_ids'])->map(fn ($id) => $destinations->firstWhere('id', $id))->filter()->values();
            $town = $picked->isNotEmpty() ? (int) $picked->first()->municipality_id : ($day['town'] ?? $lastTown);
            // A stop that didn't fit an earlier day comes along when this day, in the same town,
            // has room for it under the same day rules, if need be in place of a stop the planner
            // added (never one the AI picked); it's then scheduled like any other.
            $movedFrom = [];
            foreach ($carried as $id => [$fromDay, $reason]) {
                if ((int) $byId[$id]->municipality_id !== $town) {
                    continue;
                }
                $ids = $picked->map(fn ($d) => (int) $d->id)->all();
                $fits = $this->fitsDay($ids, $byId[$id], $dayNumber, $byId, $preferences);
                $replace = null;
                foreach ($fits ? [] : array_reverse(array_values(array_intersect($day['added'] ?? [], $ids))) as $filler) {
                    if ($this->fitsDay(array_values(array_diff($ids, [$filler])), $byId[$id], $dayNumber, $byId, $preferences)) {
                        $replace = $filler;
                        break;
                    }
                }
                if ($fits || $replace !== null) {
                    $picked = $picked->reject(fn ($d) => (int) $d->id === $replace)->values();
                    $picked->push($byId[$id]);
                    $day['reasons'][$id] = $reason;
                    $movedFrom[$id] = $fromDay;
                    unset($carried[$id]);
                }
            }
            // Without any stay (none needed, or none in the town): meals on a day with no stop are
            // found around the middle of the town's places.
            $center = $plan['centers'][$town] ?? null;
            $townCenter = $center ? (new Municipality)->forceFill([
                'id' => $town, 'name' => "the center of {$center['name']}", 'latitude' => $center['point'][0], 'longitude' => $center['point'][1],
            ]) : null;
            // Where last night was spent (day 1 starts at the stay of its own town), and tonight.
            $home = $stayIn($arrivalDay ? $town : ($dayTowns[$dayNumber - 1] ?? $town)) ?? $stayIn($town);
            $tonight = $stayIn($town) ?? $home;
            $moving = ! $arrivalDay && $home && $tonight && $home->id !== $tonight->id;
            // Where the tourist rests during the day: tonight's stay once they've moved on.
            $base = $moving ? $tonight : $home;
            $stops = $this->orderStops($picked, $home, $pace, $arrivalDay);
            $sequence = 1;
            $prev = $home;
            $clock = 0;
            $hadLunch = false;
            $hadMerienda = false;
            $shopped = false;
            $checkedIn = ! $moving;
            // On Day 1, the stay's listed check-in time when the tourist arrives before it.
            $checkInAt = null;
            // Arriving after lunchtime: no lunch to plan, or to miss.
            $lateArrival = $arrivalDay && ($arrivalAt ?? self::DEFAULT_ARRIVAL) + self::ARRIVAL_MINUTES > self::LATEST_LUNCH - 30;

            $add = function (string $type, $place, int $start, int $minutes, array $fields, $from) use (&$items, &$sequence, $dayNumber) {
                $fields['notes'] = $this->withTravelNote($fields['notes'] ?? null, $from, $place, $start);
                $items[] = $this->itemRow($type, $place, $from, $dayNumber, $sequence++, $fields + [
                    'time_slot' => $this->timeRange($start, $minutes),
                    'duration_minutes' => $minutes,
                ]);
            };
            $appendNote = function (string $text) use (&$items, $dayNumber) {
                $last = array_key_last($items);
                if ($last !== null && $items[$last]['day_number'] === $dayNumber) {
                    $items[$last]['preference_note'] = $this->joinNotes($items[$last]['preference_note'] ?? null, $text);
                }
            };
            $addFood = function (array $pick, int $start, int $minutes, string $notes, string $reason, string $meal, $near, $from) use ($add, &$usedFood, &$cuisineExplained, $cuisineListed, $preferences) {
                $usedFood[] = $pick['place']->id;
                $add(ItineraryItem::TYPE_FOOD_BEVERAGE, $pick['place'], $start, $minutes, [
                    'food_beverage_id' => $pick['place']->id,
                    'notes' => $notes,
                    'reason' => $reason,
                    'preference_note' => $this->foodNote($pick['place'], $meal, $near, $preferences, $cuisineListed, ! $cuisineExplained),
                ] + $this->dishPlan($pick['place'], $preferences, $meal), $from);
                $cuisineExplained = true;
            };
            // $searched: where an unused food place was looked for ("near X or near Y").
            $addMealAtStay = function (string $meal, int $start, string $searched, $from, Accommodation $stay) use ($add) {
                $add(ItineraryItem::TYPE_ACCOMMODATION, $stay, $start, $meal === 'breakfast' ? self::BREAKFAST_MINUTES : self::MEAL_MINUTES, [
                    'accommodation_id' => $stay->id,
                    'notes' => ucfirst($meal)." at {$stay->name}.",
                    'reason' => "Your accommodation, so you can enjoy {$meal} without another trip.",
                    'preference_note' => $this->mealAtStayNote($meal, $searched, $stay),
                    'cost_note' => ucfirst($meal).' at your accommodation — price not listed',
                ], $from);
            };
            // A food place open around $at near $near, else on the way from $near to $then.
            $findMeal = function ($near, $then, int $at, string $why, ?string $meal = null) use ($plan, &$usedFood, $foodScore, $date, $preferences, $mealKm) {
                $open = fn ($f) => $this->isOpen($f, $at, $at + self::MEAL_MINUTES, $date);
                $foodScore = $meal ? fn ($f) => $this->foodScore($f, $preferences, $meal) : $foodScore;
                $pick = $this->pickPlace($plan['food'], $usedFood, fn ($f) => $this->kmBetween($near, $f), $mealKm, $open, $foodScore);
                if ($pick) {
                    return [$pick, $this->mealReason($pick, $near, $why, $preferences)];
                }
                if ($then && $then !== $near) {
                    $pick = $this->pickPlace($plan['food'], $usedFood,
                        fn ($f) => $this->kmBetween($near, $f) + $this->kmBetween($f, $then) - $this->kmBetween($near, $then),
                        self::MAX_DETOUR_KM, $open, $foodScore);
                    if ($pick) {
                        return [$pick, "There's no unused verified food place right next to {$near->name}; this one is on the way to {$then->name}{$this->detourNote($pick['km'])}."];
                    }
                }

                return [null, null];
            };
            // Time at a stay (rest, or checking in) until $until, if it's worth the trip; a stay
            // not checked into yet can't be used before its listed check-in time.
            $restAt = function (Accommodation $stay, int $until, string $notes, string $reason, int $minimum = 45) use (&$prev, &$clock, &$checkedIn, &$checkInAt, $add, $tonight): bool {
                $start = $clock + $this->travelMinutes($prev, $stay);
                if ($this->samePlace($stay, $tonight) && ! $checkedIn && $checkInAt !== null) {
                    $start = max($start, $checkInAt);
                }
                if ($until - $start < $minimum) {
                    return false;
                }
                $add(ItineraryItem::TYPE_ACCOMMODATION, $stay, $start, $until - $start, [
                    'accommodation_id' => $stay->id,
                    'notes' => $notes,
                    'reason' => $reason,
                ], $prev);
                $prev = $stay;
                $clock = $until;
                $checkedIn = $checkedIn || $this->samePlace($stay, $tonight);

                return true;
            };
            $restNotes = function (string $then) use (&$checkedIn, &$prev, $base): string {
                if (! $checkedIn) {
                    return "Check in at {$base->name} and rest {$then}.";
                }

                return $this->samePlace($prev, $base)
                    ? "Free time at {$base->name}: rest or enjoy its amenities {$then}."
                    : "Back to {$base->name} to rest and freshen up {$then}.";
            };
            // A morning with nothing on until lunch is spent at the stay rather than waiting around.
            $freeMorning = function () use (&$clock, $base, $lastDay, $restAt, $restNotes, $endsInTime) {
                if ($base && ! $lastDay && $endsInTime(690) && 690 - $clock >= self::IDLE_MINUTES) {
                    $restAt($base, 690, $restNotes('before lunch'), 'An unhurried break, so the day never feels rushed.');
                }
            };
            // Lunch near where the tourist is (or on the way to $then), else near the stay, else at
            // the stay; a message when none is possible. Past LATEST_LUNCH there's no lunch at all.
            $lunch = function ($then) use (&$prev, &$clock, &$hadLunch, &$usedFood, $findMeal, $addFood, $addMealAtStay, $home, $tonight, $lateArrival, $plan, $foodScore, $mealKm, $date, $preferences, $endsInTime, $dayEnd, $leaveNote): ?string {
                $hadLunch = true;
                $from = $prev ?? $then;
                if (! $from) {
                    return null;
                }
                // Past lunchtime: nothing to plan after a late arrival, else say so rather than skip it silently.
                if ($clock > self::LATEST_LUNCH) {
                    return $lateArrival ? null : "There's no time for a sit-down lunch in today's plan, so bring snacks or grab something on the way.";
                }
                $earliest = 690;
                // Leaving before a sit-down lunch would be over.
                if (! $endsInTime(max($clock, $earliest) + self::MEAL_MINUTES)) {
                    return $clock < $dayEnd ? "{$leaveNote}, before a sit-down lunch would be over, so eat on the way or bring snacks." : null;
                }
                [$pick, $why] = $findMeal($from, $then, max($clock, $earliest), $then ? "a break before {$then->name}" : "a break near {$from->name}");
                if ($pick && ($clock + $this->travelMinutes($from, $pick['place']) > self::LATEST_LUNCH
                    || ! $endsInTime(max($clock + $this->travelMinutes($from, $pick['place']), $earliest) + self::MEAL_MINUTES))) {
                    $pick = null;
                }
                if ($pick) {
                    $lunchAt = max($clock + $this->travelMinutes($from, $pick['place']), $earliest);
                    $addFood($pick, $lunchAt, self::MEAL_MINUTES, "Lunch near {$from->name}.", $why, 'lunch', $from, $prev);
                    $prev = $pick['place'];
                    $clock = $lunchAt + self::MEAL_MINUTES;

                    return null;
                }
                $stay = $then ? $home : $tonight;
                // Before settling for the stay, an unused food place near it that's open in time.
                if ($stay && ! $this->samePlace($from, $stay)) {
                    $at = fn ($f) => max($clock + $this->travelMinutes($from, $f), $earliest);
                    $pick = $this->pickPlace($plan['food'], $usedFood, fn ($f) => $this->kmBetween($stay, $f), $mealKm,
                        fn ($f) => $at($f) <= self::LATEST_LUNCH && $endsInTime($at($f) + self::MEAL_MINUTES) && $this->isOpen($f, $at($f), $at($f) + self::MEAL_MINUTES, $date), $foodScore);
                    if ($pick) {
                        $lunchAt = $at($pick['place']);
                        $addFood($pick, $lunchAt, self::MEAL_MINUTES, "Lunch near {$stay->name}.",
                            "There's no unused verified food place near {$from->name}, so lunch is back near your accommodation: "
                            .$this->mealReason($pick, $stay, 'an easy break', $preferences), 'lunch', $stay, $prev);
                        $prev = $pick['place'];
                        $clock = $lunchAt + self::MEAL_MINUTES;

                        return null;
                    }
                }
                if ($stay && $clock + $this->travelMinutes($prev, $stay) <= self::LATEST_LUNCH
                    && $endsInTime(max($clock + $this->travelMinutes($prev, $stay), $earliest) + self::MEAL_MINUTES)) {
                    $lunchAt = max($clock + $this->travelMinutes($prev, $stay), $earliest);
                    $addMealAtStay('lunch', $lunchAt, $this->samePlace($from, $stay) ? "near {$stay->name}" : "near {$from->name} or near {$stay->name}", $prev, $stay);
                    $prev = $stay;
                    $clock = $lunchAt + self::MEAL_MINUTES;

                    return null;
                }

                return "We're sorry — there's no unused verified food place near {$from->name}".($then ? " or on the way to {$then->name}" : '').', so please bring a packed lunch.';
            };
            // An afternoon merienda (snack) near where the tourist is, if a food place is close.
            $merienda = function () use (&$prev, &$clock, &$hadMerienda, $findMeal, $addFood, $endsInTime) {
                if ($hadMerienda || ! $prev) {
                    return;
                }
                [$snack, $why] = $findMeal($prev, null, max($clock, 900), 'a light afternoon merienda', 'merienda');
                $at = $snack ? max($clock + $this->travelMinutes($prev, $snack['place']), 900) : null;
                if (! $snack || ! $endsInTime($at + self::MERIENDA_MINUTES)) {
                    return;
                }
                $hadMerienda = true;
                $addFood($snack, $at, self::MERIENDA_MINUTES, "Afternoon merienda (snack) near {$prev->name}.", $why, 'merienda', $prev, $prev);
                $prev = $snack['place'];
                $clock = $at + self::MERIENDA_MINUTES;
            };
            // A souvenir shop near where the tourist is (a little farther on the last day), open
            // then, where shopping is over by $endBy (so it never pushes out lunch or dinner).
            $goShopping = function (int $endBy) use (&$prev, &$clock, &$usedShops, &$shopped, $plan, $add, $dayNumber, $numDays, $date, $preferences, $dayEnd) {
                $endBy = $dayEnd === null ? $endBy : min($endBy, $dayEnd);
                $shopped = true;
                $site = $prev;
                if (! $site) {
                    return;
                }
                $start = fn ($s) => $clock + $this->travelMinutes($site, $s);
                $shop = $this->pickPlace($plan['shops'], $usedShops, fn ($s) => $this->kmBetween($site, $s),
                    $dayNumber === $numDays ? self::LAST_DAY_SHOP_KM : self::SHOP_RADIUS_KM,
                    fn ($s) => $start($s) + self::SHOPPING_MINUTES <= $endBy && $this->isOpen($s, $start($s), $start($s) + self::SHOPPING_MINUTES, $date));
                if (! $shop) {
                    return;
                }
                $usedShops[] = $shop['place']->id;
                $start = $clock + $this->travelMinutes($site, $shop['place']);
                $add(ItineraryItem::TYPE_RETAIL_SHOP, $shop['place'], $start, self::SHOPPING_MINUTES, [
                    'retail_shop_id' => $shop['place']->id,
                    'notes' => "Pick up pasalubong — local souvenirs and delicacies — near {$site->name}.",
                    'reason' => 'The closest verified souvenir shop to '.$site->name.' ('.number_format($shop['km'], 1).' km).',
                ] + $this->souvenirPlan($shop['place'], $preferences), $site);
                $prev = $shop['place'];
                $clock = $start + self::SHOPPING_MINUTES;
            };

            $plans = $stops->mapWithKeys(fn ($d) => [$d->id => $this->stretchVisit(
                $this->plannedActivities($d, $d, $pace, $this->openingHours($d->operating_hours, $date), $arrivalDay),
                $this->minimumVisit($d, $pace)
            )])->all();
            $first = $stops->first();
            $sunriseDay = $first && collect($plans[$first->id])->contains(fn ($a) => $a['label'] === 'dawn');
            $morningNote = null;

            if ($arrivalDay) {
                $arrive = $arrivalAt ?? self::DEFAULT_ARRIVAL;
                $prev = null;
                $clock = $arrive;
                if ($home) {
                    // Check in on arrival unless the stay lists a later check-in time; with no
                    // listed time, only if the room is ready (never assumed).
                    $listed = $this->checkInMinutes($home);
                    $usual = $listed !== null && $this->checkInEstimated($home);
                    $checkedIn = $listed === null || $arrive >= $listed;
                    $checkInAt = $checkedIn ? null : $listed;
                    $arrivalNote = match (true) {
                        $listed === null => "Arrive at {$home->name} and check in if your room is ready. ",
                        $checkedIn => "Arrive and check in at {$home->name}. ",
                        default => "Arrive at {$home->name}. ",
                    };
                    $when = $arrivalAt !== null
                        ? 'You arrive around '.$this->formatMinutes($arrivalAt).'.'
                        : 'No arrival time was given, so Day 1 assumes you arrive around '.$this->formatMinutes(self::DEFAULT_ARRIVAL).'; shift the day\'s times if you arrive earlier or later.';
                    $minutes = min(self::ARRIVAL_MINUTES, 1439 - $arrive);
                    $add(ItineraryItem::TYPE_ACCOMMODATION, $home, $arrive, $minutes, [
                        'accommodation_id' => $home->id,
                        'notes' => $arrivalNote.$when,
                        'reason' => $checkedIn
                            ? 'Your base for this part of the trip: settle in and freshen up before heading out.'
                            : 'Your base for this part of the trip. '.($usual ? 'Check-in usually starts around ' : 'Its listed check-in starts at ')."{$this->formatMinutes($listed)}, so you check in later today.",
                        'preference_note' => match (true) {
                            $listed === null => "{$home->name} doesn't list a check-in time, so confirm with them that your room will be ready when you arrive.",
                            ! $checkedIn && $usual => "Check-in usually starts around {$this->formatMinutes($listed)} (a typical time, not one {$home->name} lists); ask them before you arrive whether you can check in earlier or leave your bags with them.",
                            ! $checkedIn => "Your room may not be ready before the listed check-in at {$this->formatMinutes($listed)}; ask {$home->name} before you arrive whether you can leave your bags with them.",
                            default => null,
                        },
                    ], null);
                    $prev = $home;
                    $clock += $minutes;
                }
                // An early arrival still gets breakfast near the stay.
                $near = $prev ?? $first ?? $townCenter;
                if ($near && $clock <= 570 && $endsInTime($clock + self::BREAKFAST_MINUTES)) {
                    $breakfast = $this->pickPlace($plan['food'], $usedFood, fn ($f) => $this->kmBetween($near, $f), $mealKm,
                        fn ($f) => $this->isOpen($f, $clock + $this->travelMinutes($near, $f), $clock + $this->travelMinutes($near, $f) + self::BREAKFAST_MINUTES, $date), $foodScore);
                    if ($breakfast) {
                        $at = $clock + $this->travelMinutes($prev, $breakfast['place']);
                        $addFood($breakfast, $at, self::BREAKFAST_MINUTES, "Breakfast near {$near->name}.",
                            $this->mealReason($breakfast, $near, 'an easy start before heading out', $preferences), 'breakfast', $near, $prev);
                        $prev = $breakfast['place'];
                        $clock = $at + self::BREAKFAST_MINUTES;
                    }
                }
            } elseif ($sunriseDay) {
                // Breakfast is packed: the day starts before breakfast places open.
                $clock = self::WINDOWS['dawn'][0] - $this->travelMinutes($home, $first);
                $morningNote = 'Breakfast places are still closed this early: bring breakfast from your accommodation to enjoy after sunrise.';
                if (($lastDay || $moving) && $home) {
                    $morningNote .= " Check out of {$home->name} before you leave.";
                }
                // A day that starts before 5 AM doesn't also run into the night.
                $plans = array_map(fn ($planned) => array_map(fn ($a) => $a['label'] === 'evening' ? $this->outsideWindow($a) : $a, $planned), $plans);
            } else {
                $breakfastAt = $this->minutesOf($settings['breakfast']);
                $near = $home ?? $first ?? $townCenter;
                $breakfastFits = $endsInTime($breakfastAt + self::BREAKFAST_MINUTES);
                $breakfast = $near && $breakfastFits ? $this->pickPlace($plan['food'], $usedFood, fn ($f) => $this->kmBetween($near, $f), $mealKm,
                    fn ($f) => $this->isOpen($f, $breakfastAt, $breakfastAt + self::BREAKFAST_MINUTES, $date), $foodScore) : null;

                // Leaving this stay today: check out first, in time to reach breakfast.
                if (($lastDay || $moving) && $home) {
                    $checkout = $breakfastAt - self::CHECKOUT_MINUTES - ($breakfast ? $this->travelMinutes($home, $breakfast['place']) : 0);
                    if ($dayEnd !== null) {
                        $checkout = max(0, min($checkout, $dayEnd - self::CHECKOUT_MINUTES));
                    }
                    $add(ItineraryItem::TYPE_ACCOMMODATION, $home, $checkout, self::CHECKOUT_MINUTES, [
                        'accommodation_id' => $home->id,
                        'notes' => "Check out of {$home->name}.",
                        'reason' => $lastDay
                            ? 'Your last morning: settle up before heading out, and ask the front desk whether they can keep your bags if you leave later in the day.'
                            : "You move on to {$tonight->name} today: settle up and take your bags along.",
                        'preference_note' => match (true) {
                            ! $lastDay => null,
                            $dayEnd !== null => "{$leaveNote}, so today's plan ends by then. Leave yourself time to get to your transport.",
                            default => "We don't know your departure time, so today's plan runs through dinner; skip the later stops if you need to leave earlier.",
                        },
                    ], null);
                }

                // Leaving before breakfast would be over: nothing more today.
                $clock = $breakfastFits ? $breakfastAt + self::BREAKFAST_MINUTES : $dayEnd;
                if (! $breakfastFits) {
                    $appendNote("{$leaveNote}, before breakfast would be over, so eat on the way.");
                }
                if ($breakfast) {
                    $addFood($breakfast, $breakfastAt, self::BREAKFAST_MINUTES, "Breakfast near {$near->name}.",
                        $this->mealReason($breakfast, $near, 'an easy start before heading out', $preferences), 'breakfast', $near, $prev);
                    $prev = $breakfast['place'];
                } elseif ($home && $breakfastFits) {
                    $addMealAtStay('breakfast', $breakfastAt, "near {$home->name}", null, $home);
                }
            }

            $lastKind = null;
            foreach ($stops as $index => $destination) {
                $planned = $plans[$destination->id];
                $hours = $this->openingHours($destination->operating_hours, $date);
                $kind = $this->experienceKind($destination);
                $note = $index === 0 ? $morningNote : null;

                // Time to rinse off and change after a swim or a hike, before moving on.
                if (in_array($lastKind, ['water', 'highland'], true)) {
                    $clock += self::FRESHEN_UP_MINUTES;
                }

                // The first day in a new town starts with the drive from last night's town.
                $drive = $this->travelMinutes($prev, $destination);
                if ($index === 0 && $home && ! $arrivalDay && ($dayTowns[$dayNumber - 1] ?? $town) !== $town && $drive > 60) {
                    $note = trim("{$note} Today starts with the ~{$drive} min drive from {$home->name} to {$destination->municipality?->name}.");
                }

                // A sunset or evening destination starts just in time, not hours early.
                $latest = ! ($index === 0 && $sunriseDay) && $this->isAnchor($destination) ? $this->latestArrival($destination, $pace, $date) : null;
                $arrival = function () use (&$clock, &$prev, $hours, $destination, $latest) {
                    $at = $clock + $this->travelMinutes($prev, $destination);
                    if ($hours) {
                        $at = max($at, $hours[0]);
                    }

                    return $latest !== null ? max($at, $latest) : $at;
                };
                $layout = $this->layoutVisit($arrival(), $planned);

                // A destination with its own food serves lunch: a visit that starts at lunchtime
                // begins with lunch there, instead of a restaurant stop on the way.
                $onSiteLunch = in_array('lunch', (array) ($destination->meals_offered ?? []), true) && $destination->dining_details;
                $siteLunch = fn (int $start) => ['at' => [$start, $start + self::MEAL_MINUTES], 'name' => 'Lunch at the on-site dining area', 'duration' => self::MEAL_MINUTES,
                    'label' => 'midday', 'window' => self::WINDOWS['midday'], 'description' => $destination->dining_details];
                $lunchNote = "Lunch is at {$destination->name}'s own dining area, so there's no separate restaurant stop; prices aren't listed on SOSOGON.";
                if (! $hadLunch && $onSiteLunch && $layout['start'] >= 690 && $layout['start'] <= self::LATEST_LUNCH - self::MEAL_MINUTES) {
                    $hadLunch = true;
                    $planned[] = $siteLunch($layout['start']);
                    $layout = $this->layoutVisit($arrival(), $planned);
                    $note = trim("{$note} {$lunchNote}");
                }
                // Lunch first when it's already late morning, or the visit starts after 11:30.
                if (! $hadLunch && ($clock >= 660 || $layout['start'] >= 690)) {
                    $freeMorning();
                    if ($missing = $lunch($destination)) {
                        $note = trim("{$note} {$missing}");
                    }
                    $layout = $this->layoutVisit($arrival(), $planned);
                }
                // A merienda before an afternoon visit, unless it would leave no time for a stop
                // that's only planned if it fits.
                $optional = $index > 0 || $arrivalDay;
                if ($hadLunch && $clock >= 870 && $clock <= 960
                    && (! $optional || $this->visitEnd($planned, $pace, $hours) >= $layout['end'] + self::MERIENDA_MINUTES + 10)) {
                    $merienda();
                    $layout = $this->layoutVisit($arrival(), $planned);
                }
                // A long wait (for a sunset stop) is spent resting at the stay.
                if ($base && ! $lastDay && $endsInTime($layout['start']) && $layout['start'] - ($clock + $this->travelMinutes($prev, $destination)) >= self::IDLE_MINUTES
                    && $restAt($base, $layout['start'] - $this->travelMinutes($base, $destination), $restNotes("before heading to {$destination->name}"), 'A breather in the heat of the day, so you are fresh for the late-afternoon stop.')) {
                    $layout = $this->layoutVisit($arrival(), $planned);
                }
                // The visit ends by closing time and, for a later stop or any stop on the arrival
                // day, by the end of the day.
                $limit = $optional ? $this->visitEnd($planned, $pace, $hours) : ($hours[1] ?? null);
                if ($dayEnd !== null) {
                    $limit = min($limit ?? $dayEnd, $dayEnd);
                }
                if ($limit !== null && $layout['end'] > $limit) {
                    $planned = $this->shortenVisit($planned, $layout['end'] - $limit);
                    $layout = $this->layoutVisit($arrival(), $planned);
                }
                // Such a stop is left out when it would still run past the end of the day, or
                // would be cut shorter than a sensible visit. A visit that ends in time for lunch
                // is followed by it; only a longer one breaks for lunch.
                $lunchInside = ! $hadLunch && $layout['start'] < 720 && $layout['end'] > self::LATEST_LUNCH - 15;
                $tooShort = array_sum(array_column($planned, 'duration'))
                    < min($this->minimumVisit($destination, $pace), array_sum(array_column($plans[$destination->id], 'duration')));
                $pastDeparture = $dayEnd !== null && ($tooShort || $dayEnd < $layout['end'] + ($lunchInside ? self::MEAL_MINUTES : 0));
                if ($pastDeparture || ($optional && ($tooShort || $this->visitEnd($planned, $pace, $hours) < $layout['end'] + ($lunchInside ? self::MEAL_MINUTES : 0)))) {
                    // Not dropped from the trip: offered to the following days.
                    $carried[$destination->id] = [$movedFrom[$destination->id] ?? $dayNumber, $day['reasons'][$destination->id] ?? null];

                    continue;
                }

                // Lunch during the visit when it runs over noon.
                $lunchStop = null;
                if ($lunchInside) {
                    $hadLunch = true;
                    [$meal, $mealReason] = $findMeal($destination, null, 720, 'a short break from your visit');
                    $picnic = collect($planned)->search(fn ($a) => stripos($a['name'], 'picnic') !== false);
                    // One long activity is split around the break instead of starting after it.
                    $breakFor = function (int $from, int $to) use (&$planned, &$layout, $arrival) {
                        $planned = $this->splitAt($planned, $from - $arrival());
                        $layout = $this->layoutVisit($arrival(), $planned, [[$from, $to]]);
                    };
                    if ($onSiteLunch) {
                        $planned[] = $siteLunch(720);
                        $planned = $this->splitAt($planned, 720 - $arrival());
                        $layout = $this->layoutVisit($arrival(), $planned);
                        $note = trim("{$note} {$lunchNote}");
                    } elseif ($meal) {
                        $away = $this->travelMinutes($destination, $meal['place']);
                        $breakFor(720 - $away, 780 + $away);
                        $lunchStop = [$meal, $mealReason];
                    } elseif ($picnic !== false) {
                        $planned[$picnic] = ['at' => [720, 780], 'name' => "{$planned[$picnic]['name']} — your picnic lunch"] + $planned[$picnic];
                        $planned = $this->splitAt($planned, 720 - $arrival());
                        $layout = $this->layoutVisit($arrival(), $planned);
                        $note = trim("{$note} We're sorry — there's no unused verified food place near {$destination->name}, so lunch is a picnic here. Pack food from your accommodation or a store on the way.");
                    } else {
                        $breakFor(720, 780);
                        $note = trim("{$note} We're sorry — there's no unused verified food place near {$destination->name}, so please bring a packed lunch for around noon.");
                    }
                }

                // A picnic activity in the lunch window is lunch.
                foreach ($hadLunch ? [] : $layout['activities'] as $k => $activity) {
                    $slot = $this->slotMinutes($activity['time']);
                    if ($slot && stripos($activity['name'], 'picnic') !== false && $slot[0] >= 660 && $slot[0] <= self::LATEST_LUNCH) {
                        $hadLunch = true;
                        $layout['activities'][$k]['name'] .= ' — your picnic lunch';
                        $note = trim("{$note} Lunch is a picnic here: pack food from your accommodation or a store on the way.");
                        break;
                    }
                }

                $add(ItineraryItem::TYPE_DESTINATION, $destination, $layout['start'], $layout['end'] - $layout['start'], [
                    'destination_id' => $destination->id,
                    'notes' => $this->visitNotes($destination),
                    'reason' => $this->visitReason($day, $destination),
                    'activities' => $layout['activities'],
                    'preference_note' => $this->joinNotes(
                        $note,
                        isset($movedFrom[$destination->id]) ? "Moved here from Day {$movedFrom[$destination->id]}, where it didn't fit the time left that day." : null,
                        $this->visitTips($destination, $kind),
                    ),
                ] + $this->entranceCost($destination, $travelers), $prev);
                if ($lunchStop) {
                    $addFood($lunchStop[0], 720, self::MEAL_MINUTES, "Lunch break during your visit to {$destination->name}.", $lunchStop[1], 'lunch', $destination, $destination);
                }
                $prev = $destination;
                $clock = $layout['end'];
                $lastKind = $kind;
            }

            // A free day at the stay, unless the tourist only arrives late in the day.
            if ($stops->isEmpty() && $home && (! $arrivalDay || $clock + 120 <= $this->visitEndTime($pace))
                && $endsInTime($this->layoutVisit($clock + $this->travelMinutes($prev, $home), $this->plannedActivities(null, $home, $pace, null))['end'])) {
                // No destination left for today: a free day at the stay.
                $start = $clock + $this->travelMinutes($prev, $home);
                $layout = $this->layoutVisit($start, $this->plannedActivities(null, $home, $pace, null));
                $add(ItineraryItem::TYPE_ACCOMMODATION, $home, $layout['start'], $layout['end'] - $layout['start'], [
                    'accommodation_id' => $home->id,
                    'notes' => "Free day at {$home->name}.",
                    'reason' => 'A slower day to rest and enjoy your stay between outings.',
                    'activities' => $layout['activities'],
                    'preference_note' => $this->joinNotes($morningNote, $sightseeing === []
                        ? 'You picked '.$this->interestList($preferences).' without a sightseeing interest, so today has no attractions: it is built around meals at verified local restaurants'
                            .($shopDaily ? ' and a souvenir stop' : '').', with free time at your stay. Add Nature, Beaches, Culture, Adventure or Relaxation for sightseeing.'
                        : null),
                ], $prev);
                $prev = $home;
                $clock = $layout['end'];
            }

            // Nothing else before lunch: souvenir shopping first (over by 12:30), else a free
            // morning at the stay. Shopping after lunch is over in time for dinner, which then
            // still starts within its evening window.
            $dinnerTarget = $this->minutesOf($settings['dinner']);
            $shopToday = $shopDaily || $dayNumber === $numDays;
            if (! $hadLunch && $clock < 690 && $shopToday) {
                $goShopping(750);
            }
            if (! $hadLunch && $prev) {
                $freeMorning();
                if ($missing = $lunch(null)) {
                    $appendNote($missing);
                }
            }
            if (! $shopped && $shopToday) {
                $goShopping(self::LATEST_DINNER - 30);
            }

            // A merienda when the afternoon has room for one (always offered on a packed pace).
            if ($hadLunch && $prev && ($settings['merienda']
                ? $clock <= 960
                : $clock >= 840 && $clock <= 990 && $dinnerTarget - $clock >= self::IDLE_MINUTES)) {
                $merienda();
            }

            // Check in before dinner if that's still to do, or rest at the stay instead of a long
            // wait (after travelling, even a shorter one on the arrival day).
            if ($base && $prev && ! $lastDay && $endsInTime($dinnerTarget)) {
                if (! $checkedIn) {
                    $from = max($clock + $this->travelMinutes($prev, $base), $checkInAt ?? 0);
                    $restAt($base, max($dinnerTarget - 15, $from + 30), $restNotes('before dinner'),
                        'Your base for this part of the trip.', 30);
                } elseif ($dinnerTarget - $clock >= ($arrivalDay ? 45 : self::IDLE_MINUTES)) {
                    $restAt($base, $dinnerTarget - 15, $restNotes('before dinner'), "A breather after the day's travel and outings, so the evening never feels rushed.", $arrivalDay ? 30 : 90);
                }
            }

            // Dinner near where the day ends, then on the way back to tonight's stay, then at the
            // stay; none after a late arrival, when dinner time is long past.
            if ($prev && ! $endsInTime(max($dinnerTarget, $clock) + self::MEAL_MINUTES)) {
                if ($clock < $dayEnd) {
                    $appendNote("{$leaveNote}, before dinner time, so plan to eat on the way.");
                }
            } elseif ($prev && $clock > self::LATEST_DINNER) {
                $appendNote('You arrive after the usual dinner time, so eat on the way or ask '.($tonight?->name ?? 'your accommodation').' about food options.');
            } elseif ($prev) {
                $dinnerStart = fn ($f) => max($dinnerTarget, $clock + $this->travelMinutes($prev, $f));
                $dinnerOpen = fn ($f) => $endsInTime($dinnerStart($f) + self::MEAL_MINUTES) && $this->isOpen($f, $dinnerStart($f), $dinnerStart($f) + self::MEAL_MINUTES, $date);
                $dinner = $this->pickPlace($plan['food'], $usedFood, fn ($f) => $this->kmBetween($prev, $f), $mealKm, $dinnerOpen, $foodScore);
                $dinnerReason = $dinner ? $this->mealReason($dinner, $prev, 'right where your day ends', $preferences) : null;
                if (! $dinner && $tonight && $prev !== $tonight) {
                    $dinner = $this->pickPlace($plan['food'], $usedFood,
                        fn ($f) => $this->kmBetween($prev, $f) + $this->kmBetween($f, $tonight) - $this->kmBetween($prev, $tonight),
                        self::MAX_DETOUR_KM, $dinnerOpen, $foodScore);
                    if ($dinner) {
                        $dinnerReason = "On your way back from {$prev->name} to {$tonight->name}{$this->detourNote($dinner['km'])}.";
                    }
                }
                if ($dinner) {
                    $addFood($dinner, $dinnerStart($dinner['place']), self::MEAL_MINUTES, "Dinner near {$prev->name}.", $dinnerReason, 'dinner', $prev, $prev);
                } elseif ($tonight && $endsInTime(max($dinnerTarget, $clock + $this->travelMinutes($prev, $tonight)) + self::MEAL_MINUTES)) {
                    $addMealAtStay('dinner', max($dinnerTarget, $clock + $this->travelMinutes($prev, $tonight)),
                        "near {$prev->name}".($prev !== $tonight ? " or on the way to {$tonight->name}" : ''), $prev, $tonight);
                }
            }

            // Without a stay there's no arrival stop: Day 1's first stop says when the tourist arrives.
            if ($arrivalDay && ! $home && ! $this->needsStay($preferences)) {
                $firstToday = collect($items)->search(fn ($i) => $i['day_number'] === $dayNumber);
                if ($firstToday !== false) {
                    $items[$firstToday]['preference_note'] = $this->joinNotes(
                        'You arrive around '.$this->formatMinutes($arrivalAt ?? self::DEFAULT_ARRIVAL)
                            .($arrivalAt === null ? ' (no arrival time was given)' : '').". You don't need accommodation, so the day starts here.",
                        $items[$firstToday]['preference_note'] ?? null,
                    );
                }
            }

            $dayTowns[$dayNumber] = $town;
            $lastTown = $town;
        }

        if ($carried !== []) {
            Log::warning('Itinerary destinations that fit no day', ['destinations' => collect(array_keys($carried))->map(fn ($id) => $byId[$id]->name)->all()]);
        }

        return [$items, $dayTowns];
    }

    /** A destination whose visit is pinned to the end of the day (a sunset or evening activity). */
    private function isAnchor(Destination $destination): bool
    {
        // Only when the place is still open then: a sunset spot that closes at 5 PM isn't one.
        $hours = $this->openingHours($destination->operating_hours, null);

        return $destination->activities->contains(function ($a) use ($hours) {
            $label = $this->timeOfDay($a->name, $a->best_time_to_visit, 'anytime');

            return in_array($label, ['sunset', 'evening'], true) && (! $hours || $hours[1] >= self::WINDOWS[$label][0] + 60);
        });
    }

    /** The latest arrival at an anchor that still does its other activities before its sunset/evening one. */
    private function latestArrival(Destination $destination, string $pace, ?Carbon $date): int
    {
        $planned = $this->plannedActivities($destination, $destination, $pace, $this->openingHours($destination->operating_hours, $date));
        $pinned = collect($planned)->filter(fn ($a) => in_array($a['label'], ['sunset', 'evening'], true));
        if ($pinned->isEmpty()) {
            // Nothing to wait for (e.g. closed by evening on this date): no later start.
            return 0;
        }

        return $pinned->min(fn ($a) => $a['window'][0]) - (count($planned) - $pinned->count()) * $this->activityMinutes($pace);
    }

    /**
     * A visit's activities with their time of day ('label') and window: exactly the
     * destination's listed activities, each as long as listed, else as the pace allows (two generic ones on a
     * free day at the stay). A dawn activity on the arrival day, on a relaxed pace or before the place opens,
     * or an evening one after it closes, is kept but loses its window and says when it's best instead.
     *
     * @return array<int, array{name: string, duration: int, label: string, window: ?array, description: ?string}>
     */
    private function plannedActivities(?Destination $destination, $site, string $pace, ?array $hours, bool $arrivalDay = false): array
    {
        if (! $destination) {
            return [
                ['name' => "Relax and enjoy the amenities at {$site->name}", 'duration' => 120, 'label' => 'morning', 'window' => self::WINDOWS['morning'], 'description' => null],
                ['name' => "Explore the neighborhood around {$site->name}", 'duration' => 90, 'label' => 'afternoon', 'window' => self::WINDOWS['afternoon'], 'description' => null],
            ];
        }

        $planned = [];
        foreach ($destination->activities as $activity) {
            $label = $this->timeOfDay($activity->name, $activity->best_time_to_visit, 'anytime');
            $entry = [
                'name' => $activity->name,
                // The activity's own listed length when it has one.
                'duration' => $activity->duration_minutes > 0 ? min((int) $activity->duration_minutes, 240) : $this->activityMinutes($pace),
                'label' => $label,
                'window' => self::WINDOWS[$label] ?? null,
                'description' => $activity->description,
            ];

            $tooEarly = $label === 'dawn' && ($arrivalDay || $pace === 'relaxed' || ($hours && $hours[0] > self::WINDOWS['dawn'][0] + 30));
            // A sunset or evening activity needs about an hour of it before closing time.
            $tooLate = in_array($label, ['sunset', 'evening'], true) && $hours && $hours[1] < self::WINDOWS[$label][0] + 60;
            $planned[] = $tooEarly || $tooLate ? $this->outsideWindow($entry) : $entry;
        }

        // No listed activities: the shortest sensible visit for this kind of place at this pace.
        return $planned ?: [['name' => "Explore {$destination->name}", 'duration' => max($this->minimumVisit($destination, $pace), $this->activityMinutes($pace)), 'label' => 'anytime', 'window' => null, 'description' => null]];
    }

    /**
     * Lengthens a visit's anytime activities evenly until the visit lasts at least $minimum
     * minutes, so a beach with one listed activity isn't an hour's stop.
     */
    private function stretchVisit(array $planned, int $minimum): array
    {
        $total = array_sum(array_column($planned, 'duration'));
        $flexible = array_keys(array_filter($planned, fn ($a) => $a['label'] === 'anytime'));
        if ($total >= $minimum || $flexible === []) {
            return $planned;
        }

        $extra = (int) (ceil(($minimum - $total) / count($flexible) / 5) * 5);
        foreach ($flexible as $i) {
            $planned[$i]['duration'] += $extra;
        }

        return $planned;
    }

    /**
     * When a visit's first activity without a fixed time runs past $minutes into the visit (a
     * break starts there), splits it into the part before the break and the rest after it,
     * so the visit doesn't wait for the break to start. A remainder under 30 minutes isn't
     * worth coming back to, so the activity just ends at the break; with under 30 minutes
     * before the break, the visit is left as it was.
     */
    private function splitAt(array $planned, int $minutes): array
    {
        foreach ($planned as $i => $activity) {
            if (isset($activity['at']) || in_array($activity['label'], ['dawn', 'sunset', 'evening'], true)) {
                continue;
            }
            if ($minutes < 30 || $activity['duration'] <= $minutes) {
                return $planned;
            }
            if ($activity['duration'] - $minutes < 30) {
                $planned[$i]['duration'] = $minutes;

                return $planned;
            }
            array_splice($planned, $i, 1, [
                ['duration' => $minutes] + $activity,
                ['duration' => $activity['duration'] - $minutes] + $activity,
            ]);

            return $planned;
        }

        return $planned;
    }

    /** Takes $over minutes off a visit's activities without a fixed time, none below 30 minutes. */
    private function shortenVisit(array $planned, int $over): array
    {
        foreach ($planned as $i => $activity) {
            if ($over <= 0 || isset($activity['at']) || in_array($activity['label'], ['dawn', 'sunset', 'evening'], true)) {
                continue;
            }
            $cut = min(max(0, $activity['duration'] - 30), (int) (ceil($over / 5) * 5));
            $planned[$i]['duration'] -= $cut;
            $over -= $cut;
        }

        return $planned;
    }

    /** The shortest sensible visit for this kind of place, longer on a relaxed pace and shorter on a packed one. */
    private function minimumVisit(Destination $destination, string $pace): int
    {
        $adjust = ['relaxed' => 30, 'packed' => -15][$pace] ?? 0;

        return (self::MIN_VISIT[$this->experienceKind($destination)] ?? 60) + $adjust;
    }

    /** A short opener for a visit's notes; the listing's description is shown beside the stop already. */
    private function visitNotes(Destination $destination): string
    {
        return "Visit {$destination->name}.";
    }

    /** Practical reminders for a visit, from its listed activities and the kind of place it is. */
    private function visitTips(Destination $destination, string $kind): ?string
    {
        $tips = [];
        $wear = $destination->activities->pluck('what_to_wear')->filter()->map(fn ($w) => rtrim(trim($w), '.'))->unique()->implode('; ');
        if ($wear !== '') {
            $tips[] = "What to wear: {$wear}.";
        }
        if ($kind === 'water') {
            $tips[] = 'Bring swimwear, a towel and a change of clothes; the plan leaves about '.self::FRESHEN_UP_MINUTES.' minutes to rinse off and change before moving on.';
        } elseif ($kind === 'highland') {
            $tips[] = 'Wear comfortable shoes and bring water.';
        } elseif ($destination->activities->contains('type', 'outdoor')) {
            $tips[] = 'Bring water and sun protection.';
        }
        if (trim((string) $destination->operating_hours) === '') {
            $tips[] = "Opening hours aren't listed, so confirm with the site before you go.";
        } elseif (in_array('operating_hours', $destination->estimated_fields ?? [], true)) {
            $tips[] = 'The hours shown are typical for this kind of place, not confirmed by the site, so check before you go.';
        }

        return $tips === [] ? null : implode(' ', $tips);
    }

    /** The same listing, by type and ID (two lookups of one stay are different objects). */
    private function samePlace($a, $b): bool
    {
        return $a && $b && $a::class === $b::class && $a->id === $b->id;
    }

    private function joinNotes(?string ...$notes): ?string
    {
        $text = trim(implode(' ', array_filter($notes)));

        return $text === '' ? null : $text;
    }

    /**
     * $notes followed by when to leave $from and how long the trip to $to takes, saying
     * whether that's Google's driving time or an estimate from the map distance.
     */
    private function withTravelNote(?string $notes, $from, $to, int $start): ?string
    {
        $minutes = $this->travelMinutes($from, $to);
        if ($minutes === 0) {
            return $notes;
        }

        $how = $this->roadLeg($from, $to) ? 'driving time from Google Maps' : 'estimated from the map distance, so allow extra time';

        return trim(($notes ?? '')." Leave {$from->name} by {$this->formatMinutes($start - $minutes)}: about {$minutes} min ({$how}).");
    }

    /** When the tourist leaves on the last day ("HH:MM") in minutes after midnight, or null when not given. */
    private function departureMinutes(array $preferences): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', (string) ($preferences['departure_time'] ?? ''), $m)) {
            return null;
        }

        return min((int) $m[1] * 60 + (int) $m[2], 1439);
    }

    /** The tourist's arrival on Day 1 ("HH:MM") in minutes after midnight, or null when not given. */
    private function arrivalMinutes(array $preferences): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', (string) ($preferences['arrival_time'] ?? ''), $m)) {
            return null;
        }

        return min((int) $m[1] * 60 + (int) $m[2], 1439);
    }

    /**
     * What breaks the planning rules in a built trip (empty when nothing does): a stop without
     * a readable time, one that starts before the previous stop ends plus the travel between
     * them, a lunch break outside its visit, a meal outside its usual window, and a destination
     * used twice or visited outside its opening hours.
     *
     * @return array<int, string>
     */
    public function scheduleProblems(array $items, Collection $destinations, array $preferences): array
    {
        $startDate = ! empty($preferences['start_date']) ? Carbon::parse($preferences['start_date']) : null;
        $byId = $destinations->keyBy(fn ($d) => (int) $d->id);
        $windows = ['Breakfast' => [330, 600], 'Lunch' => [660, self::LATEST_LUNCH], 'Afternoon merienda' => [870, 1000], 'Dinner' => [1050, 1290]];
        $problems = [];
        $seen = [];

        foreach (collect($items)->groupBy('day_number') as $day => $rows) {
            $date = $startDate?->copy()->addDays($day - 1);
            $prevEnd = null;
            $visit = null;
            foreach ($rows->sortBy('sequence') as $row) {
                $label = "Day {$day}, stop {$row['sequence']}";
                $slot = $this->slotMinutes($row['time_slot'] ?? null);
                if (! $slot) {
                    $problems[] = "{$label} has no readable time.";

                    continue;
                }
                [$from, $to] = $slot;
                $notes = (string) ($row['notes'] ?? '');

                // A lunch break belongs to the visit right before it, with the trip to and from
                // the food place inside that visit; the visit's end stays the time to beat.
                if (Str::startsWith($notes, 'Lunch break')) {
                    $travel = (int) ($row['travel_time_from_prev'] ?? 0);
                    if (! $visit || $from - $travel < $visit[0] || $to + $travel > $visit[1]) {
                        $problems[] = "{$label}: the lunch break falls outside its visit.";
                    }
                    $visit = null;

                    continue;
                }
                if ($prevEnd !== null && $from < $prevEnd + (int) ($row['travel_time_from_prev'] ?? 0)) {
                    $problems[] = "{$label} starts before the previous stop ends plus the travel time.";
                }
                foreach ($windows as $meal => [$earliest, $latest]) {
                    if (Str::startsWith($notes, $meal) && ($from < $earliest || $from > $latest)) {
                        $problems[] = "{$label}: {$meal} at {$this->formatMinutes($from)} is outside its usual time.";
                    }
                }
                if (($row['item_type'] ?? null) === ItineraryItem::TYPE_DESTINATION) {
                    $id = (int) $row['destination_id'];
                    if (isset($seen[$id])) {
                        $problems[] = "{$label} repeats a destination already in the trip.";
                    }
                    $seen[$id] = true;
                    $place = $byId[$id] ?? null;
                    if ($place && ! $this->isOpen($place, $from, $to, $date)) {
                        $problems[] = "{$label} is outside {$place->name}'s opening hours.";
                    }
                }
                $visit = ($row['item_type'] ?? null) === ItineraryItem::TYPE_DESTINATION ? [$from, $to] : null;
                $prevEnd = $to;
            }
        }

        return $problems;
    }

    /** An activity that can't happen at its time of day: done in sequence, noting when it's best. */
    private function outsideWindow(array $activity): array
    {
        $best = [
            'dawn' => 'at sunrise',
            'morning' => 'in the morning',
            'midday' => 'around midday',
            'afternoon' => 'in the afternoon',
            'sunset' => 'at sunset',
            'evening' => 'in the evening',
        ][$activity['label']] ?? null;

        return array_merge($activity, [
            'label' => 'anytime',
            'window' => null,
            'description' => $best ? trim(($activity['description'] ?? '')." Best enjoyed {$best}.") : $activity['description'],
        ]);
    }

    /**
     * Lays a visit's activities out one after another from $arrival: a sunrise activity first,
     * then the others by time of day, then sunset and evening ones. Sunrise, sunset and evening
     * activities wait for their window (or note when they're best if it's already gone), an
     * activity pinned with 'at' (a picnic lunch) keeps its time, and none overlaps $blocks
     * ([start, end], e.g. a lunch break). Only the activities are listed — no free time.
     *
     * @return array{activities: array<int, array{time: string, name: string, description: ?string}>, start: int, end: int}
     */
    private function layoutVisit(int $arrival, array $planned, array $blocks = []): array
    {
        $rank = ['dawn' => 0, 'morning' => 1, 'midday' => 2, 'anytime' => 2, 'afternoon' => 3, 'sunset' => 4, 'evening' => 5];
        $order = array_keys($planned);
        usort($order, fn ($a, $b) => [$rank[$planned[$a]['label']] ?? 2, $a] <=> [$rank[$planned[$b]['label']] ?? 2, $b]);

        $busy = array_map(fn ($b) => [$b[0], $b[1]], $blocks);
        foreach ($planned as $activity) {
            if (isset($activity['at'])) {
                $busy[] = $activity['at'];
            }
        }

        $rows = [];
        $cursor = $arrival;
        foreach ($order as $i) {
            $activity = $planned[$i];
            if (isset($activity['at'])) {
                $rows[] = [$activity['at'][0], $activity['at'][1], $activity['name'], $activity['description']];

                continue;
            }

            $length = $activity['duration'] ?? 60;
            $start = $cursor;
            if (in_array($activity['label'], ['dawn', 'sunset', 'evening'], true) && $activity['window']) {
                if ($cursor + $length <= $activity['window'][1]) {
                    $start = max($cursor, $activity['window'][0]);
                } else {
                    $activity = $this->outsideWindow($activity);
                }
            }
            $start = $this->afterBusy($busy, $start, $length);

            $rows[] = [$start, $start + $length, $activity['name'], $activity['description']];
            $busy[] = [$start, $start + $length];
            $cursor = $start + $length;
        }
        usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);

        return [
            'activities' => array_map(fn ($r) => [
                'time' => $this->formatMinutes($r[0]).' – '.$this->formatMinutes($r[1]),
                'name' => $r[2],
                'description' => $r[3],
            ], $rows),
            'start' => $rows === [] ? $arrival : min(array_column($rows, 0)),
            'end' => $rows === [] ? $arrival + 60 : max(array_column($rows, 1)),
        ];
    }

    /** The earliest start at or after $start for $length minutes that overlaps none of $busy. */
    private function afterBusy(array $busy, int $start, int $length): int
    {
        do {
            $moved = false;
            foreach ($busy as [$from, $to]) {
                if ($start < $to && $start + $length > $from) {
                    $start = $to;
                    $moved = true;
                }
            }
        } while ($moved);

        return $start;
    }

    /** When a visit may end: the pace's usual end, later for sunset or evening activities, never after closing. */
    private function visitEnd(array $planned, string $pace, ?array $hours): int
    {
        $end = $this->visitEndTime($pace);
        if (collect($planned)->contains(fn ($a) => $a['label'] === 'sunset')) {
            $end = max($end, self::WINDOWS['sunset'][1]);
        }
        $eveningMinutes = collect($planned)->where('label', 'evening')->sum(fn ($a) => $a['duration'] ?? 60);
        if ($eveningMinutes > 0) {
            $end = max($end, min(self::WINDOWS['evening'][1], self::WINDOWS['evening'][0] + $eveningMinutes));
        }

        return $hours ? min($end, $hours[1]) : $end;
    }

    private function visitReason(array $day, Destination $destination): string
    {
        return $day['reasons'][$destination->id]
            ?? 'Fits the interests you picked'.($destination->category ? " ({$destination->category->name})" : '').'.';
    }

    /**
     * A destination's listed activities laid out inside a stop's time range, around $blocks
     * ([start, end, …] stops nested in it, e.g. a lunch break). Used when a tourist adds, swaps
     * or re-times a destination. Each gets the pace's activity length, or an equal share of a shorter range (at
     * least 15 minutes); sunrise/sunset/evening activities outside the range note when they're best.
     *
     * @return array<int, array{time: string, name: string, description: ?string}>
     */
    public function scheduleDestinationActivities(Destination $destination, int $start, int $end, array $blocks = [], string $pace = 'moderate'): array
    {
        $this->applyEstimates($destination);
        $planned = array_map(
            fn ($a) => $a['window'] && ($a['window'][1] <= $start || $a['window'][0] >= $end) ? $this->outsideWindow($a) : $a,
            $this->plannedActivities($destination, $destination, $pace, $this->openingHours($destination->operating_hours, null))
        );
        $blocked = array_sum(array_map(fn ($b) => max(0, min($b[1], $end) - max($b[0], $start)), $blocks));
        $share = intdiv(intdiv(max($end - $start - $blocked, 0), max(count($planned), 1)), 5) * 5;
        $length = max(15, min($this->activityMinutes($pace), $share));
        $planned = array_map(fn ($a) => ['duration' => $length] + $a, $planned);

        return $this->layoutVisit($start, $planned, $blocks)['activities'];
    }

    /**
     * Why a tourist's time for a destination stop doesn't work, or null when it does: the place
     * is closed that day, or the time falls outside its listed (or, without them, its typical
     * estimated) opening hours. $date is the day of the stop, when the trip has dates.
     */
    public function visitHoursProblem(Destination $destination, int $start, int $end, ?Carbon $date): ?string
    {
        $this->applyEstimates($destination);
        $typical = in_array('operating_hours', $destination->estimated_fields ?? [], true) ? ' (typical hours, not confirmed by the site)' : '';
        if ($this->isClosedOn($destination, $date)) {
            return "{$destination->name} isn't open on that day. Pick another day for it.";
        }
        $hours = $this->openingHours($destination->operating_hours, $date);
        if ($hours && ($start < $hours[0] || $end > $hours[1])) {
            return "{$destination->name} is open {$this->formatMinutes($hours[0])} – {$this->formatMinutes($hours[1])}{$typical}. Pick a time within those hours.";
        }

        return null;
    }

    /**
     * $notes with the generated wording brought up to date after an edit: the "<Meal> near X."
     * opener names the stop before, and the "Leave X by …: about N min (…)." sentence uses the
     * actual previous stop, travel time and start time (removed when there's no trip to make).
     * Notes the tourist wrote themselves don't match these patterns and are left as they are.
     */
    private function refreshedTravelNotes(?string $notes, $prev, $place, ?string $slot): ?string
    {
        if ($notes === null || $notes === '') {
            return $notes;
        }

        if ($prev && $prev !== $place) {
            $notes = preg_replace('/^(Breakfast|Lunch|Dinner|Afternoon merienda \(snack\)) near .+?\.(?= Leave |$)/u', "$1 near {$prev->name}.", $notes);
        }

        $pattern = '/\s*Leave .+? by \d{1,2}:\d{2} [AP]M: about \d+ min \([^)]*\)\.$/u';
        if (! preg_match($pattern, $notes)) {
            return $notes;
        }
        $base = preg_replace($pattern, '', $notes);
        $range = $this->slotMinutes($slot);

        return $range ? ($this->withTravelNote($base, $prev, $place, $range[0]) ?? $base) : $notes;
    }

    /** "7:30 AM – 11:30 AM" → [450, 690]; null for anything else, or an end not after the start. */
    public function slotMinutes(?string $slot): ?array
    {
        $time = '(\d{1,2}):(\d{2})\s*([AP])M';
        if (! preg_match("/^\s*{$time}\s*[–-]\s*{$time}\s*$/iu", (string) $slot, $m)) {
            return null;
        }

        $toMinutes = fn ($h, $min, $ampm) => ((int) $h % 12 + (strtoupper($ampm) === 'P' ? 12 : 0)) * 60 + (int) $min;
        $start = $toMinutes($m[1], $m[2], $m[3]);
        $end = $toMinutes($m[4], $m[5], $m[6]);

        return $end > $start ? [$start, $end] : null;
    }

    /**
     * Time of day for an activity: its name first (sunrise, sunset, stargazing…), then its
     * listed best time when that names a single part of the day, then the AI's label.
     */
    private function timeOfDay(string $name, ?string $bestTime, string $aiLabel): string
    {
        $name = mb_strtolower($name);
        if (preg_match('/sunrise|dawn/', $name)) {
            return 'dawn';
        }
        if (preg_match('/stargaz|firefl|night|evening|moonless|bonfire|campfire/', $name)) {
            return 'evening';
        }
        if (preg_match('/sunset|golden hour|dusk/', $name)) {
            return 'sunset';
        }
        if (str_contains($name, 'picnic')) {
            return 'midday';
        }

        $best = mb_strtolower((string) $bestTime);
        if ($best !== '' && ! str_contains($best, ' or ')) {
            foreach ([
                'dawn' => '/sunrise|dawn/',
                'sunset' => '/sunset|golden hour|late afternoon|dusk/',
                'morning' => '/morning/',
                'midday' => '/midday|noon/',
                'afternoon' => '/afternoon/',
                'evening' => '/evening|night|moonless/',
            ] as $label => $pattern) {
                if (preg_match($pattern, $best)) {
                    return $label;
                }
            }
        }

        return in_array($aiLabel, self::TIMES_OF_DAY, true) ? $aiLabel : 'anytime';
    }

    /**
     * The best unused place in $pool by $distance (km): among those open (per $isOpen) and
     * within $maxKm, the nearest, or with $score, the highest-scoring one within
     * PREFERENCE_RADIUS_KM of the nearest.
     *
     * @return array{place: mixed, km: float}|null
     */
    private function pickPlace(Collection $pool, array $usedIds, callable $distance, ?float $maxKm, callable $isOpen, ?callable $score = null): ?array
    {
        $ranked = $pool
            ->reject(fn ($p) => in_array($p->id, $usedIds, true))
            ->filter($isOpen)
            ->map(fn ($p) => ['place' => $p, 'km' => $distance($p)])
            ->filter(fn ($r) => $maxKm === null || $r['km'] <= $maxKm)
            ->sortBy('km')
            ->values();

        if ($ranked->isEmpty()) {
            return null;
        }
        if (! $score) {
            return $ranked->first();
        }

        $cutoff = $ranked->first()['km'] + self::PREFERENCE_RADIUS_KM;

        return $ranked
            ->filter(fn ($r) => $r['km'] <= $cutoff)
            ->sortBy([
                fn ($a, $b) => $score($b['place']) <=> $score($a['place']),
                fn ($a, $b) => $a['km'] <=> $b['km'],
            ])
            ->first();
    }

    /**
     * Opening hours on $date as [open, close] in minutes after midnight (close past midnight
     * when needed), or null when the place is always open or its hours can't be read.
     * Handles "6:00 AM - 9:00 PM", "7:00am-5:00pm", "Mon-Fri: … | Sat-Sun: …",
     * "Monday: … Tuesday to Friday: …", "24 hours",
     * "24/7", "Open Access" and "Daytime".
     */
    private function openingHours(?string $hours, ?Carbon $date): ?array
    {
        $text = trim((string) $hours);
        if ($text === '' || preg_match('/24\s*(hours|\/\s*7)|open access/i', $text)) {
            return null;
        }
        if (preg_match('/^daytime$/i', $text)) {
            return [360, 1080];
        }

        $days = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
        if ($date && preg_match_all('/\b(mon|tue|wed|thu|fri|sat|sun)\w*(?:\s*(?:-|–|to)\s*(mon|tue|wed|thu|fri|sat|sun)\w*)?\s*:\s*([^|]+)/i', $text, $segments, PREG_SET_ORDER)) {
            $today = $date->dayOfWeekIso;
            foreach ($segments as $segment) {
                $from = $days[strtolower($segment[1])];
                $to = $days[strtolower($segment[2] ?: $segment[1])];
                $inRange = $from <= $to ? $today >= $from && $today <= $to : $today >= $from || $today <= $to;
                if ($inRange) {
                    $text = $segment[3];
                    break;
                }
            }
        }

        $time = '(\d{1,2})(?::(\d{2}))?\s*([ap])\.?\s*m\.?';
        if (! preg_match("/{$time}\s*(?:-|–|to)\s*{$time}/i", $text, $m)) {
            return null;
        }

        $toMinutes = fn ($h, $min, $ampm) => ((int) $h % 12 + (strtolower($ampm) === 'p' ? 12 : 0)) * 60 + (int) $min;
        $open = $toMinutes($m[1], $m[2], $m[3]);
        $close = $toMinutes($m[4], $m[5], $m[6]);

        return [$open, $close <= $open ? $close + 1440 : $close];
    }

    private function isOpen($place, int $from, int $to, ?Carbon $date): bool
    {
        $hours = $this->openingHours($place->operating_hours, $date);

        return ! $hours || ($from >= $hours[0] && $to <= $hours[1]);
    }

    private function budgetLevel(array $preferences): string
    {
        $budget = $preferences['budget'] ?? 'moderate';

        return $budget === 'medium' ? 'moderate' : $budget;
    }

    private function accommodationTypeMatcher(array $preferences): ?callable
    {
        $types = match ($preferences['accommodation_type'] ?? null) {
            'resort' => ['Resort'],
            'hotel' => ['Hotel'],
            'homestay' => ['Homestay'],
            'budget' => ['Inn', 'Homestay'],
            default => [],
        };

        return $types ? fn ($a) => in_array($a->type, $types, true) : null;
    }

    private function accommodationTypeLabel(array $preferences): string
    {
        return match ($preferences['accommodation_type'] ?? null) {
            'resort' => 'Resort',
            'hotel' => 'Hotel',
            'homestay' => 'Homestay / BnB',
            'budget' => 'Budget / Backpacker stay',
            default => 'accommodation',
        };
    }

    private function cuisineMatcher(array $preferences): ?callable
    {
        $mentions = fn ($f, array $words) => Str::contains(mb_strtolower("{$f->cuisine_type} {$f->name} {$f->description}"), $words);

        return match ($preferences['food_preference'] ?? null) {
            'seafood' => fn ($f) => $f->cuisine_type === 'Seafood',
            // Any recorded Filipino cuisine: "Filipino", "Filipino Bar & Grill", "Filipino Restobar"…
            'filipino_bicolano' => fn ($f) => $this->isLocalCuisine($f),
            'street_food' => fn ($f) => in_array($f->cuisine_type, ['Snacks & Desserts', 'Bakery'], true),
            'fine_dining' => fn ($f) => $f->price_range === 'luxury',
            'vegetarian' => fn ($f) => $mentions($f, ['vegetarian', 'vegan']),
            'fast_food' => fn ($f) => $mentions($f, ['fast food', 'burger', 'fried chicken', 'pizza']),
            default => null,
        };
    }

    private function cuisineLabel(array $preferences): string
    {
        return match ($preferences['food_preference'] ?? null) {
            'seafood' => 'seafood',
            'filipino_bicolano' => 'Filipino / Bicolano',
            'street_food' => 'street food',
            'fine_dining' => 'fine dining',
            'vegetarian' => 'vegetarian',
            'fast_food' => 'fast food',
            default => 'food',
        };
    }

    /**
     * 2 for the cuisine the tourist asked for (or no preference), +1 for their budget, with
     * the Food interest +1 for local (Filipino / Bicolano) cooking, and for a merienda +2 for a
     * café, snack shop or bakery.
     */
    private function foodScore(FoodBeverage $place, array $preferences, ?string $meal = null): int
    {
        $cuisine = $this->cuisineMatcher($preferences);

        return ($cuisine === null || $cuisine($place) ? 2 : 0) + ($place->price_range === $this->budgetLevel($preferences) ? 1 : 0)
            + ($this->localFoodWanted($place, $preferences) ? 1 : 0)
            + ($meal === 'merienda' && $this->isSnackPlace($place) ? 2 : 0)
            + ($meal !== null && in_array($meal, (array) ($place->meals_served ?? []), true) ? 1 : 0);
    }

    /** A café, coffee or tea shop, snack shop or bakery, by its cuisine type or name. */
    private function isSnackPlace(FoodBeverage $place): bool
    {
        return (bool) preg_match('/caf[eé]|coffee|kape|snack|bakery|bakeshop|pastr|dessert|milk ?tea|tea house/i', "{$place->cuisine_type} {$place->name}");
    }

    /** A place serving local (Filipino / Bicolano, per its cuisine_type) food, for a tourist who picked Food. */
    private function localFoodWanted(FoodBeverage $place, array $preferences): bool
    {
        return in_array('food', $preferences['interests'] ?? [], true) && $this->isLocalCuisine($place);
    }

    private function isLocalCuisine(FoodBeverage $place): bool
    {
        return Str::contains(mb_strtolower((string) $place->cuisine_type), ['filipino', 'bicol']);
    }

    /** "Food and Shopping" */
    private function interestList(array $preferences): string
    {
        $labels = array_map('ucfirst', array_values($preferences['interests'] ?? []));

        return count($labels) > 1 ? implode(', ', array_slice($labels, 0, -1)).' and '.end($labels) : ($labels[0] ?? '');
    }

    private function mealReason(array $pick, $near, string $why, array $preferences): string
    {
        $place = $pick['place'];
        $kind = $place->cuisine_type ? mb_strtolower($place->cuisine_type).' spot' : 'dining spot';
        $cuisine = $this->cuisineMatcher($preferences);
        $matches = $cuisine !== null && $cuisine($place) && $place->price_range === $this->budgetLevel($preferences)
            ? ", matching your {$this->cuisineLabel($preferences)} preference and budget"
            : '';
        $local = $this->localFoodWanted($place, $preferences) ? ', for a taste of local Filipino cooking' : '';

        return "The closest verified {$kind} to {$near->name} (".number_format($pick['km'], 1)." km), {$why}{$matches}{$local}.";
    }

    /**
     * Apology when a meal place doesn't match the tourist's cuisine, or costs more than their
     * budget (a cheaper place is no reason to apologize), else null. When the trip's towns list
     * no place of that cuisine at all, that's said once ($explainCuisine) instead of at every meal.
     */
    private function foodNote(FoodBeverage $place, string $meal, $near, array $preferences, bool $cuisineListed, bool $explainCuisine): ?string
    {
        $cuisine = $this->cuisineMatcher($preferences);
        $cuisineMatched = $cuisine === null || $cuisine($place);
        $budget = $this->budgetLevel($preferences);
        $levels = ['budget' => 1, 'moderate' => 2, 'luxury' => 3];
        $pricier = ($levels[$place->price_range] ?? 0) > ($levels[$budget] ?? 3);

        $notes = [];
        if (! $cuisineMatched && ! $cuisineListed && $explainCuisine) {
            $notes[] = "No verified {$this->cuisineLabel($preferences)} places are listed in {$this->townNames($preferences)} yet, so your meals are at the closest verified restaurants instead.";
        }
        $missing = ! $cuisineMatched && $cuisineListed;
        if ($missing || $pricier) {
            $wanted = ($missing ? $this->cuisineLabel($preferences).' ' : '').'place'.($pricier ? ' in the '.ucfirst($budget).' price range' : '');
            $notes[] = "We're sorry — we couldn't find an unused verified {$wanted} near {$near->name} for {$meal}. "
                ."We recommended {$place->name} instead: {$this->describeListing($place->cuisine_type ? "{$place->cuisine_type} spot" : 'dining spot', $place->price_range)}, "
                .number_format($this->kmBetween($near, $place), 1)." km from {$near->name} — the closest verified option.";
        }

        return $this->joinNotes(...$notes);
    }

    /** Why a meal is at the stay: no unused verified food place was open where it was looked for ($searched: "near X or near Y"). */
    private function mealAtStayNote(string $meal, string $searched, $stay): string
    {
        return "We're sorry — there's no other verified food place open {$searched} that isn't already in your trip, "
            ."so {$meal} is at your accommodation, {$stay->name}. "
            .($stay->dining_details ? trim($stay->dining_details).' ' : '')."Ask them about their {$meal} options.";
    }

    /** " (a 1.2 km detour)", or nothing when the place is right on the way. */
    private function detourNote(float $km): string
    {
        return $km < 0.1 ? ', right on the way' : ' (a '.number_format($km, 1).' km detour)';
    }

    /** "a Hotel (moderate price)" */
    private function describeListing(?string $type, ?string $priceRange): string
    {
        $type = $type ?: 'local listing';
        $article = preg_match('/^[aeiou]/i', $type) ? 'an' : 'a';

        return "{$article} {$type}".($priceRange ? " ({$priceRange} price)" : '');
    }

    /** "Bulan", "Bulan and Matnog", "Bulan, Matnog and Irosin" (or $conjunction instead of "and"). */
    private function townNames(array $preferences, string $conjunction = 'and'): string
    {
        $ids = $preferences['municipality_ids'] ?? $this->townIds($preferences);
        $names = Municipality::whereIn('id', $ids)->pluck('name', 'id');
        $ordered = collect($ids)->map(fn ($id) => $names[$id] ?? null)->filter()->values();

        if ($ordered->isEmpty()) {
            return 'Bulan';
        }

        return $ordered->count() === 1
            ? $ordered->first()
            : $ordered->slice(0, -1)->implode(', ')." {$conjunction} ".$ordered->last();
    }

    private function souvenirShopQuery(): Builder
    {
        return RetailShop::approved()->where(function ($q) {
            foreach (self::SOUVENIR_KEYWORDS as $keyword) {
                $q->orWhere('name', 'like', "%{$keyword}%")
                    ->orWhere('description', 'like', "%{$keyword}%");
            }
        });
    }

    /**
     * The drive between two places: Google's driving time when the distance matrix measured
     * it, else an estimate from the straight-line distance at ~30 km/h; rounded up to 5
     * minutes so the whole schedule lands on clean times (same figure shown between stops).
     */
    private function travelMinutes($from, $to): int
    {
        if (! $from || ! $to || $from === $to) {
            return 0;
        }

        $minutes = $this->roadLeg($from, $to)['duration_min'] ?? round($this->kmBetween($from, $to), 2) / 30 * 60;

        return (int) (ceil($minutes / 5) * 5);
    }

    /** Google's driving route between two destinations, when the distance matrix measured it. */
    private function roadLeg($from, $to): ?array
    {
        return $from instanceof Destination && $to instanceof Destination
            ? ($this->roadLegs["{$from->id}-{$to->id}"] ?? null)
            : null;
    }

    private function travelers(array $preferences): int
    {
        return max(1, (int) ($preferences['travelers'] ?? 1));
    }

    /** @return array{cost_min: float, cost_max: float, cost_note: string} */
    private function entranceCost(Destination $destination, int $travelers): array
    {
        // The listed Fees & Rates come first: they're what the destination's page shows.
        if ($listed = $this->generalFee($destination)) {
            $fee = (float) $listed->amount;

            return $fee <= 0
                ? ['cost_min' => 0, 'cost_max' => 0, 'cost_note' => 'Free entrance']
                : ['cost_min' => $fee * $travelers, 'cost_max' => $fee * $travelers, 'cost_note' => $this->timesTravelers(
                    // The fee's own label when it says more than "entrance fee" (e.g. "Adult", "Day Entrance").
                    preg_match('/^\s*(entrance|entance|entry|admission)?\s*(fee)?\s*(\(per (person|head)\))?\s*$/i', $listed->label) ? 'Entrance fee' : "Entrance fee ({$listed->label})",
                    $travelers
                )];
        }

        if ($destination->entrance_fee === null) {
            // A typical fee for this kind of place (see applyEstimates()), else unknown.
            [$min, $max] = $destination->getAttribute('estimated_fee') ?? [null, null];
            if ($max === null) {
                return ['cost_min' => 0, 'cost_max' => 0, 'cost_note' => 'Entrance fee not listed'];
            }

            return $max <= 0
                ? ['cost_min' => 0, 'cost_max' => 0, 'cost_note' => 'Usually free entrance']
                : ['cost_min' => $min * $travelers, 'cost_max' => $max * $travelers, 'cost_note' => $this->timesTravelers('Est. entrance fee', $travelers)];
        }

        $fee = (float) $destination->entrance_fee;
        if ($fee <= 0) {
            return ['cost_min' => 0, 'cost_max' => 0, 'cost_note' => 'Free entrance'];
        }

        return ['cost_min' => $fee * $travelers, 'cost_max' => $fee * $travelers, 'cost_note' => $this->timesTravelers('Entrance fee', $travelers)];
    }

    /**
     * The one entry from a destination's Fees & Rates list that a regular adult visitor pays:
     * an adult / general / entrance / day entry, skipping vehicle, parking and equipment rows
     * and concession, child or overnight rates; with only age bands, the highest one (the
     * adult band). Null when the destination lists no fees.
     */
    private function generalFee(Destination $destination): ?object
    {
        $fees = $destination->relationLoaded('fees') ? $destination->fees : $destination->fees()->get();
        $people = $fees->reject(fn ($f) => preg_match('/park|motor|tricycle|\bcar\b|suv|jeep|coaster|truck|\bbus\b|vehicle|boat|tent|cottage/i', $f->label));
        $concession = fn ($f) => preg_match('/child|kid|bab|senior|pwd|student|resident|below|minor|infant|\d+\s*(yrs|years|y\/o)|night|overnight/i', $f->label);

        return $people->first(fn ($f) => preg_match('/adult|regular|general|entrance|entry|entance|day|admission|environmental|register|tourism/i', $f->label) && ! $concession($f))
            ?? $people->reject($concession)->first()
            ?? $people->sortByDesc(fn ($f) => (float) $f->amount)->first();
    }

    /** Cheapest to priciest approved menu item per person, or the price-level band. */
    private function mealCost(FoodBeverage $place, int $travelers): array
    {
        $prices = $this->positivePrices($place->loadMissing(['menuItems' => fn ($q) => $q->approved()])->menuItems);
        [$min, $max, $label] = $prices->isNotEmpty()
            ? [$prices->min(), $prices->max(), 'Per person']
            : [...$this->band('meal_per_person', $place->price_range), 'Est. per person'];

        return ['cost_min' => $min * $travelers, 'cost_max' => $max * $travelers, 'cost_note' => $this->timesTravelers($label, $travelers)];
    }

    /** Cheapest to priciest approved product per person, or the souvenir band. */
    private function souvenirCost(RetailShop $shop, int $travelers): array
    {
        $prices = $this->positivePrices($shop->loadMissing(['products' => fn ($q) => $q->approved()])->products);
        [$min, $max, $label] = $prices->isNotEmpty()
            ? [$prices->min(), $prices->max(), 'Souvenirs per person']
            : [...config('services.itinerary.price_bands.souvenirs_per_person'), 'Est. souvenirs per person'];

        return ['cost_min' => $min * $travelers, 'cost_max' => $max * $travelers, 'cost_note' => $this->timesTravelers($label, $travelers)];
    }

    /**
     * The whole stay: cheapest to priciest approved room (or the price-level band) for one
     * room per two travelers, every night of the trip (at least one).
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function stayCost(?Accommodation $accommodation, int $nights, array $preferences): array
    {
        if (! $accommodation) {
            return [null, null];
        }

        $rooms = (int) ceil($this->travelers($preferences) / 2);
        $prices = $this->positivePrices($accommodation->loadMissing(['rooms' => fn ($q) => $q->approved()])->rooms);
        [$min, $max] = $prices->isNotEmpty()
            ? [$prices->min(), $prices->max()]
            : $this->band('room_per_night', $accommodation->price_range);

        return [$min * $rooms * $nights, $max * $rooms * $nights];
    }

    /**
     * $count options for the tourist's budget, from priced rows: the cheapest for "budget", the
     * priciest for "luxury", and those around the median price for "moderate".
     */
    private function tierPick(Collection $options, string $budget, int $count): Collection
    {
        $sorted = $options->filter(fn ($o) => (float) $o->price > 0)->sortBy(fn ($o) => (float) $o->price)->values();
        if ($sorted->count() <= $count) {
            return $sorted;
        }

        return match ($budget) {
            'budget' => $sorted->take($count),
            'luxury' => $sorted->take(-$count)->values(),
            default => $sorted->slice(intdiv($sorted->count() - $count, 2), $count)->values(),
        };
    }

    /**
     * Rooms at $stay for the whole group, from the room category that fits the budget (the
     * cheapest for "budget", the priciest for "luxury", the middle one for "moderate"), moving
     * to the next-closest category when that one runs out. Returns the rooms and how many
     * travelers they sleep.
     *
     * @return array{0: Collection, 1: int}
     */
    private function recommendRooms(Accommodation $stay, array $preferences): array
    {
        $rooms = $stay->rooms()->approved()->where('occupancy_status', 'unoccupied')->where('price', '>', 0)->get();
        if ($rooms->isEmpty()) {
            return [collect(), 0];
        }

        $categories = $rooms->groupBy(fn ($r) => $r->category ?: 'Room')
            ->sortBy(fn ($group) => $group->min(fn ($r) => (float) $r->price))
            ->values();
        $indexes = range(0, $categories->count() - 1);
        $middle = intdiv($categories->count() - 1, 2);
        $order = match ($this->budgetLevel($preferences)) {
            'budget' => $indexes,
            'luxury' => array_reverse($indexes),
            default => collect($indexes)->sortBy(fn ($i) => [abs($i - $middle), $i])->values()->all(),
        };

        $travelers = $this->travelers($preferences);
        $chosen = collect();
        $sleeps = 0;
        foreach ($order as $index) {
            foreach ($categories[$index]->sortBy(fn ($r) => (float) $r->price) as $room) {
                if ($sleeps >= $travelers) {
                    break 2;
                }
                $chosen->push($room);
                $sleeps += self::ROOM_CAPACITY[$room->category] ?? 2;
            }
        }

        return [$chosen, $sleeps];
    }

    /**
     * The dishes for the tourist's budget that each traveler orders, and what they cost (a
     * price-band estimate when the place has no priced menu). Dinner: 3 from the whole menu.
     * Breakfast: 1, a breakfast dish when the menu has one; lunch: 1 main dish; merienda: 1
     * light item (snack, dessert or drink). Each falls back to the next-best part of the menu,
     * and sharing platters are left out of single portions.
     *
     * @return array{dishes: array<int, array{quantity: int}>, cost_min: float, cost_max: float, cost_note: string}
     */
    private function dishPlan(FoodBeverage $place, array $preferences, string $meal = 'dinner'): array
    {
        $travelers = $this->travelers($preferences);
        $menu = $place->menuItems()->approved()->with('estimates')->get();
        if ($meal !== 'dinner') {
            $text = fn ($d) => "{$d->name} {$d->description}";
            $single = $menu->reject(fn ($d) => preg_match(self::SHARING_DISH, $d->name));
            $single = $single->isNotEmpty() ? $single : $menu;
            $light = $single->filter(fn ($d) => preg_match(self::LIGHT_DISH, $d->name));
            $mains = $single->reject(fn ($d) => preg_match(self::LIGHT_DISH, $d->name));
            $menu = collect(match ($meal) {
                'breakfast' => [$single->filter(fn ($d) => preg_match(self::BREAKFAST_DISH, $text($d))), $mains],
                'merienda' => [$light],
                default => [$mains],
            })->first(fn ($options) => $options->filter(fn ($d) => (float) $d->price > 0)->isNotEmpty()) ?? $single;
        }
        $dishes = $this->tierPick($menu, $this->budgetLevel($preferences), $meal === 'dinner' ? self::DISHES_PER_MEAL : 1)
            ->each(fn ($d) => $d->setRelation('pivot', new Pivot(['quantity' => $travelers])));

        return ['dishes' => $dishes->mapWithKeys(fn ($d) => [$d->id => ['quantity' => $travelers]])->all()]
            + $this->foodCost($place, $dishes, $travelers);
    }

    /**
     * The 3 souvenirs for the tourist's budget, one of each for the group, and what they cost.
     *
     * @return array{souvenirs: array<int, array{quantity: int}>, cost_min: float, cost_max: float, cost_note: string}
     */
    private function souvenirPlan(RetailShop $shop, array $preferences): array
    {
        $souvenirs = $this->tierPick($shop->products()->approved()->get(), $this->budgetLevel($preferences), self::SOUVENIRS_PER_SHOP)
            ->each(fn ($p) => $p->setRelation('pivot', new Pivot(['quantity' => 1])));

        return ['souvenirs' => $souvenirs->mapWithKeys(fn ($p) => [$p->id => ['quantity' => 1]])->all()]
            + $this->shopCost($shop, $souvenirs, $this->travelers($preferences));
    }

    /** Which meal a food stop is, from its generated notes ("Lunch near …"); dinner when they don't say. */
    private function mealOf(?string $notes): string
    {
        return match (true) {
            (bool) preg_match('/^Breakfast\b/', (string) $notes) => 'breakfast',
            (bool) preg_match('/^Lunch\b/', (string) $notes) => 'lunch',
            (bool) preg_match('/^Afternoon merienda\b/', (string) $notes) => 'merienda',
            default => 'dinner',
        };
    }

    /** Cost of the picked dishes; nothing picked costs nothing, unless the place lists no prices. */
    private function foodCost(FoodBeverage $place, Collection $dishes, int $travelers): array
    {
        if ($dishes->isNotEmpty()) {
            return $this->picksCost($dishes, 'dish');
        }

        return $place->menuItems()->approved()->where('price', '>', 0)->exists()
            ? ['cost_min' => 0, 'cost_max' => 0, 'cost_note' => 'No dishes picked']
            : $this->mealCost($place, $travelers);
    }

    /** Cost of the picked souvenirs; nothing picked costs nothing, unless the shop lists no prices. */
    private function shopCost(RetailShop $shop, Collection $souvenirs, int $travelers): array
    {
        if ($souvenirs->isNotEmpty()) {
            return $this->picksCost($souvenirs, 'souvenir');
        }

        return $shop->products()->approved()->where('price', '>', 0)->exists()
            ? ['cost_min' => 0, 'cost_max' => 0, 'cost_note' => 'No souvenirs picked']
            : $this->souvenirCost($shop, $travelers);
    }

    /**
     * Σ price × quantity of picked rows (each with a pivot quantity): "3 dishes × 4", with
     * "(incl. est. prices)" when any picked price is only an estimate (see ListingEstimate).
     */
    private function picksCost(Collection $picks, string $noun): array
    {
        $total = $picks->sum(fn ($p) => (float) $p->price * (int) $p->pivot->quantity);
        $quantities = $picks->map(fn ($p) => (int) $p->pivot->quantity)->unique();
        $label = $picks->count().' '.Str::plural($noun, $picks->count());
        if ($quantities->count() === 1 && $quantities->first() > 1) {
            $label .= ' × '.$quantities->first();
        } elseif ($quantities->count() > 1) {
            $label .= ' ('.$picks->sum(fn ($p) => (int) $p->pivot->quantity).' in all)';
        }
        if ($picks->contains(fn ($p) => method_exists($p, 'estimate') && $p->estimate('price') !== null)) {
            $label .= ' (incl. est. prices)';
        }

        return ['cost_min' => $total, 'cost_max' => $total, 'cost_note' => $label];
    }

    /**
     * A stay: every booked room's nightly price for each of its $nights. No rooms booked costs
     * nothing, unless the stay lists no room prices (then a band); no nights there costs nothing.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function roomsCost(?Accommodation $stay, Collection $rooms, int $nights, array $preferences): array
    {
        if (! $stay) {
            return [null, null];
        }
        if ($nights === 0) {
            return [0, 0];
        }
        if ($rooms->isNotEmpty()) {
            $total = $rooms->sum(fn ($r) => (float) $r->price) * $nights;

            return [$total, $total];
        }

        return $stay->rooms()->approved()->where('price', '>', 0)->exists() ? [0, 0] : $this->stayCost($stay, $nights, $preferences);
    }

    private function positivePrices(Collection $rows): Collection
    {
        return $rows->pluck('price')->map(fn ($p) => (float) $p)->filter(fn ($p) => $p > 0)->values();
    }

    /** @return array{0: float, 1: float} */
    private function band(string $key, ?string $level): array
    {
        $bands = config("services.itinerary.price_bands.{$key}");

        return $bands[$level] ?? $bands['moderate'];
    }

    private function timesTravelers(string $label, int $travelers): string
    {
        return $travelers > 1 ? "{$label} × {$travelers}" : $label;
    }

    private function itemRow(string $type, $place, $prev, int $dayNumber, int $sequence, array $fields): array
    {
        return $fields + [
            'item_type' => $type,
            'day_number' => $dayNumber,
            'sequence' => $sequence,
            'duration_minutes' => null,
            'reason' => null,
            'preference_note' => null,
            'activities' => null,
            'cost_min' => null,
            'cost_max' => null,
            'cost_note' => null,
            // Google's road distance when measured, else the straight-line distance.
            'distance_from_prev_km' => $prev ? ($this->roadLeg($prev, $place)['distance_km'] ?? round($this->kmBetween($prev, $place), 2)) : null,
            'travel_time_from_prev' => $prev ? $this->travelMinutes($prev, $place) : null,
        ];
    }

    /** @return array{0: float, 1: float}|null */
    private function centroid(Collection $places): ?array
    {
        $withCoords = $places->filter(fn ($p) => $p->latitude !== null && $p->longitude !== null);
        if ($withCoords->isEmpty()) {
            return null;
        }

        return [
            $withCoords->avg(fn ($p) => (float) $p->latitude),
            $withCoords->avg(fn ($p) => (float) $p->longitude),
        ];
    }

    private function minutesOf(string $time): int
    {
        $clock = Carbon::createFromFormat('g:i A', $time);

        return $clock->hour * 60 + $clock->minute;
    }

    private function formatMinutes(int $minutes): string
    {
        $minutes = (($minutes % 1440) + 1440) % 1440;
        $hour = intdiv($minutes, 60);

        return sprintf('%d:%02d %s', $hour % 12 ?: 12, $minutes % 60, $hour < 12 ? 'AM' : 'PM');
    }

    /** "7:30 AM – 11:30 AM" */
    private function timeRange(int $start, int $minutes): string
    {
        return $this->formatMinutes($start).' – '.$this->formatMinutes($start + $minutes);
    }

    private function pace(string $pace): array
    {
        return self::PACES[$pace] ?? self::PACES['moderate'];
    }

    /** How long each of a destination's listed activities takes at this pace. */
    private function activityMinutes(string $pace): int
    {
        return $this->pace($pace)['activity'];
    }

    /** When a day at a destination ends unless a sunset or evening activity keeps it going. */
    private function visitEndTime(string $pace): int
    {
        return $this->pace($pace)['visit_end'];
    }

    private function km(array $point, $place): float
    {
        return $this->haversineDistance($point[0], $point[1], (float) $place->latitude, (float) $place->longitude);
    }

    private function kmBetween($from, $to): float
    {
        return $this->km([(float) $from->latitude, (float) $from->longitude], $to);
    }

    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /** Sum of every leg travelled between consecutive stops. */
    private function calculateTotalDistance(array $items): float
    {
        return round(array_sum(array_map(fn ($item) => $item['distance_from_prev_km'] ?? 0, $items)), 2);
    }

    private function formatInterests(array $interests): string
    {
        return implode(', ', array_map('ucfirst', $interests));
    }
}

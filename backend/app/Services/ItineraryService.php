<?php

namespace App\Services;

use App\Models\Accommodation;
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
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Builds a trip over one or more towns, with one recommended stay per town (central to the
 * destinations visited there); each night is spent at the stay of the town that day ends in.
 * The AI picks each day's destinations; this service lays out each destination's own listed
 * activities (an hour each), fills spare time with the nearest unused destination in the same
 * town, picks every meal, souvenir shop and stay (never repeating a food place, shop or
 * destination), and sets all times so activities fall in their time of day and the places'
 * opening hours where possible.
 */
class ItineraryService
{
    /** A preference-matching listing wins if it is at most this much farther than the nearest one. */
    private const PREFERENCE_RADIUS_KM = 3;

    /** "Near" for breakfast (the stay), lunch (the destination) and dinner (where the day ends). */
    private const NEARBY_KM = 3;

    /** Largest detour accepted for a meal on the way between two places. */
    private const MAX_DETOUR_KM = 10;

    /** Souvenir shops this close to the day's destination are added that day (any distance on the last day). */
    private const SHOP_RADIUS_KM = 5;

    private const SOUVENIR_KEYWORDS = ['souvenir', 'pasalubong', 'craft', 'gift', 'otop', 'pili', 'native'];

    private const BREAKFAST_MINUTES = 45;

    private const MEAL_MINUTES = 60;

    private const SHOPPING_MINUTES = 60;

    private const MERIENDA_MINUTES = 30;

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

    public function __construct(
        private GeminiAIService $ai,
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

        if ($destinations->isEmpty()) {
            throw new \Exception(count($preferences['municipality_ids']) > 1
                ? 'No destinations in the selected municipalities match your interests. Try adding more interests.'
                : 'No destinations in this municipality match your interests. Try adding more interests.');
        }

        // Google only measures the first few destinations, so take them from every town in turn.
        $distanceMatrix = $this->maps->getDistanceMatrix($this->interleaveByTown($destinations));
        $prompt = $this->buildPrompt($preferences, $destinations, $distanceMatrix);

        $maxAttempts = 2;
        $parsedItinerary = null;
        $aiResponse = null;

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

        // Total for the whole group: every stop (entrance fees, dishes, souvenirs) plus the rooms.
        $stayMin = array_sum(array_map(fn ($s) => $s['cost_min'] ?? 0, $stays));
        $stayMax = array_sum(array_map(fn ($s) => $s['cost_max'] ?? 0, $stays));
        $costMin = array_sum(array_map(fn ($item) => $item['cost_min'] ?? 0, $items)) + $stayMin;
        $costMax = array_sum(array_map(fn ($item) => $item['cost_max'] ?? 0, $items)) + $stayMax;
        // Older screens show one stay: the first one the tourist sleeps at.
        $main = collect($stays)->first(fn ($s) => $s['night_days'] !== [] && $s['accommodation']) ?? collect($stays)->first();

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
                        $item->dishes()->sync($this->dishPlan($place, $preferences)['dishes']);
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
                    $fields += $place instanceof Destination
                        ? $this->entranceCost($place, $travelers)
                        : ['cost_min' => null, 'cost_max' => null, 'cost_note' => null];
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

    /** Gives an itinerary saved without stays (the older shape) one stay for every night. */
    public function ensureStays(Itinerary $itinerary): void
    {
        if ($itinerary->stays()->exists()) {
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

    private function getEligibleDestinations(array $preferences): Collection
    {
        $interestCategoryMap = [
            'nature' => [1, 2, 3, 7, 8],
            'culture' => [4, 5],
            'food' => [13, 14, 15, 16],
            'adventure' => [2, 3, 7, 8],
            'shopping' => [17, 18, 19, 20],
            'relaxation' => [1, 6, 8],
        ];

        $categoryIds = [];
        foreach ($preferences['interests'] as $interest) {
            if (isset($interestCategoryMap[$interest])) {
                $categoryIds = array_merge($categoryIds, $interestCategoryMap[$interest]);
            }
        }
        $categoryIds = array_unique($categoryIds);

        $query = Destination::published()
            ->whereIn('municipality_id', $preferences['municipality_ids'])
            ->whereIn('category_id', $categoryIds);

        if (! empty($preferences['preferred_destinations'])) {
            $preferred = $preferences['preferred_destinations'];
            $query->orWhere(function ($q) use ($preferred) {
                $q->whereIn('id', $preferred)->where('status', 'published');
            });
        }

        return $query->with(['category:id,name', 'municipality:id,name', 'activities:id,destination_id,name,description,type,best_time_to_visit'])
            ->select('id', 'name', 'description', 'category_id', 'municipality_id', 'latitude', 'longitude', 'operating_hours', 'entrance_fee')
            ->get();
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

        $destinationsList = $destinations->map(function ($d) {
            $hours = $d->operating_hours ?: 'Not listed';
            $fee = $d->entrance_fee ?? '0';
            $activities = $d->activities->map(function ($a) {
                $details = implode(', ', array_filter([$a->type, $a->best_time_to_visit ? "best: {$a->best_time_to_visit}" : null]));

                return $a->name.($details !== '' ? " ({$details})" : '');
            })->implode('; ');

            return "ID:{$d->id} | {$d->name} | Town: {$d->municipality?->name} | Category: {$d->category->name} | Coords: {$d->latitude},{$d->longitude} | Hours: {$hours} | Fee: PHP {$fee}"
                .($activities !== '' ? " | Activities: {$activities}" : '');
        })->implode("\n");

        $matrixText = '';
        foreach ($distanceMatrix as $entry) {
            $matrixText .= "From ID:{$entry['from']} to ID:{$entry['to']}: {$entry['duration_min']} min, {$entry['distance_km']} km\n";
        }

        $townNames = $this->townNames($preferences);
        $townCount = count($preferences['municipality_ids']);

        $prompt = "You are a local travel itinerary planner for {$townNames}, Sorsogon, Philippines.\n\n";
        $prompt .= "Create a {$numDays}-day travel itinerary in which the tourist visits exactly ONE destination per day.\n\n";

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
        if (! empty($preferences['additional_preferences'])) {
            $prompt .= "- Additional notes from tourist: {$preferences['additional_preferences']}\n";
        }
        $prompt .= "\n";
        $prompt .= "HOW THIS TRIP WORKS:\n";
        $prompt .= "- You ONLY choose each day's destination and why. The planner itself schedules the destination's 3 listed Activities, picks one accommodation per town (where the tourist sleeps after the days spent there), every meal and the souvenir shop, and sets all clock times.\n";
        $prompt .= "- Each day: breakfast near last night's accommodation, the day's destination with lunch around noon, souvenir shopping, then dinner nearby. Travelling between towns takes most of a day, so a day never covers two towns.\n\n";
        $prompt .= "AVAILABLE VERIFIED DESTINATIONS (use ONLY these, reference by ID):\n";
        $prompt .= "{$destinationsList}\n\n";
        $prompt .= "TRAVEL TIME BETWEEN DESTINATIONS:\n";
        $prompt .= "{$matrixText}\n";
        $prompt .= "RULES:\n";
        $prompt .= "1. Use ONLY destination IDs from the list above. Never invent places, and never use the same destination twice.\n";
        $prompt .= "2. Schedule exactly ONE destination per day (a \"destinations\" list with one entry).\n";
        if ($townCount > 1) {
            $prompt .= "3. Give EVERY selected town ({$townNames}) at least one day, and keep the days spent in the same town consecutive (finish one town before moving to the next).\n";
        } elseif ($destinationCount < $numDays) {
            $prompt .= "3. There are only {$destinationCount} destinations for {$numDays} days: use each of them once, and give the remaining days an empty \"destinations\" list (free days at the accommodation).\n";
        } else {
            $prompt .= "3. Choose the {$numDays} destinations that best match the tourist's interests and notes, keeping consecutive days close to each other.\n";
        }
        $prompt .= "4. Respect operating hours and keep entrance fees in line with the tourist's budget.\n";
        $prompt .= "5. Every \"reason\" is ONE sentence of at most 25 words explaining why that destination suits THIS tourist (interests, budget, pace). Do not repeat the place's name.\n\n";
        $prompt .= "RESPOND WITH ONLY THIS JSON (no other text), with one entry for every day from 1 to {$numDays}:\n";
        $prompt .= "{\n";
        $prompt .= '  "title": "A short descriptive title for this itinerary",'."\n";
        $prompt .= '  "days": ['."\n";
        $prompt .= "    {\n";
        $prompt .= '      "day_number": 1,'."\n";
        $prompt .= '      "destinations": [ { "destination_id": 123, "reason": "Why this destination suits the tourist" } ]'."\n";
        $prompt .= "    }\n";
        $prompt .= "  ]\n";
        $prompt .= "}\n";

        return $prompt;
    }



    /**
     * Returns ['title', 'days' => [dayNumber => ['destination_id', 'activities', 'reason']]]
     * with exactly one entry per trip day, each destination used at most once and any ID
     * not in the eligible list dropped. Days left without a destination get the nearest
     * unused one; whatever is left stays a free day.
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
                // Skip destinations we can't use, and anything after the day's one destination.
                if ($days[$n]['destination_ids'] !== [] || $id === null || ! in_array($id, $validIds, true) || in_array($id, $used, true)) {
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

        $days = $this->groupDaysByTown($days, $destinations);

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

        $stays = [];
        foreach ($townIds as $sort => $town) {
            $center = $this->centroid($chosen->where('municipality_id', $town))
                ?? $this->centroid($destinations->where('municipality_id', $town))
                ?? $this->centroid($towns->only([$town]));
            $name = $towns[$town]->name ?? 'this town';
            [$stay, $reason, $note] = $center ? $this->chooseStay($center, $preferences, $town, $name) : [null, null, null];

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
            'food' => FoodBeverage::approved()->whereIn('municipality_id', $townIds)
                ->whereNotNull('latitude')->whereNotNull('longitude')->get(),
            'shops' => $this->souvenirShopQuery()->whereIn('municipality_id', $townIds)
                ->whereNotNull('latitude')->whereNotNull('longitude')->get(),
        ];
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
        $pool = Accommodation::approved()->with('municipality:id,name')->where('municipality_id', $town)
            ->whereNotNull('latitude')->whereNotNull('longitude')->get();
        $inTown = $pool->isNotEmpty();
        if (! $inTown) {
            $pool = Accommodation::approved()->with('municipality:id,name')
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
        $budgetMatched = $stay->price_range === $budget;

        $reason = "Your base in {$townName}, {$km} km from the center of the places you'll visit there, so those days start and end with a short trip.";

        if ($typeMatched && $budgetMatched && $inTown) {
            return [$stay, $reason, null];
        }

        $wanted = ($typeMatched ? 'accommodation' : $this->accommodationTypeLabel($preferences))
            .($budgetMatched ? '' : ' in the '.ucfirst($budget).' price range');
        $note = $inTown
            ? "We're sorry — we couldn't find a verified {$wanted} near your destinations in {$townName}. "
            : "We're sorry — there's no verified accommodation in {$townName} yet"
                .($typeMatched && $budgetMatched ? '' : ", and no {$wanted} close by").'. ';
        $note .= "We recommended {$stay->name} instead: {$this->describeListing($stay->type, $stay->price_range)}, {$km} km from the center of your destinations in {$townName}"
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

            [$rooms, $sleeps] = $this->recommendRooms($stay['accommodation'], $preferences);
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
     * day is spent in (where that night is spent). Each day has one destination in one town:
     * breakfast near last night's stay (or a packed one on a sunrise day), then the destination
     * for its own listed activities, timed so nobody waits around: a sunset or evening
     * destination starts in the afternoon, just in time. Lunch is a food place near where the
     * tourist is around noon (before, during or after the visit), else the destination's picnic
     * or a packed lunch; a visit that ends early is followed by souvenir shopping before lunch.
     * On a packed pace an afternoon merienda follows, then dinner near where the day ends. No
     * destination, food place or shop repeats; when no unused food place is left, the meal is
     * at the stay.
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
        $foodScore = fn ($f) => $this->foodScore($f, $preferences);
        $stayIn = fn (?int $town) => $town === null ? null : ($plan['stays'][$town]['accommodation'] ?? null);
        $usedFood = [];
        $usedShops = [];
        $items = [];
        $dayTowns = [];
        $lastTown = (int) $preferences['municipality_ids'][0];

        foreach ($days as $dayNumber => $day) {
            $date = $startDate?->copy()->addDays($dayNumber - 1);
            $destination = $destinations->firstWhere('id', $day['destination_ids'][0] ?? null);
            $town = $destination ? (int) $destination->municipality_id : $lastTown;
            // Where last night was spent (day 1 starts at the stay of its own town).
            $home = $stayIn($dayNumber === 1 ? $town : ($dayTowns[$dayNumber - 1] ?? $town)) ?? $stayIn($town);
            $sequence = 1;
            $prev = $home;

            $add = function (string $type, $place, int $start, int $minutes, array $fields, $from) use (&$items, &$sequence, $dayNumber) {
                $items[] = $this->itemRow($type, $place, $from, $dayNumber, $sequence++, $fields + [
                    'time_slot' => $this->timeRange($start, $minutes),
                    'duration_minutes' => $minutes,
                ]);
            };
            $addFood = function (array $pick, int $start, int $minutes, string $notes, string $reason, string $meal, $near, $from) use ($add, &$usedFood, $preferences) {
                $usedFood[] = $pick['place']->id;
                $add(ItineraryItem::TYPE_FOOD_BEVERAGE, $pick['place'], $start, $minutes, [
                    'food_beverage_id' => $pick['place']->id,
                    'notes' => $notes,
                    'reason' => $reason,
                    'preference_note' => $this->foodNote($pick['place'], $meal, $near, $preferences),
                ] + $this->dishPlan($pick['place'], $preferences), $from);
            };
            $addMealAtStay = function (string $meal, int $start, string $near, $from, Accommodation $stay) use ($add, $preferences) {
                $add(ItineraryItem::TYPE_ACCOMMODATION, $stay, $start, $meal === 'breakfast' ? self::BREAKFAST_MINUTES : self::MEAL_MINUTES, [
                    'accommodation_id' => $stay->id,
                    'notes' => ucfirst($meal)." at {$stay->name}.",
                    'reason' => "Your accommodation, so you can enjoy {$meal} without another trip.",
                    'preference_note' => $this->mealAtStayNote($meal, $near, $stay, $preferences),
                    'cost_note' => ucfirst($meal).' at your accommodation — price not listed',
                ], $from);
            };
            // A food place open around $at near $near, else on the way from $near to $then.
            $findMeal = function ($near, $then, int $at, string $why) use ($plan, &$usedFood, $foodScore, $date, $preferences) {
                $open = fn ($f) => $this->isOpen($f, $at, $at + self::MEAL_MINUTES, $date);
                $pick = $this->pickPlace($plan['food'], $usedFood, fn ($f) => $this->kmBetween($near, $f), self::NEARBY_KM, $open, $foodScore);
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
            // A souvenir shop near where the tourist is (any unused one on the last day).
            $shopped = false;
            $goShopping = function () use (&$prev, &$clock, &$usedShops, &$shopped, $plan, $add, $dayNumber, $numDays, $date, $preferences) {
                $shopped = true;
                $site = $prev;
                if (! $site) {
                    return;
                }
                $shop = $this->pickPlace($plan['shops'], $usedShops, fn ($s) => $this->kmBetween($site, $s),
                    $dayNumber === $numDays ? null : self::SHOP_RADIUS_KM,
                    fn ($s) => $this->isOpen($s, $clock + $this->travelMinutes($site, $s), $clock + $this->travelMinutes($site, $s) + self::SHOPPING_MINUTES, $date));
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

            $hours = $destination ? $this->openingHours($destination->operating_hours, $date) : null;
            $planned = $destination ? $this->plannedActivities($destination, $destination, $pace, $hours) : [];
            $sunriseDay = collect($planned)->contains(fn ($a) => $a['label'] === 'dawn');
            $note = null;

            // Breakfast near last night's stay, or a packed one at the destination on a sunrise day.
            if ($sunriseDay) {
                $clock = self::WINDOWS['dawn'][0] - $this->travelMinutes($home, $destination);
                $note = 'Breakfast places are still closed this early: bring breakfast from your accommodation to enjoy after sunrise.';
                // A day that starts before 5 AM doesn't also run into the night.
                $planned = array_map(fn ($a) => $a['label'] === 'evening' ? $this->outsideWindow($a) : $a, $planned);
            } else {
                $breakfastAt = $this->minutesOf($settings['breakfast']);
                $clock = $breakfastAt + self::BREAKFAST_MINUTES;
                $near = $home ?? $destination;
                $breakfast = $near ? $this->pickPlace($plan['food'], $usedFood, fn ($f) => $this->kmBetween($near, $f), self::NEARBY_KM,
                    fn ($f) => $this->isOpen($f, $breakfastAt, $breakfastAt + self::BREAKFAST_MINUTES, $date), $foodScore) : null;

                if ($breakfast) {
                    $addFood($breakfast, $breakfastAt, self::BREAKFAST_MINUTES, "Breakfast near {$near->name}.",
                        $this->mealReason($breakfast, $near, 'an easy start before heading out', $preferences), 'breakfast', $near, $prev);
                    $prev = $breakfast['place'];
                } elseif ($home) {
                    $addMealAtStay('breakfast', $breakfastAt, $home->name, null, $home);
                }
            }

            $hadLunch = false;
            if ($destination) {
                // The first day in a new town starts with the drive from last night's town.
                $drive = $this->travelMinutes($prev, $destination);
                if ($home && $dayNumber > 1 && ($dayTowns[$dayNumber - 1] ?? $town) !== $town && $drive > 60) {
                    $note = trim("{$note} Today starts with the ~{$drive} min drive from {$home->name} to {$destination->municipality?->name}.");
                }

                // A sunset or evening destination starts just in time, not hours early.
                $latest = ! $sunriseDay && $this->isAnchor($destination) ? $this->latestArrival($destination, $pace, $date) : null;
                $arrival = function () use (&$clock, &$prev, $hours, $destination, $latest) {
                    $at = $clock + $this->travelMinutes($prev, $destination);
                    if ($hours) {
                        $at = max($at, $hours[0]);
                    }

                    return $latest !== null ? max($at, $latest) : $at;
                };
                $layout = $this->layoutVisit($arrival(), $planned);

                // Lunch first when the visit starts after 11:30.
                if ($layout['start'] >= 690) {
                    $hadLunch = true;
                    $from = $prev ?? $destination;
                    [$lunch, $lunchReason] = $findMeal($from, $destination, max($clock, 690), "a break before {$destination->name}");
                    if ($lunch) {
                        $lunchAt = max($clock + $this->travelMinutes($from, $lunch['place']), 690);
                        $addFood($lunch, $lunchAt, self::MEAL_MINUTES, "Lunch near {$from->name}.", $lunchReason, 'lunch', $from, $prev);
                        $prev = $lunch['place'];
                        $clock = $lunchAt + self::MEAL_MINUTES;
                        $layout = $this->layoutVisit($arrival(), $planned);
                    } elseif ($home) {
                        // The morning is spent at the stay anyway: have lunch there before heading out.
                        $lunchAt = max($clock + $this->travelMinutes($prev, $home), 720);
                        $addMealAtStay('lunch', $lunchAt, $from->name, $prev, $home);
                        $prev = $home;
                        $clock = $lunchAt + self::MEAL_MINUTES;
                        $layout = $this->layoutVisit($arrival(), $planned);
                    } else {
                        $note = trim("{$note} We're sorry — there's no unused verified food place near {$from->name} or on the way to {$destination->name}, so please bring a packed lunch.");
                    }
                }

                // Lunch during the visit when it runs over noon.
                $lunchStop = null;
                if (! $hadLunch && $layout['start'] < 720 && $layout['end'] > 720) {
                    $hadLunch = true;
                    [$lunch, $lunchReason] = $findMeal($destination, null, 720, 'a short break from your visit');
                    $picnic = collect($planned)->search(fn ($a) => stripos($a['name'], 'picnic') !== false);
                    if ($lunch) {
                        $away = $this->travelMinutes($destination, $lunch['place']);
                        $layout = $this->layoutVisit($arrival(), $planned, [[720 - $away, 780 + $away]]);
                        $lunchStop = [$lunch, $lunchReason];
                    } elseif ($picnic !== false) {
                        $planned[$picnic] = ['at' => [720, 780], 'name' => "{$planned[$picnic]['name']} — your picnic lunch"] + $planned[$picnic];
                        $layout = $this->layoutVisit($arrival(), $planned);
                        $note = trim("{$note} We're sorry — there's no unused verified food place near {$destination->name}, so lunch is a picnic here. Pack food from your accommodation or a store on the way.");
                    } else {
                        $layout = $this->layoutVisit($arrival(), $planned, [[720, 780]]);
                        $note = trim("{$note} We're sorry — there's no unused verified food place near {$destination->name}, so please bring a packed lunch for around noon.");
                    }
                }

                $add(ItineraryItem::TYPE_DESTINATION, $destination, $layout['start'], $layout['end'] - $layout['start'], [
                    'destination_id' => $destination->id,
                    'notes' => "Day at {$destination->name}.",
                    'reason' => $this->visitReason($day, $destination),
                    'activities' => $layout['activities'],
                    'preference_note' => $note ?: null,
                ] + $this->entranceCost($destination, $travelers), $prev);
                if ($lunchStop) {
                    $addFood($lunchStop[0], 720, self::MEAL_MINUTES, "Lunch break during your visit to {$destination->name}.", $lunchStop[1], 'lunch', $destination, $destination);
                }
                $prev = $destination;
                $clock = $layout['end'];
            } elseif ($home) {
                // No destination left for today: a free day at the stay.
                $start = $clock + $this->travelMinutes($prev, $home);
                $layout = $this->layoutVisit($start, $this->plannedActivities(null, $home, $pace, null));
                $add(ItineraryItem::TYPE_ACCOMMODATION, $home, $layout['start'], $layout['end'] - $layout['start'], [
                    'accommodation_id' => $home->id,
                    'notes' => "Free day at {$home->name}.",
                    'reason' => 'A slower day to rest and enjoy your stay between outings.',
                    'activities' => $layout['activities'],
                    'preference_note' => $note,
                ], $prev);
                $prev = $home;
                $clock = $layout['end'];
            }

            $tonight = $stayIn($town) ?? $home;

            // A visit that ends well before noon: souvenir shopping first, then lunch.
            if (! $hadLunch && $clock < 690) {
                $goShopping();
            }
            if (! $hadLunch && $prev) {
                [$lunch, $lunchReason] = $findMeal($prev, null, max($clock, 720), "a break near {$prev->name}");
                if ($lunch) {
                    $lunchAt = max($clock + $this->travelMinutes($prev, $lunch['place']), 720);
                    $addFood($lunch, $lunchAt, self::MEAL_MINUTES, "Lunch near {$prev->name}.", $lunchReason, 'lunch', $prev, $prev);
                    $prev = $lunch['place'];
                    $clock = $lunchAt + self::MEAL_MINUTES;
                } elseif ($tonight) {
                    $lunchAt = max($clock + $this->travelMinutes($prev, $tonight), 720);
                    $addMealAtStay('lunch', $lunchAt, $prev->name, $prev, $tonight);
                    $prev = $tonight;
                    $clock = $lunchAt + self::MEAL_MINUTES;
                }
            }
            if (! $shopped) {
                $goShopping();
            }

            // Packed pace: an afternoon merienda (snack) near where the tourist is.
            if ($settings['merienda'] && $prev && $clock <= 960) {
                $snackTime = max($clock, 900);
                [$snack, $snackReason] = $findMeal($prev, null, $snackTime, 'a quick afternoon merienda');
                if ($snack) {
                    $snackAt = max($clock + $this->travelMinutes($prev, $snack['place']), 900);
                    $addFood($snack, $snackAt, self::MERIENDA_MINUTES, "Afternoon merienda (snack) near {$prev->name}.", $snackReason, 'merienda', $prev, $prev);
                    $prev = $snack['place'];
                    $clock = $snackAt + self::MERIENDA_MINUTES;
                }
            }

            // Dinner near where the day ends, then on the way back to tonight's stay, then at the stay.
            if ($prev) {
                $dinnerTarget = $this->minutesOf($settings['dinner']);
                $dinnerStart = fn ($f) => max($dinnerTarget, $clock + $this->travelMinutes($prev, $f));
                $dinnerOpen = fn ($f) => $this->isOpen($f, $dinnerStart($f), $dinnerStart($f) + self::MEAL_MINUTES, $date);
                $dinner = $this->pickPlace($plan['food'], $usedFood, fn ($f) => $this->kmBetween($prev, $f), self::NEARBY_KM, $dinnerOpen, $foodScore);
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
                } elseif ($tonight) {
                    $addMealAtStay('dinner', max($dinnerTarget, $clock + $this->travelMinutes($prev, $tonight)), $prev->name, $prev, $tonight);
                }
            }

            $dayTowns[$dayNumber] = $town;
            $lastTown = $town;
        }

        return [$items, $dayTowns];
    }

    /** A destination whose visit is pinned to the end of the day (a sunset or evening activity). */
    private function isAnchor(Destination $destination): bool
    {
        return $destination->activities->contains(fn ($a) => in_array($this->timeOfDay($a->name, $a->best_time_to_visit, 'anytime'), ['sunset', 'evening'], true));
    }

    /** The latest arrival at an anchor that still does its other activities before its sunset/evening one. */
    private function latestArrival(Destination $destination, string $pace, ?Carbon $date): int
    {
        $planned = $this->plannedActivities($destination, $destination, $pace, $this->openingHours($destination->operating_hours, $date));
        $pinned = collect($planned)->filter(fn ($a) => in_array($a['label'], ['sunset', 'evening'], true));
        if ($pinned->isEmpty()) {
            return $this->visitEndTime($pace);
        }

        return $pinned->min(fn ($a) => $a['window'][0]) - (count($planned) - $pinned->count()) * $this->activityMinutes($pace);
    }

    /**
     * A visit's activities with their time of day ('label') and window: exactly the
     * destination's listed activities, each as long as the pace allows (two generic ones on a free day at the
     * stay). A dawn activity on a relaxed pace or before the place opens, or an evening one
     * after it closes, is kept but loses its window and says when it's best instead.
     *
     * @return array<int, array{name: string, duration: int, label: string, window: ?array, description: ?string}>
     */
    private function plannedActivities(?Destination $destination, $site, string $pace, ?array $hours): array
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
                'duration' => $this->activityMinutes($pace),
                'label' => $label,
                'window' => self::WINDOWS[$label] ?? null,
                'description' => $activity->description,
            ];

            $tooEarly = $label === 'dawn' && ($pace === 'relaxed' || ($hours && $hours[0] > self::WINDOWS['dawn'][0] + 30));
            $tooLate = $label === 'evening' && $hours && $hours[1] < self::WINDOWS['evening'][0] + 30;
            $planned[] = $tooEarly || $tooLate ? $this->outsideWindow($entry) : $entry;
        }

        return $planned ?: [['name' => "Explore {$destination->name}", 'duration' => $this->activityMinutes($pace) * 2, 'label' => 'anytime', 'window' => null, 'description' => null]];
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
        if (preg_match('/stargaz|firefl|night|evening|moonless/', $name)) {
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
            'filipino_bicolano' => fn ($f) => $f->cuisine_type === 'Filipino',
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

    /** 2 for the cuisine the tourist asked for (or no preference), +1 for their budget. */
    private function foodScore(FoodBeverage $place, array $preferences): int
    {
        $cuisine = $this->cuisineMatcher($preferences);

        return ($cuisine === null || $cuisine($place) ? 2 : 0) + ($place->price_range === $this->budgetLevel($preferences) ? 1 : 0);
    }

    private function mealReason(array $pick, $near, string $why, array $preferences): string
    {
        $place = $pick['place'];
        $kind = $place->cuisine_type ? mb_strtolower($place->cuisine_type).' spot' : 'dining spot';
        $matches = $this->foodScore($place, $preferences) === 3 && ($preferences['food_preference'] ?? 'no_preference') !== 'no_preference'
            ? ", matching your {$this->cuisineLabel($preferences)} preference and budget"
            : '';

        return "The closest verified {$kind} to {$near->name} (".number_format($pick['km'], 1)." km), {$why}{$matches}.";
    }

    /** Apology when a meal place doesn't match the tourist's cuisine or budget, else null. */
    private function foodNote(FoodBeverage $place, string $meal, $near, array $preferences): ?string
    {
        $cuisine = $this->cuisineMatcher($preferences);
        $cuisineMatched = $cuisine === null || $cuisine($place);
        $budget = $this->budgetLevel($preferences);
        $budgetMatched = $place->price_range === $budget;

        if ($cuisineMatched && $budgetMatched) {
            return null;
        }

        $wanted = ($cuisineMatched ? '' : $this->cuisineLabel($preferences).' ').'place'
            .($budgetMatched ? '' : ' in the '.ucfirst($budget).' price range');

        return "We're sorry — we couldn't find an unused verified {$wanted} near {$near->name} for {$meal}. "
            ."We recommended {$place->name} instead: {$this->describeListing($place->cuisine_type ? "{$place->cuisine_type} spot" : 'dining spot', $place->price_range)}, "
            .number_format($this->kmBetween($near, $place), 1)." km from {$near->name} — the closest verified option.";
    }

    private function mealAtStayNote(string $meal, string $near, $stay, array $preferences): string
    {
        return "We're sorry — there's no other verified food place open near {$near} in {$this->townNames($preferences)} that isn't already in your trip, "
            ."so {$meal} is at your accommodation, {$stay->name}. Ask them about their {$meal} options.";
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
     * Estimated drive between two places at ~30 km/h, rounded up to 5 minutes so the whole
     * schedule lands on clean times (same estimate shown between stops).
     */
    private function travelMinutes($from, $to): int
    {
        if (! $from || ! $to || $from === $to) {
            return 0;
        }

        $km = round($this->kmBetween($from, $to), 2);

        return (int) (ceil(($km / 30) * 60 / 5) * 5);
    }

    private function travelers(array $preferences): int
    {
        return max(1, (int) ($preferences['travelers'] ?? 1));
    }

    /** @return array{cost_min: float, cost_max: float, cost_note: string} */
    private function entranceCost(Destination $destination, int $travelers): array
    {
        if ($destination->entrance_fee === null) {
            return ['cost_min' => 0, 'cost_max' => 0, 'cost_note' => 'Entrance fee not listed'];
        }

        $fee = (float) $destination->entrance_fee;
        if ($fee <= 0) {
            return ['cost_min' => 0, 'cost_max' => 0, 'cost_note' => 'Free entrance'];
        }

        return ['cost_min' => $fee * $travelers, 'cost_max' => $fee * $travelers, 'cost_note' => $this->timesTravelers('Entrance fee', $travelers)];
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
     * The 3 dishes for the tourist's budget that each traveler orders, and what they cost
     * (a price-band estimate when the place has no priced menu).
     *
     * @return array{dishes: array<int, array{quantity: int}>, cost_min: float, cost_max: float, cost_note: string}
     */
    private function dishPlan(FoodBeverage $place, array $preferences): array
    {
        $travelers = $this->travelers($preferences);
        $dishes = $this->tierPick($place->menuItems()->approved()->get(), $this->budgetLevel($preferences), self::DISHES_PER_MEAL)
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

    /** Σ price × quantity of picked rows (each with a pivot quantity): "3 dishes × 4". */
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
            'distance_from_prev_km' => $prev ? round($this->kmBetween($prev, $place), 2) : null,
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

<?php

namespace App\Services;

use App\Contracts\ItineraryAI;
use App\Exceptions\ItineraryNotPossibleException;
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

    /**
     * Lunch is always 11:30 AM – 12:30 PM, on every day of the trip, never moved later to fit in
     * more sightseeing: the tourist is at the table by 11:30 (stops end in time, or a visit breaks
     * for it) and a day that can't keep it loses a stop instead (see generate()).
     */
    private const LUNCH_START = 690;

    private const LUNCH_END = 750;

    /** Breakfast is over by 8:00 AM; a day that starts later (an arrival) has had it before. */
    private const BREAKFAST_END = 480;

    /** Dinner is 6:00 – 7:30 PM: it starts by 6:30 PM and lasts an hour. */
    private const DINNER_START = 1080;

    private const LATEST_DINNER_START = 1110;

    /** No dinner is planned when the day only gets going after 8:45 PM. */
    private const LATEST_DINNER = 1245;

    /** How many times a day's plan is rebuilt with one stop fewer when it breaks a scheduling rule. */
    private const MAX_REPAIRS = 12;

    /**
     * Public transport (jeepney, bus, van, tricycle) is slower than driving and has waits and
     * transfers: road time × this factor, plus a wait for the ride (a tricycle for short hops,
     * a jeepney/bus/van with a tricycle to the entrance for longer ones). All estimates.
     */
    private const PUBLIC_TRANSPORT_FACTOR = 1.3;

    private const TRICYCLE_KM = 5;

    private const TRICYCLE_WAIT = 10;

    private const JEEPNEY_WAIT = 20;

    /** Two selected towns whose attractions are at most this far apart can share a day. */
    private const SHARED_DAY_KM = 30;

    /** A meal back at the stay only makes sense when it's at most this far (minutes) away. */
    private const STAY_MEAL_MINUTES = 20;

    /** How much each tourism-office classification counts when ranking destinations. */
    private const PRIORITY_POINTS = ['iconic' => 100, 'popular' => 60, 'recommended' => 30, 'hidden_gem' => 20];

    private const PRIORITY_LABELS = [
        'iconic' => 'Must-visit',
        'popular' => 'Popular attraction',
        'recommended' => 'Recommended attraction',
        'hidden_gem' => 'Hidden gem',
    ];

    private const TRANSPORT_LABELS = [
        'private_car' => 'private car',
        'motorcycle' => 'motorcycle',
        'rented_vehicle' => 'rented vehicle',
        'public_transport' => 'public transport (jeepney, bus, van, tricycle)',
        'no_preference' => 'no preference',
    ];

    /** Registering and the briefing before a boat trip or guided activity. */
    private const REGISTRATION_MINUTES = 20;

    /** Activities that need a boat, a guide or an operator, so a visit starts with registering. */
    private const OPERATOR_ACTIVITY = '/island.?hopping|sland hopping|boat trip|boating|whale shark|butanding|diving|snorkel|firefly|surfing lesson|guided|banana boat/i';

    /**
     * Each travel pace's daily schedule: breakfast time (over by 8:00 AM), how long each activity
     * takes, when a visit usually ends (minutes after midnight), dinner time (6:00 – 6:30 PM, so
     * it's over by 7:30), and whether an afternoon merienda stop is added. Sunrise activities are
     * only planned when the tourist asks for an early start (see wantsEarlyStart()).
     */
    private const PACES = [
        'relaxed' => ['breakfast' => '7:15 AM', 'activity' => 90, 'visit_end' => 990, 'dinner' => '6:00 PM', 'merienda' => false],
        'moderate' => ['breakfast' => '7:00 AM', 'activity' => 60, 'visit_end' => 1020, 'dinner' => '6:30 PM', 'merienda' => false],
        'packed' => ['breakfast' => '6:30 AM', 'activity' => 45, 'visit_end' => 1050, 'dinner' => '6:30 PM', 'merienda' => true],
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
        'evening' => [1170, 1290],  // 7:30 – 9:30 PM, after dinner
    ];

    /** Google driving times between destinations ("fromId-toId" => matrix entry), when available. */
    private array $roadLegs = [];

    /** How the tourist gets around (the itinerary's transport_preference); sets travel times and notes. */
    private string $transport = 'no_preference';

    /**
     * Whether sunrise activities (before breakfast) may be planned: only when the tourist asks
     * for an early start (see wantsEarlyStart()). Edits keep whatever time the tourist sets.
     */
    private bool $earlyStart = true;

    /**
     * Meals a day deliberately has no stop for, with why (a late arrival, an early departure, no
     * food place nearby, so the tourist is told what to do instead): [day => [meal => reason]].
     * The schedule check accepts these and nothing else.
     */
    private array $mealExcuses = [];

    /** Destinations that fit no day of the last build, by the day they were planned for: [day => [name]]. */
    private array $unplaced = [];

    /**
     * Selected towns planned together with a nearby one, because the trip has fewer days than
     * towns: [town id => the town whose days it shares]. Empty when every town has its own days.
     */
    private array $townOf = [];

    /** Reminders already given in this trip, so the same advice isn't repeated on every stop. */
    private array $said = [];

    /** Each stay's breakfast arrangement for this trip (see breakfastAtStay()). */
    private array $breakfastCache = [];

    /** The towns the planner chose when the tourist chose none: ['towns' => ids, 'note' => why, 'highlights' => names]. */
    private ?array $chosenTowns = null;

    /** Towns more than this far from the rest of the trip cost a day's worth of travel by public transport. */
    private const TOWN_GAP_KM_PUBLIC = 1.5;

    private const TOWN_GAP_KM_CAR = 0.8;

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
        // No municipality chosen: the tourist leaves it to us, so the province's best-known
        // attractions decide which towns the trip covers.
        $this->chosenTowns = null;
        if ($preferences['municipality_ids'] === []) {
            $this->chosenTowns = $this->chooseTowns($preferences);
            $preferences['municipality_ids'] = $this->chosenTowns['towns'];
        }
        // More towns than days: nearby towns share days, planned as one area.
        $preferences['all_town_ids'] = $preferences['municipality_ids'];
        $this->townOf = $this->shareDays($preferences['municipality_ids'], (int) $preferences['num_days']);
        $preferences['municipality_ids'] = array_values(array_unique(array_map(fn ($t) => $this->townOf[$t] ?? $t, $preferences['municipality_ids'])));
        $destinations = $this->getEligibleDestinations($preferences);

        $this->roadLegs = [];
        $this->transport = $preferences['transport_preference'] ?? 'no_preference';
        $this->earlyStart = $this->wantsEarlyStart($preferences);
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
        $parsedItinerary['days'] = $this->skipResortsAtResortStay($parsedItinerary['days'], $plan, $destinations, $preferences);

        // Build the days, then check every one against the scheduling rules (meals in their
        // windows, lunch 11:30 – 12:30, no overlaps, travel time between stops, opening hours…).
        // A day that breaks one is rebuilt with one stop fewer — never with a later lunch —
        // until it passes; the stops left out are named on that day.
        $dropped = [];
        $firstProblems = null;
        // Destinations taken out of the trip, never added back by a top-up, and the ones that fit no day by the day they were planned for.
        $excluded = [];
        $leftOut = [];
        for ($repair = 0; ; $repair++) {
            [$items, $dayTowns] = $this->buildDayItems($parsedItinerary['days'], $destinations, $plan, $preferences);
            $problems = $this->dayProblems($items, $destinations, $preferences);
            $firstProblems ??= $problems;
            // Stops that fit no day (too long for the time left, past the departure…) are taken
            // out, and the days that lost them are topped up again from places that fit.
            $unplacedIds = $destinations->whereIn('name', array_merge([], ...array_values($this->unplaced)))->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($problems === [] && array_diff($unplacedIds, $excluded) !== [] && $repair < self::MAX_REPAIRS) {
                foreach ($this->unplaced as $day => $names) {
                    $leftOut[$day] = array_merge($leftOut[$day] ?? [], $names);
                }
                $excluded = array_values(array_unique([...$excluded, ...$unplacedIds]));
                $parsedItinerary['days'] = $this->refill($parsedItinerary['days'], $excluded, $plan, $destinations, $preferences);

                continue;
            }
            if ($problems === [] || $repair >= self::MAX_REPAIRS) {
                break;
            }
            $repaired = false;
            foreach (array_keys($problems) as $day) {
                $victim = $this->stopToDrop($parsedItinerary['days'], $items, $day, $preferences);
                if ($victim === null) {
                    continue;
                }
                // Out of the trip, from whichever day the AI put it on (a stop may have moved days).
                foreach ($parsedItinerary['days'] as $n => $planned) {
                    $parsedItinerary['days'][$n]['destination_ids'] = array_values(array_diff($planned['destination_ids'], [$victim]));
                }
                $dropped[$day][] = $destinations->firstWhere('id', $victim)->name;
                $excluded[] = $victim;
                $repaired = true;
            }
            if (! $repaired) {
                break;
            }
        }
        if ($dropped !== []) {
            Log::info('Itinerary days rebuilt with fewer stops', ['user_id' => $user?->id, 'dropped' => $dropped, 'problems' => $firstProblems]);
        }
        // Named on the day they were planned for: those taken out along the way, and any that still fit no day.
        foreach ($this->unplaced as $day => $names) {
            $dropped[$day] = array_merge($dropped[$day] ?? [], $names);
        }
        foreach ($leftOut as $day => $names) {
            $dropped[$day] = array_merge($dropped[$day] ?? [], $names);
        }
        $items = $this->noteDroppedStops($items, $dropped);
        if ($this->chosenTowns !== null && $items !== []) {
            $items[0]['preference_note'] = $this->joinNotes($this->chosenTowns['note'], $items[0]['preference_note'] ?? null);
        }
        $dayPlans = $this->dayPlans($items, $destinations, $parsedItinerary['titles'] ?? []);

        $stays = $this->bookNights($plan['stays'], $dayTowns, $preferences);

        if ($problems !== []) {
            Log::warning('Itinerary schedule problems', ['user_id' => $user?->id, 'problems' => array_merge(...array_values($problems))]);
        }

        // Total for the whole group: every stop (entrance fees, dishes, souvenirs) plus the rooms.
        $stayMin = array_sum(array_map(fn ($s) => $s['cost_min'] ?? 0, $stays));
        $stayMax = array_sum(array_map(fn ($s) => $s['cost_max'] ?? 0, $stays));
        $costMin = array_sum(array_map(fn ($item) => $item['cost_min'] ?? 0, $items)) + $stayMin;
        $costMax = array_sum(array_map(fn ($item) => $item['cost_max'] ?? 0, $items)) + $stayMax;
        // Older screens show one stay: the first one the tourist sleeps at.
        $main = collect($stays)->first(fn ($s) => $s['night_days'] !== [] && $s['accommodation']) ?? collect($stays)->first();
        if (! $this->needsStay($preferences)) {
            $main = ['accommodation' => null, 'reason' => null, 'note' => "You said you don't need accommodation, so this plan suggests no stay and adds no room costs. Each day starts at its first stop."];
        }

        // All-or-nothing so a failed item insert doesn't leave an empty itinerary behind.
        return DB::transaction(function () use ($user, $claimTokenHash, $preferences, $stays, $main, $parsedItinerary, $aiResponse, $items, $stayMin, $stayMax, $costMin, $costMax, $dayPlans) {
            $itinerary = Itinerary::create([
                'user_id' => $user?->id,
                'claim_token' => $user ? null : $claimTokenHash,
                'municipality_id' => $preferences['all_town_ids'][0],
                'accommodation_id' => $main['accommodation']?->id,
                'accommodation_reason' => $main['reason'] ?? null,
                'accommodation_note' => $main['note'] ?? null,
                'accommodation_cost_min' => $stayMin,
                'accommodation_cost_max' => $stayMax,
                'title' => $parsedItinerary['title'],
                'day_plans' => $dayPlans,
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
            $itinerary->municipalities()->attach($preferences['all_town_ids']);

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
        $this->transport = $itinerary->transport_preference ?? 'no_preference';
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

                // A lunch or dinner break whose visit was removed or moved is now an ordinary meal after the stop before it.
                if ($place instanceof FoodBeverage && preg_match('/^(Lunch|Dinner) break during your visit to (.+?)\.(?= |$)/u', (string) $item->notes, $host)
                    && ! ($prev instanceof Destination && $prev->name === $host[2])) {
                    $item->notes = preg_replace('/^(Lunch|Dinner) break during your visit to .+?\.(?= |$)/u', $prev ? "{$host[1]} near {$prev->name}." : "{$host[1]}.", $item->notes);
                    $item->update(['notes' => $item->notes]);
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

                // A lunch or dinner break is a round trip from the destination it happens during.
                if (! ($place instanceof FoodBeverage && preg_match('/^(Lunch|Dinner) break/', (string) $item->notes))) {
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

    /** Makes $roomIds the options picked at $accommodationId, replacing those picked there before. */
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
            ->whereIn('municipality_id', $preferences['all_town_ids'] ?? $preferences['municipality_ids'])
            ->whereIn('category_id', $categoryIds)
            ->with(['category:id,name', 'municipality:id,name', 'activities:id,destination_id,name,description,type,best_time_to_visit,duration_minutes,what_to_wear', 'estimates', 'fees', 'amenities:id,destination_id,name'])
            ->select('id', 'name', 'description', 'dining_details', 'meals_offered', 'visitor_guidelines', 'category_id', 'municipality_id', 'latitude', 'longitude', 'operating_hours', 'entrance_fee',
                'is_featured', 'tourism_priority', 'setting', 'average_rating', 'total_reviews', 'view_count')
            ->get()
            ->each(fn ($d) => $this->applyEstimates($d))
            ->each(fn ($d) => $this->planningTown($d));
    }

    /** The destination IDs the tourist asked for (preferred_destinations), as integers. */
    /**
     * Whether the tourist asked for an early start (in their notes: sunrise, dawn, an early
     * morning or departure), the only case where an activity is planned before breakfast.
     * Never on a relaxed pace.
     */
    private function wantsEarlyStart(array $preferences): bool
    {
        return ($preferences['travel_pace'] ?? 'moderate') !== 'relaxed'
            && preg_match('/sunrise|dawn|early (start|morning|departure|riser|bird)|maaga|madaling.?araw/i', (string) ($preferences['additional_preferences'] ?? '')) === 1;
    }

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

            throw new ItineraryNotPossibleException("No {$what} in {$where} match your interests. Try adding more interests.");
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
        return blank($stay->check_in_time) && ! preg_match('/check[\s-]?in/i', (string) $stay->operating_hours) && $stay->estimate('check_in') !== null;
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
            'Places to stay' => ! $this->needsStay($preferences) ? collect() : Accommodation::approved()->whereIn('municipality_id', $towns)->with(['rooms' => fn ($q) => $q->approved()->select('id', 'accommodation_id', 'name', 'sort_order')])->limit(8)->get(['id', 'name', 'municipality_id', 'type', 'price_range', 'description', 'dining_details'])
                ->map(fn ($a) => $line($a, trim("{$a->type}, {$a->price_range}", ', '))
                    .($a->dining_details ? ' Dining: '.$this->promptExcerpt($a->dining_details, 120) : '')
                    .($a->rooms->isNotEmpty() ? ' Options: '.$a->rooms->pluck('name')->filter()->unique()->take(6)->implode(', ').'.' : '')),
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
        $ids = $preferences['municipality_ids'] ?? (isset($preferences['municipality_id']) ? [$preferences['municipality_id']] : []);

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
        // The most worthwhile places first, so they're the first the AI reads.
        $destinationsList = $destinations->sortByDesc(fn ($d) => $this->priorityScore($d))->map(function ($d) use ($preferred) {
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

            $standing = array_filter([
                $this->priorityLabel($d),
                (int) $d->total_reviews >= 5 ? "rated {$d->average_rating}/5 by {$d->total_reviews} visitors" : null,
            ]);
            $setting = ['indoor' => 'indoors', 'outdoor' => 'open-air', 'mixed' => 'partly covered'][$d->setting] ?? null;

            return "ID:{$d->id} | {$d->name} | Town: {$d->municipality?->name} | Category: {$d->category->name} | Coords: {$d->latitude},{$d->longitude} | Hours: {$hours} | Fee: {$fee}"
                .($standing !== [] ? ' | Standing: '.implode('; ', $standing) : '')
                .($setting ? " | Setting: {$setting}" : '')
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
        if ($this->chosenTowns !== null) {
            $prompt .= "The tourist didn't choose any municipality, so the planner chose {$townNames} for the province's best-known attractions that match their interests ("
                .implode(', ', $this->chosenTowns['highlights'])."). Build the trip around those highlights, visiting the towns in that order (a sensible route).\n\n";
        }
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
        $prompt .= '- Getting around: '.(self::TRANSPORT_LABELS[$this->transport] ?? 'no preference')."\n";
        if (! empty($preferences['travelers'])) {
            $prompt .= "- Travelers: {$preferences['travelers']}\n";
        }
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
        $prompt .= "- You ONLY choose each day's destinations, their order and why. The planner itself handles arrival".($this->needsStay($preferences) ? ' and check-in on Day 1' : ' on Day 1').", schedules each destination's listed activities, ".($this->needsStay($preferences) ? 'picks one accommodation per town' : 'suggests no accommodation (the tourist has their own arrangements)').", every meal (breakfast, lunch, an afternoon merienda and dinner at verified restaurants, so never spend a destination on a meal), rest breaks and souvenir shopping, and sets all clock times.\n";
        $shared = collect($this->townOf)->groupBy(fn ($rep) => $rep, true)->map(fn ($group, $rep) => array_unique([$rep, ...array_keys($group->all())]));
        if ($shared->isEmpty()) {
            $prompt .= "- Travelling between towns takes most of a day, so a day never covers two towns.\n";
        } else {
            $names = Municipality::whereIn('id', $preferences['all_town_ids'])->pluck('name', 'id');
            $prompt .= "- Travelling between towns takes most of a day, so a day never covers two towns, EXCEPT these neighbouring towns, which share their days as one area: "
                .$shared->map(fn ($ids) => collect($ids)->map(fn ($id) => $names[$id] ?? '')->implode(' + '))->implode('; ').".\n";
        }
        $prompt .= "- FIXED MEAL TIMES the planner always keeps: breakfast 6:00 – 8:00 AM, lunch 11:30 AM – 12:30 PM (never later), dinner 6:00 – 7:30 PM. A day whose stops can't fit around these loses a stop, so choose fewer, closer destinations rather than more: a comfortable day beats a crowded one.\n";
        if ($this->transport === 'public_transport') {
            $prompt .= "- The tourist uses PUBLIC TRANSPORT (jeepneys, buses, vans, tricycles): every trip takes longer than driving, with waits and transfers. Prefer destinations close together and near town centres or main roads, and keep the day's stops few.\n";
        }
        $prompt .= "\n";
        $prompt .= "AVAILABLE VERIFIED DESTINATIONS (use ONLY these, reference by ID):\n";
        $prompt .= "{$destinationsList}\n\n";
        $prompt .= $this->otherPlacesContext($preferences);
        $prompt .= $roadTimes
            ? "TRAVEL TIME BETWEEN DESTINATIONS (driving times from Google Maps):\n"
            : "TRAVEL TIME BETWEEN DESTINATIONS (rough estimates from straight-line distance; real road time is longer):\n";
        $prompt .= "{$matrixText}\n";
        $prompt .= "HOW TO CHOOSE (like a knowledgeable local guide, not a map search):\n";
        $prompt .= "- First decide each town's highlights: places with a Standing (Must-visit, Popular, featured, well rated), then the attractions Sorsogon is best known for that you can recognise in the list. These come first; build each day around one highlight.\n";
        $prompt .= "- Then add only places that make that day better: close to the highlight, a different kind of experience, worth the time. Never pick a place just because it is near another one, and prefer two worthwhile stops to four ordinary ones.\n";
        $prompt .= "- Lesser-known places are welcome when they offer something genuinely worthwhile (a hidden gem), not as filler.\n\n";
        $prompt .= "RULES:\n";
        $prompt .= "1. Use ONLY destination IDs from the list above. Never invent places, and never use the same destination twice in the trip.\n";
        $prompt .= "2. Up to {$perDay} destinations per day ({$firstDay} on Day 1). Put destinations that are close to each other on the same day, in an order that avoids backtracking. Different days should feature different experiences.\n";
        $prompt .= "3. Vary each day: never two beach or swimming spots on the same day, and mix water, nature, viewpoints, heritage and resorts. Don't put two physically demanding stops back to back. A sunrise spot goes first in its day, and a sunset or night-time spot (one whose activities are best in the evening) last.\n";
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
        $prompt .= "6. Every \"reason\" is one or two sentences, at most 40 words, written like a local guide: what makes the place worth it and what the tourist will actually do there (from its listed activities). The tourist already sees the place's description, so don't restate it, and avoid filler (\"explore the area\", \"enjoy the destination\", \"we recommend\"). Don't repeat the place's name, and never state prices, hours, facilities or conditions the list doesn't give (never call an open-air place indoor).\n";
        $prompt .= "7. Each day has a \"title\" naming its real highlight and a \"highlight_id\" (one of that day's destination IDs).\n\n";
        $prompt .= "RESPOND WITH ONLY THIS JSON (no other text), with one entry for every day from 1 to {$numDays}:\n";
        $prompt .= "{\n";
        $prompt .= '  "title": "A short descriptive title for this itinerary",'."\n";
        $prompt .= '  "days": ['."\n";
        $prompt .= "    {\n";
        $prompt .= '      "day_number": 1,'."\n";
        $prompt .= '      "title": "A short, specific title for the day (max 8 words), e.g. Pink sand and island hopping in Matnog",'."\n";
        $prompt .= '      "highlight_id": 123,'."\n";
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
        $titles = [];
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
            // The day's title, kept with its highlight (the planner may move or reorder days).
            $title = $this->cleanText($day['title'] ?? null, 80);
            $ids = array_filter(array_column($byDay[$dayNumber], 'id'));
            $highlight = in_array((int) ($day['highlight_id'] ?? 0), $ids, true) ? (int) $day['highlight_id'] : (reset($ids) ?: null);
            if ($title !== null && $highlight !== null) {
                $titles[$highlight] = $title;
            }
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
            $pick = $pool->sortBy(fn ($d) => ($center ? $this->km($center, $d) : 0) - $this->priorityScore($d) / 2)->first();
            if ($pick) {
                $days[$n]['destination_ids'][] = (int) $pick->id;
            }
        }

        $days = $this->promoteHighlights($this->shapeDays($this->groupDaysByTown($days, $destinations), $destinations, $preferences), $destinations, $preferences);

        $title = $this->cleanText($parsed['title'] ?? null);

        return [
            'title' => $title !== null ? Str::limit($title, 250) : "{$numDays}-Day Sorsogon Trip",
            'days' => $days,
            'titles' => $titles,
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
                ->sortBy(fn ($d) => ($center ? $this->km($center, $d) : 0) - $this->priorityScore($d) / 2)
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
                $roomy = collect($ids)->contains(fn ($id) => (self::PRIORITY_POINTS[$byId[$id]->tourism_priority] ?? 0) >= self::PRIORITY_POINTS['iconic']);
                if (count($ids) >= $this->dayTarget($n, $preferences) - ($roomy && count($ids) > 1 ? 1 : 0)) {
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
                // A day built around a highlight isn't padded with unranked places just to add stops.
                $hasHighlight = collect($ids)->contains(fn ($id) => $this->priorityScore($byId[$id]) >= self::PRIORITY_POINTS['popular']);
                $pick = $destinations
                    ->reject(fn ($d) => in_array((int) $d->id, $used, true))
                    ->reject(fn ($d) => $hasHighlight && count($ids) >= 2 && $this->priorityScore($d) < self::PRIORITY_POINTS['hidden_gem'])
                    ->filter(fn ($d) => (int) $d->municipality_id === $town && $fits($ids, $d, $n))
                    // Worth counts as much as closeness: a must-visit place is worth ~100 minutes more.
                    ->sortBy(fn ($d) => ($near->isEmpty() ? 0 : $near->min(fn ($s) => $this->travelMinutes($s, $d)))
                        + (in_array($this->experienceKind($d), $kinds, true) ? 30 : 0)
                        - (array_intersect($uncovered, $this->interestsOf($preferences, $d)) !== [] ? 60 : 0)
                        - (in_array((int) $d->id, $preferred, true) ? 120 : 0)
                        - $this->priorityScore($d))
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
     * Makes sure each town's best-known attraction is in the trip, whatever the AI picked: the
     * town's highest-ranked unused place (ranked by the tourism office's classification,
     * featured status and visitor ratings, never a guess) joins one of the town's days when
     * it fits there, or takes the place of that day's clearly weaker stop. Places the tourist
     * asked for are never replaced.
     */
    private function promoteHighlights(array $days, Collection $destinations, array $preferences): array
    {
        $byId = $destinations->keyBy(fn ($d) => (int) $d->id);
        $asked = $this->preferredIds($preferences);
        $townOfDay = fn (array $day) => $day['destination_ids'] === [] ? null : (int) $byId[$day['destination_ids'][0]]->municipality_id;

        foreach ($preferences['municipality_ids'] as $town) {
            $used = array_merge(...array_values(array_column($days, 'destination_ids')));
            $best = $destinations->where('municipality_id', $town)
                ->reject(fn ($d) => in_array((int) $d->id, $used, true))
                ->filter(fn ($d) => $this->priorityScore($d) >= self::PRIORITY_POINTS['popular'])
                ->sortByDesc(fn ($d) => $this->priorityScore($d))
                ->first();
            if (! $best) {
                continue;
            }
            foreach ($days as $n => $day) {
                if ($townOfDay($day) !== (int) $town) {
                    continue;
                }
                $ids = $day['destination_ids'];
                $before = $ids === [] ? null : $byId[end($ids)];
                if (count($ids) < $this->dayTarget($n, $preferences) && $this->fitsDay($ids, $best, $n, $byId, $preferences)) {
                    $days[$n]['destination_ids'][] = (int) $best->id;
                } else {
                    $weakest = collect($ids)->reject(fn ($id) => in_array($id, $asked, true))
                        ->sortBy(fn ($id) => $this->priorityScore($byId[$id]))->first();
                    if ($weakest === null || $this->priorityScore($byId[$weakest]) > $this->priorityScore($best) - 30) {
                        continue;
                    }
                    $kept = array_values(array_diff($ids, [$weakest]));
                    // Still no room (a short arrival day): the highlight keeps only the stops the
                    // tourist asked for, and the weaker ones make way.
                    if (! $this->fitsDay($kept, $best, $n, $byId, $preferences)) {
                        $kept = array_values(array_filter($ids, fn ($id) => in_array($id, $asked, true)));
                        if (! $this->fitsDay($kept, $best, $n, $byId, $preferences)) {
                            continue;
                        }
                    }
                    foreach (array_diff($ids, $kept) as $gone) {
                        unset($days[$n]['reasons'][$gone]);
                    }
                    $days[$n]['destination_ids'] = [...$kept, (int) $best->id];
                }
                $days[$n]['reasons'][(int) $best->id] = $this->addedReason($best, $before);
                break;
            }
        }

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
        $lunch = $from < self::LUNCH_START && $until > self::LUNCH_END ? self::MEAL_MINUTES : 0;

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
        $lunchDue = $clock < self::LUNCH_START;
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
            if ($lunchDue && $clock > self::LUNCH_START) {
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

        return $this->visitEndTime($preferences['travel_pace'] ?? 'moderate') - $from - ($from < self::LUNCH_START ? self::MEAL_MINUTES : 0);
    }

    /**
     * A stay's check-in time in minutes after midnight: the one it lists ("Check-in: 1:00 PM…"),
     * else its usual (estimated, see checkInEstimated()) one, else null.
     */
    private function checkInMinutes(Accommodation $stay): ?int
    {
        $pattern = '/check[\s-]?in\b[^0-9]{0,10}(\d{1,2})(?::(\d{2}))?\s*([ap])\.?\s*m\b/i';
        if (! preg_match($pattern, 'check-in '.$stay->check_in_time, $m) && ! preg_match($pattern, (string) $stay->operating_hours, $m) && ! preg_match($pattern, 'check-in '.$stay->estimate('check_in'), $m)) {
            return null;
        }

        return ((int) $m[1] % 12 + (strtolower($m[3]) === 'p' ? 12 : 0)) * 60 + (int) $m[2];
    }

    /**
     * Where a meal can fall back to when no food place is found: $stay, unless the tourist has
     * checked out of it today; then tonight's new stay when they're moving on, else nowhere.
     */
    private function mealStay(?Accommodation $stay, ?Accommodation $home, ?Accommodation $tonight, bool $checkedOut): ?Accommodation
    {
        if (! $stay || ! $checkedOut || ! $home || ! $this->samePlace($stay, $home)) {
            return $stay;
        }

        return $tonight && ! $this->samePlace($tonight, $home) ? $tonight : null;
    }

    /** A stay's listed (or usual, estimated) check-out time in minutes after midnight, else null. */
    private function checkOutMinutes(Accommodation $stay): ?int
    {
        // "11:00 AM" or "12:00 noon"; in the opening hours only after the words "check-out".
        $texts = [(string) $stay->check_out_time, (string) $stay->estimate('check_out')];
        if (preg_match('/check[\s-]?out\b([^.;]*)/i', (string) $stay->operating_hours, $listed)) {
            $texts[] = $listed[1];
        }
        foreach ($texts as $text) {
            if (preg_match('/(\d{1,2})(?::(\d{2}))?\s*([ap])\.?\s*m\b/i', $text, $m)) {
                return ((int) $m[1] % 12 + (strtolower($m[3]) === 'p' ? 12 : 0)) * 60 + (int) ($m[2] ?? 0);
            }
            if (preg_match('/\bnoon\b/i', $text)) {
                return 720;
            }
        }

        return null;
    }

    /** Why the planner (not the AI) added a destination to a day. */
    private function addedReason(Destination $place, ?Destination $after): string
    {
        $kind = $this->kindLabel($this->experienceKind($place));
        $standing = $this->priorityLabel($place);
        $lead = $standing ? "{$standing}: " : '';
        if (! $after) {
            return "{$lead}a {$kind} that matches your interests.";
        }

        $minutes = $this->travelMinutes($after, $place);
        $change = $this->experienceKind($after) !== $this->experienceKind($place) ? "a {$kind} to vary the day" : "another {$kind}";

        return ucfirst("{$lead}{$change}, ".($minutes > 0 ? "{$minutes} min from {$after->name}." : "right by {$after->name}."));
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
     * a sunset/evening stop last. In between, the day's highlight (its clearly highest-ranked
     * place) comes first, while the morning is fresh and its morning activities are possible;
     * the rest each the nearest to where the tourist is, unless that repeats the kind of
     * experience just had and another kind is left.
     */
    private function orderStops(Collection $stops, ?Accommodation $home, string $pace, bool $arrivalDay): Collection
    {
        $dawn = ! $arrivalDay && $pace !== 'relaxed' ? $stops->first(fn ($d) => $this->hasDawn($d)) : null;
        $anchor = $stops->first(fn ($d) => $d !== $dawn && $this->isAnchor($d));
        $rest = $stops->reject(fn ($d) => $d === $dawn || $d === $anchor)->values();

        $ordered = [];
        $from = $dawn ?? $home;
        $lastKind = $dawn ? $this->experienceKind($dawn) : null;
        $ranked = $rest->sortByDesc(fn ($d) => $this->priorityScore($d))->values();
        if ($ranked->count() > 1 && $this->priorityScore($ranked[0]) > $this->priorityScore($ranked[1])) {
            $ordered[] = $ranked[0];
            $rest = $rest->reject(fn ($d) => $d === $ranked[0])->values();
            $from = $ranked[0];
            $lastKind = $this->experienceKind($ranked[0]);
        }
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
     * shopping are picked from. Nights and rooms are planned later by bookNights().
     *
     * @return array{stays: array<int, array>, food: Collection, shops: Collection}
     */
    private function planStays(array $parsed, Collection $destinations, array $preferences): array
    {
        $townIds = $preferences['municipality_ids'];
        $chosen = $destinations->whereIn('id', array_merge(...array_values(array_column($parsed['days'], 'destination_ids'))));
        $towns = Municipality::whereIn('id', $townIds)->get(['id', 'name', 'latitude', 'longitude'])->keyBy('id');
        $allTowns = $preferences['all_town_ids'] ?? $townIds;
        $food = FoodBeverage::approved()->with(['estimates', 'municipality:id,name'])->whereIn('municipality_id', $allTowns)
            ->whereNotNull('latitude')->whereNotNull('longitude')->get()
            ->each(fn ($f) => $this->applyEstimates($f))
            ->each(fn ($f) => $this->planningTown($f));
        $shops = $this->souvenirShopQuery()->with('municipality:id,name')->whereIn('municipality_id', $allTowns)
            ->whereNotNull('latitude')->whereNotNull('longitude')->get()
            ->each(fn ($shop) => $this->planningTown($shop));

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

    /**
     * On days based at a resort that has its own pool or beach, another resort adds nothing, so
     * resort destinations are left out of those days (unless the tourist asked for them by name).
     * Each one dropped is replaced by the nearest unused non-resort place in the same town that
     * fits the day; with none, the day keeps its other stops, or becomes free time at the resort.
     */
    private function skipResortsAtResortStay(array $days, array $plan, Collection $destinations, array $preferences): array
    {
        $byId = $destinations->keyBy(fn ($d) => (int) $d->id);
        $asked = $this->preferredIds($preferences);

        foreach ($days as $n => $day) {
            $ids = $day['destination_ids'];
            if ($ids === []) {
                continue;
            }
            $town = (int) $byId[$ids[0]]->municipality_id;
            $stay = $plan['stays'][$town]['accommodation'] ?? null;
            if (! $stay || ! $this->isResortWithWater($stay)) {
                continue;
            }

            $kept = array_values(array_filter($ids, fn ($id) => ! $this->isResortDestination($byId[$id]) || in_array($id, $asked, true)));
            $dropped = count($ids) - count($kept);
            if ($dropped === 0) {
                continue;
            }

            $days[$n]['destination_ids'] = $kept;
            $days[$n]['reasons'] = array_intersect_key($day['reasons'] ?? [], array_flip($kept));
            // A reason that describes a stop by the resort just removed ("5 min from …") is rewritten.
            $goneNames = array_map(fn ($id) => $byId[$id]->name, array_diff($ids, $kept));
            foreach ($kept as $i => $id) {
                $reason = (string) ($days[$n]['reasons'][$id] ?? '');
                if ($reason !== '' && collect($goneNames)->contains(fn ($name) => str_contains($reason, $name))) {
                    $days[$n]['reasons'][$id] = $this->addedReason($byId[$id], $i > 0 ? $byId[$kept[$i - 1]] : null);
                }
            }
            // An emptied day still belongs to its town, so its meals and stay are planned there.
            $days[$n]['town'] = $town;

            $used = array_merge(...array_values(array_column($days, 'destination_ids')));
            $replacements = $destinations
                ->filter(fn ($d) => (int) $d->municipality_id === $town && ! in_array((int) $d->id, $used, true) && ! $this->isResortDestination($d))
                ->sortBy(fn ($d) => $this->km([(float) $stay->latitude, (float) $stay->longitude], $d));
            foreach ($replacements as $candidate) {
                if ($dropped === 0) {
                    break;
                }
                if (! $this->fitsDay($days[$n]['destination_ids'], $candidate, $n, $byId, $preferences)) {
                    continue;
                }
                $after = $days[$n]['destination_ids'] === [] ? null : $byId[end($days[$n]['destination_ids'])];
                $days[$n]['destination_ids'][] = (int) $candidate->id;
                $days[$n]['reasons'][(int) $candidate->id] = "{$stay->name} already has its own pool or beach, so this day visits something different. ".$this->addedReason($candidate, $after);
                $dropped--;
            }
        }

        return $days;
    }

    /**
     * A Plan B for each day, for rain or running late, from verified listings in the day's town:
     * up to two indoor places not already in the trip (a destination with an indoor activity, a
     * museum or a church), a café or restaurant to wait out a downpour when there are fewer, and
     * which outdoor stop is easiest to shorten or skip. Worked out when the itinerary is shown,
     * so saved trips get one too and it follows edits.
     *
     * @return array<int, array{places: array<int, array{type: string, id: int, name: string, why: string}>, flexible: ?string}>
     */
    public function backupOptions(Itinerary $itinerary): array
    {
        $itinerary->loadMissing(['items.destination.activities', 'items.destination.category:id,name', 'items.accommodation', 'stays.accommodation']);
        $inTrip = $itinerary->items->pluck('destination_id')->filter()->map(fn ($id) => (int) $id)->all();
        $foodInTrip = $itinerary->items->pluck('food_beverage_id')->filter()->map(fn ($id) => (int) $id)->all();
        $plans = [];

        foreach ($itinerary->items->groupBy('day_number') as $day => $items) {
            $visits = $items->where('item_type', ItineraryItem::TYPE_DESTINATION)->sortBy('sequence')->pluck('destination')->filter()->values();
            $anchor = $visits->first() ?? $this->nightStay($itinerary->stays, (int) $day)?->accommodation ?? $this->nightStay($itinerary->stays, (int) $day - 1)?->accommodation;
            if (! $anchor || $anchor->latitude === null || $anchor->longitude === null) {
                continue;
            }
            $point = [(float) $anchor->latitude, (float) $anchor->longitude];
            $town = (int) $anchor->municipality_id;

            $places = Destination::published()->where('municipality_id', $town)->whereNotIn('id', $inTrip)
                ->whereNotNull('latitude')->whereNotNull('longitude')
                ->with(['category:id,name', 'activities:id,destination_id,name,type'])
                ->get()
                // Really sheltered (see sheltered()). Resorts are water stops, not a rain plan.
                ->filter(fn ($d) => ! $this->isResortDestination($d) && $this->sheltered($d))
                ->sortBy(fn ($d) => $this->km($point, $d))
                ->take(2)
                ->map(fn ($d) => [
                    'type' => 'destination', 'id' => (int) $d->id, 'name' => $d->name,
                    'why' => ($this->sheltered($d) === 'indoor' ? 'Indoors, ' : 'Partly covered, ').number_format($this->km($point, $d), 1).' km from '.$anchor->name.'.',
                ])
                ->values()->all();
            if (count($places) < 2) {
                $cafe = FoodBeverage::approved()->where('municipality_id', $town)->whereNotIn('id', $foodInTrip)
                    ->whereNotNull('latitude')->whereNotNull('longitude')->get()
                    ->sortBy(fn ($f) => $this->km($point, $f))->first();
                if ($cafe) {
                    $places[] = ['type' => 'food_beverage', 'id' => (int) $cafe->id, 'name' => $cafe->name,
                        'why' => 'A sit-down place to wait out a downpour, '.number_format($this->km($point, $cafe), 1).' km from '.$anchor->name.'.'];
                }
            }

            // The day's last outdoor stop is the one to shorten or skip; the rest keep their times.
            $outdoor = $visits->filter(fn ($d) => in_array($this->experienceKind($d), ['water', 'highland'], true) || $d->activities->contains('type', 'outdoor'))->last();
            $flexible = $outdoor
                ? "If it rains or you're running late, {$outdoor->name} is the easiest stop to shorten or skip. Skip beaches, boat trips and hilltops in heavy rain or strong wind."
                : null;
            $stay = $this->nightStay($itinerary->stays, (int) $day)?->accommodation ?? $this->nightStay($itinerary->stays, (int) $day - 1)?->accommodation;
            if ($places === [] && $stay) {
                $flexible = trim(($flexible ?? '')." There's no verified indoor attraction nearby on SOSOGON yet, so wait out a downpour at {$stay->name}.");
            }

            if ($places !== [] || $flexible) {
                $plans[(int) $day] = ['places' => $places, 'flexible' => $flexible];
            }
        }

        return $plans;
    }

    /**
     * Whether a destination is a shelter from the rain: 'indoor', 'mixed' (partly covered) or
     * null. The tourism office's setting decides when set; otherwise only a museum, gallery or
     * church building, or a place whose listed activities are mostly indoors and whose name
     * doesn't say it's open grounds (a sports complex, park, plaza or ruins is not a shelter).
     */
    private function sheltered(Destination $destination): ?string
    {
        if ($destination->setting) {
            return in_array($destination->setting, ['indoor', 'mixed'], true) ? $destination->setting : null;
        }
        if (preg_match('/complex|park|plaza|oval|field|grounds|ruins|boulevard|beach|falls|hill|camp/i', $destination->name)) {
            return null;
        }
        $building = mb_strtolower((string) $destination->category?->name) === 'church' || preg_match('/museum|gallery|church|cathedral|chapel|library/i', $destination->name);

        return $building || $this->mostlyIndoor($destination) ? 'indoor' : null;
    }

    /** Whether a visit is mostly out in the open (a beach, park, hill, falls…), where a packed lunch makes sense. */
    private function outdoorStop(Destination $destination): bool
    {
        if ($destination->setting) {
            return $destination->setting === 'outdoor';
        }

        return in_array($this->experienceKind($destination), ['water', 'highland', 'nature'], true) && ! $this->sheltered($destination);
    }

    /** At least half of a destination's listed activities are indoors. */
    private function mostlyIndoor(Destination $destination): bool
    {
        $indoor = $destination->activities->where('type', 'indoor')->count();

        return $indoor > 0 && $indoor * 2 >= $destination->activities->count();
    }

    /** A stay listed as a resort that offers a swimming pool or beach access. */
    private function isResortWithWater(Accommodation $stay): bool
    {
        $isResort = strcasecmp((string) $stay->type, 'Resort') === 0
            || in_array('resort', array_map('strtolower', (array) ($stay->classifications ?? [])), true);
        if (! $isResort) {
            return false;
        }
        $offers = strtolower(implode(' ', (array) ($stay->amenities ?? [])).' '.$stay->description);

        return (bool) preg_match('/swimming pool|\bpools?\b|beach access|beachfront|private beach|by the beach|on the beach/', $offers);
    }

    /** A destination that is itself a resort: in the Resort category, or named as one (e.g. a "Beach Resort"). */
    private function isResortDestination(Destination $destination): bool
    {
        return strcasecmp((string) $destination->category?->name, 'Resort') === 0
            || (bool) preg_match('/\bresorts?\b/i', (string) $destination->name);
    }

    /** Whether the tourist wants the planner to suggest a stay (true unless they said they don't need one). */
    private function needsStay(array $preferences): bool
    {
        return filter_var($preferences['accommodation_needed'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Options for the group at the tourist's chosen one: that option first, then others of the
     * same room category until everyone has a bed. An option whose capacity isn't known doesn't
     * limit the group: the property confirms it. Returns the options and how many they sleep
     * (less than $travelers when the category runs out).
     *
     * @return array{0: Collection, 1: int}
     */
    public function roomsForGroup(AccommodationRoom $room, int $travelers): array
    {
        $sameType = $room->category && ! $room->isEntireProperty()
            ? AccommodationRoom::approved()->where('accommodation_id', $room->accommodation_id)
                ->where('category', $room->category)
                ->when((float) $room->price > 0, fn ($q) => $q->where('price', '>', 0))
                ->whereKeyNot($room->id)->reorder('price')->orderBy('id')->get()
            : collect();
        $chosen = collect();
        $sleeps = 0;
        foreach ($sameType->prepend($room) as $candidate) {
            if ($sleeps >= $travelers) {
                break;
            }
            $chosen->push($candidate);
            $capacity = $this->capacity($candidate);
            if ($capacity === null) {
                return [$chosen, $travelers];
            }
            $sleeps += $capacity * max(1, (int) $candidate->unit_count);
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
     * Plans every night of the trip (after days 1 … num_days − 1, at least one) at the stay of
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
            if ($nights === 0 || ! $stay['accommodation']) {
                continue;
            }

            [$rooms, $sleeps] = $this->stayRooms($stay['accommodation'], $preferences);
            if ($rooms->isNotEmpty() && $sleeps < $travelers) {
                $stays[$town]['note'] = trim(($stay['note'] ?? '')." This stay has rooms for {$sleeps} of your {$travelers} travelers — contact them about extra beds.");
            }
            [$stays[$town]['cost_min'], $stays[$town]['cost_max']] = $this->roomsCost($stay['accommodation'], $rooms, $nights, $preferences);
            $stays[$town]['rooms'] = $rooms;
        }

        // Only where the tourist actually sleeps is a stay of this trip; a town they just pass through has none.
        return array_values(array_filter($stays, fn ($stay) => $stay['night_days'] !== []));
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
        $this->mealExcuses = [];
        $this->said = [];
        $this->breakfastCache = [];

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
            // Set once the tourist checks out of last night's stay today; no meal is planned there after that.
            $checkedOut = false;
            // On Day 1, the stay's listed check-in time when the tourist arrives before it.
            $checkInAt = null;
            // Lunch is 11:30 AM – 12:30 PM every day. A tourist arriving after 11:15 AM (too late to
            // be seated by 11:30) has lunch before arriving or on the way: none is planned.
            $lateArrival = $arrivalDay && ($arrivalAt ?? self::DEFAULT_ARRIVAL) + 15 > self::LUNCH_START;
            $hadDinner = false;
            // A meal the day deliberately goes without, with the note telling the tourist what to do
            // instead (shown on the day's first stop when nothing else says it).
            $dayNotes = [];
            $excuse = function (string $meal, ?string $note = null) use ($dayNumber, &$dayNotes) {
                $this->mealExcuses[$dayNumber][$meal] = true;
                if ($note !== null) {
                    $dayNotes[] = $note;
                }
            };

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
            $addMealAtStay = function (string $meal, int $start, string $searched, $from, Accommodation $stay) use ($add, $preferences) {
                // Breakfast follows the stay's own arrangement (included with the room, or not).
                $arrangement = $meal === 'breakfast' ? $this->breakfastAtStay($stay, $preferences) : null;
                $add(ItineraryItem::TYPE_ACCOMMODATION, $stay, $start, $meal === 'breakfast' ? self::BREAKFAST_MINUTES : self::MEAL_MINUTES, [
                    'accommodation_id' => $stay->id,
                    'notes' => ucfirst($meal)." at {$stay->name}.",
                    'reason' => null,
                    'preference_note' => $arrangement
                        ? $this->once($arrangement['note'] ?? "Ask {$stay->name} whether they serve breakfast.", "stay-breakfast-{$stay->id}")
                        : $this->mealAtStayNote($meal, $searched, $stay),
                    'cost_note' => ($arrangement['kind'] ?? null) === 'free' ? 'Breakfast included with your room' : ucfirst($meal).' at your accommodation — price not listed',
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
                    'reason' => $reason !== '' ? $reason : null,
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
            // A morning with nothing on until lunch is spent at the stay rather than waiting around
            // (back in time to reach lunch by 11:30).
            $freeMorning = function () use (&$clock, $base, $lastDay, $restAt, $restNotes, $endsInTime) {
                $until = self::LUNCH_START - 15;
                if ($base && ! $lastDay && $endsInTime($until) && $until - $clock >= self::IDLE_MINUTES) {
                    $restAt($base, $until, $restNotes('before lunch'), '');
                }
            };
            // Lunch at 11:30 AM – 12:30 PM, at a place the tourist can reach by 11:30: near where
            // they are (or on the way to $then), else near the stay, else at the stay. It is never
            // moved later. A message when none is possible: an excused gap when the tourist isn't
            // around then or there's simply no food place, else (no place reachable in time) left
            // for the schedule check, which then takes a stop out of the day.
            $lunch = function ($then) use (&$prev, &$clock, &$hadLunch, &$usedFood, &$checkedOut, $addFood, $addMealAtStay, $excuse, $home, $tonight, $lateArrival, $plan, $foodScore, $mealKm, $date, $preferences, $endsInTime, $dayEnd, $leaveNote): ?string {
                $hadLunch = true;
                $from = $prev ?? $then;
                if (! $from) {
                    return null;
                }
                if ($lateArrival) {
                    $excuse('lunch');

                    return null;
                }
                // Leaving before lunch would be over.
                if (! $endsInTime(self::LUNCH_END)) {
                    $excuse('lunch');

                    return $clock < $dayEnd ? "{$leaveNote}, before lunch (11:30 AM – 12:30 PM) would be over, so eat on the way or bring snacks." : null;
                }
                $at = self::LUNCH_START;
                $open = fn ($f) => $this->isOpen($f, $at, $at + self::MEAL_MINUTES, $date);
                $inTime = fn ($place) => $clock + $this->travelMinutes($prev, $place) <= $at;
                $lunchScore = fn ($f) => $this->foodScore($f, $preferences, 'lunch');
                $stay = $this->mealStay($then ? $home : $tonight, $home, $tonight, $checkedOut);
                $searches = [
                    [$from, fn ($f) => $this->kmBetween($from, $f), $mealKm, fn ($pick) => $this->mealReason($pick, $from, $then ? "a break before {$then->name}" : "a break near {$from->name}", $preferences)],
                ];
                if ($then && $then !== $from) {
                    $searches[] = [$from, fn ($f) => $this->kmBetween($from, $f) + $this->kmBetween($f, $then) - $this->kmBetween($from, $then), self::MAX_DETOUR_KM,
                        fn ($pick) => "There's no unused verified food place right next to {$from->name}; this one is on the way to {$then->name}{$this->detourNote($pick['km'])}."];
                }
                // Back near the stay only when it's close by.
                $stayClose = $stay && $this->travelMinutes($prev ?? $from, $stay) <= self::STAY_MEAL_MINUTES;
                if ($stayClose && ! $this->samePlace($from, $stay)) {
                    $searches[] = [$stay, fn ($f) => $this->kmBetween($stay, $f), $mealKm,
                        fn ($pick) => $this->mealReason($pick, $stay, 'close to your accommodation', $preferences)];
                }
                // Then the stay itself, and last the nearest food place anywhere that can still be reached by 11:30.
                $anywhere = [$from, fn ($f) => $this->kmBetween($from, $f), null,
                    fn ($pick) => 'The nearest listed restaurant open at lunchtime, '.number_format($pick['km'], 1)." km from {$from->name}."];
                $anyPlace = false;
                $meal = fn ($f) => ! $this->isSnackPlace($f);
                $snack = fn ($f) => true;
                // Restaurants nearby or on the way, the stay if it's close, a restaurant farther
                // off, the stay wherever it is, and only then a café or snack shop.
                $order = [...array_map(fn ($s) => [...$s, $meal], $searches), 'stay-close', [...$anywhere, $meal], 'stay', ...array_map(fn ($s) => [...$s, $snack], [...$searches, $anywhere])];
                foreach ($order as $search) {
                    if ($search === 'stay-close' || $search === 'stay') {
                        if ($stay && $inTime($stay) && ($search === 'stay' || $stayClose)) {
                            $addMealAtStay('lunch', $at, $this->samePlace($from, $stay) ? "near {$stay->name}" : "near {$from->name} or near {$stay->name}", $prev, $stay);
                            $prev = $stay;
                            $clock = self::LUNCH_END;

                            return null;
                        }

                        continue;
                    }
                    [$near, $distance, $maxKm, $reason, $kind] = $search;
                    $pick = $this->pickPlace($plan['food'], $usedFood, $distance, $maxKm, fn ($f) => $kind($f) && $open($f) && $inTime($f), $lunchScore);
                    if ($pick) {
                        $addFood($pick, $at, self::MEAL_MINUTES, "Lunch near {$near->name}.", $reason($pick), 'lunch', $near, $prev);
                        $prev = $pick['place'];
                        $clock = self::LUNCH_END;

                        return null;
                    }
                    $anyPlace = $anyPlace || $this->pickPlace($plan['food'], $usedFood, $distance, $maxKm, $open) !== null;
                }
                // A food place or the stay exists but can't be reached by 11:30: not excused, so
                // the day is rebuilt with fewer stops rather than lunch moved later.
                if ($anyPlace || $stay) {
                    return null;
                }
                $excuse('lunch');

                return "No restaurant is listed near {$from->name}".($then ? " or on the way to {$then->name}" : '').': bring a packed lunch for 11:30 AM.';
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
                $shopped = true;
                $start = $clock + $this->travelMinutes($site, $shop['place']);
                $add(ItineraryItem::TYPE_RETAIL_SHOP, $shop['place'], $start, self::SHOPPING_MINUTES, [
                    'retail_shop_id' => $shop['place']->id,
                    'notes' => "Pick up pasalubong — local souvenirs and delicacies — near {$site->name}.",
                    'reason' => 'The closest verified souvenir shop to '.$site->name.' ('.number_format($shop['km'], 1).' km).',
                ] + $this->souvenirPlan($shop['place'], $preferences), $site);
                $prev = $shop['place'];
                $clock = $start + self::SHOPPING_MINUTES;
            };

            $shopToday = $shopDaily || $dayNumber === $numDays;
            $dinnerTarget = $this->minutesOf($settings['dinner']);
            // Arriving after dinner time: dinner whenever the tourist can, if at all.
            $lateDinner = $arrivalDay && ($arrivalAt ?? self::DEFAULT_ARRIVAL) + self::ARRIVAL_MINUTES > self::LATEST_DINNER_START;
            // Check in before dinner if that's still to do, or rest at the stay instead of a long
            // wait (after travelling, even a shorter one on the arrival day).
            $beforeDinner = function () use (&$prev, &$clock, &$checkedIn, &$checkInAt, $base, $lastDay, $arrivalDay, $endsInTime, $dinnerTarget, $restAt, $restNotes) {
                if (! $base || ! $prev || $lastDay || ! $endsInTime($dinnerTarget)) {
                    return;
                }
                if (! $checkedIn) {
                    $from = max($clock + $this->travelMinutes($prev, $base), $checkInAt ?? 0);
                    $restAt($base, max($dinnerTarget - 15, $from + 30), $restNotes('before dinner'), '', 30);
                } elseif ($dinnerTarget - $clock >= ($arrivalDay ? 45 : self::IDLE_MINUTES)) {
                    $restAt($base, $dinnerTarget - 15, $restNotes('before dinner'), '', $arrivalDay ? 30 : 90);
                }
            };
            // Dinner (6:00 – 7:30 PM: starting by 6:30) near where the tourist is, then on the way
            // back to tonight's stay, then at the stay, wherever can be reached in time. None after
            // a late arrival or before an early departure (said so); none reachable in time is left
            // for the schedule check, which then takes a stop out of the day.
            $dinner = function () use (&$prev, &$clock, &$hadDinner, &$usedFood, &$checkedOut, $plan, $addFood, $addMealAtStay, $appendNote, $excuse, $home, $tonight, $lastDay, $lateDinner, $dinnerTarget, $endsInTime, $dayEnd, $leaveNote, $mealKm, $date, $preferences) {
                $hadDinner = true;
                if (! $prev) {
                    return;
                }
                if (! $endsInTime(max($dinnerTarget, $clock) + self::MEAL_MINUTES)) {
                    $excuse('dinner');
                    if ($clock < $dayEnd) {
                        $appendNote("{$leaveNote}, before dinner time, so plan to eat on the way.");
                    }

                    return;
                }
                if ($clock > self::LATEST_DINNER) {
                    $excuse('dinner');
                    $appendNote('You arrive after the usual dinner time, so eat on the way or ask '.($tonight?->name ?? 'your accommodation').' about food options.');

                    return;
                }
                $latestStart = $lateDinner ? self::LATEST_DINNER : self::LATEST_DINNER_START;
                $dinnerScore = fn ($f) => $this->foodScore($f, $preferences, 'dinner');
                $start = fn ($place) => max($dinnerTarget, $clock + $this->travelMinutes($prev, $place));
                $open = fn ($f) => $endsInTime($start($f) + self::MEAL_MINUTES) && $this->isOpen($f, $start($f), $start($f) + self::MEAL_MINUTES, $date);
                $inTime = fn ($f) => $start($f) <= $latestStart && $open($f);
                $near = fn ($f) => $this->kmBetween($prev, $f);
                $onTheWay = fn ($f) => $this->kmBetween($prev, $f) + $this->kmBetween($f, $tonight) - $this->kmBetween($prev, $tonight);

                // A restaurant near where the day ends or on the way back, then the stay, and only
                // then a café or snack shop.
                $findDinner = function (callable $kind) use ($plan, &$usedFood, $near, $onTheWay, $mealKm, $inTime, $open, $dinnerScore, $prev, $tonight, $preferences, &$anyPlace): array {
                    $pick = $this->pickPlace($plan['food'], $usedFood, $near, $mealKm, fn ($f) => $kind($f) && $inTime($f), $dinnerScore);
                    $anyPlace = $anyPlace || $this->pickPlace($plan['food'], $usedFood, $near, $mealKm, $open) !== null;
                    if ($pick) {
                        return [$pick, $this->mealReason($pick, $prev, 'right where your day ends', $preferences)];
                    }
                    if ($tonight && $prev !== $tonight) {
                        $pick = $this->pickPlace($plan['food'], $usedFood, $onTheWay, self::MAX_DETOUR_KM, fn ($f) => $kind($f) && $inTime($f), $dinnerScore);
                        $anyPlace = $anyPlace || $this->pickPlace($plan['food'], $usedFood, $onTheWay, self::MAX_DETOUR_KM, $open) !== null;
                        if ($pick) {
                            return [$pick, "On your way back from {$prev->name} to {$tonight->name}{$this->detourNote($pick['km'])}."];
                        }
                    }

                    return [null, null];
                };
                $anyPlace = false;
                $stay = $this->mealStay($tonight, $home, $tonight, $checkedOut);
                $stayInTime = $stay && $start($stay) <= $latestStart && $endsInTime($start($stay) + self::MEAL_MINUTES);
                [$pick, $reason] = $findDinner(fn ($f) => ! $this->isSnackPlace($f));
                if (! $pick && ! $stayInTime) {
                    [$pick, $reason] = $findDinner(fn ($f) => true);
                }
                if ($pick) {
                    $at = $start($pick['place']);
                    $addFood($pick, $at, self::MEAL_MINUTES, "Dinner near {$prev->name}.", $reason, 'dinner', $prev, $prev);
                    $prev = $pick['place'];
                    $clock = $at + self::MEAL_MINUTES;

                    return;
                }
                if ($stayInTime) {
                    $at = $start($stay);
                    $addMealAtStay('dinner', $at, "near {$prev->name}".($prev !== $stay ? " or on the way to {$stay->name}" : ''), $prev, $stay);
                    $prev = $stay;
                    $clock = $at + self::MEAL_MINUTES;

                    return;
                }
                // The nearest listed restaurant that can still be reached in time.
                $far = $this->pickPlace($plan['food'], $usedFood, $near, null, fn ($f) => ! $this->isSnackPlace($f) && $inTime($f), $dinnerScore);
                if ($far) {
                    $at = $start($far['place']);
                    $addFood($far, $at, self::MEAL_MINUTES, "Dinner near {$prev->name}.", 'The nearest listed restaurant open for dinner, '.number_format($far['km'], 1)." km from {$prev->name}.", 'dinner', $prev, $prev);
                    $prev = $far['place'];
                    $clock = $at + self::MEAL_MINUTES;

                    return;
                }
                if ($checkedOut && $lastDay) {
                    $excuse('dinner');
                    $appendNote("No restaurant is listed near {$prev->name} for dinner: eat on your way home.");
                } elseif (! $anyPlace && ! $stay) {
                    $excuse('dinner');
                    $appendNote("No restaurant is listed near {$prev->name} for dinner: eat on the way or bring food along.");
                }
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
                if ($lateArrival && $arrive < self::LUNCH_END + 90) {
                    $excuse('lunch', 'You arrive after lunch time (11:30 AM – 12:30 PM) has started, so have lunch before you arrive or on your way.');
                }
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
                        : 'Planned for a '.$this->formatMinutes(self::DEFAULT_ARRIVAL).' arrival; shift the times if you arrive earlier or later.';
                    // Arriving shortly before lunch: settle in quickly, so lunch still starts at 11:30.
                    $minutes = min(self::ARRIVAL_MINUTES, 1439 - $arrive);
                    if (! $lateArrival && $arrive < self::LUNCH_START && $arrive + $minutes > self::LUNCH_START) {
                        $minutes = self::LUNCH_START - $arrive;
                    }
                    $add(ItineraryItem::TYPE_ACCOMMODATION, $home, $arrive, $minutes, [
                        'accommodation_id' => $home->id,
                        'notes' => $arrivalNote.$when,
                        'reason' => null,
                        'preference_note' => match (true) {
                            $listed === null => "Check-in time isn't listed: confirm your room will be ready.",
                            ! $checkedIn && $usual => "Check-in is usually from {$this->formatMinutes($listed)} (typical, not listed): ask to leave your bags if you're early.",
                            ! $checkedIn => "Check-in is from {$this->formatMinutes($listed)}: ask to leave your bags if you're early.",
                            default => null,
                        },
                    ], null);
                    $prev = $home;
                    $clock += $minutes;
                }
                // An early arrival still gets breakfast near the stay, over by 8:00 AM; arriving
                // later, the tourist has had it before setting off.
                $near = $prev ?? $first ?? $townCenter;
                $breakfast = null;
                if ($near && $endsInTime($clock + self::BREAKFAST_MINUTES)) {
                    $breakfastAt = fn ($f) => $clock + $this->travelMinutes($prev, $f);
                    $breakfast = $this->pickPlace($plan['food'], $usedFood, fn ($f) => $this->kmBetween($near, $f), $mealKm,
                        fn ($f) => $breakfastAt($f) + self::BREAKFAST_MINUTES <= self::BREAKFAST_END
                            && $this->isOpen($f, $breakfastAt($f), $breakfastAt($f) + self::BREAKFAST_MINUTES, $date), $foodScore);
                    if ($breakfast) {
                        $at = $breakfastAt($breakfast['place']);
                        $addFood($breakfast, $at, self::BREAKFAST_MINUTES, "Breakfast near {$near->name}.",
                            $this->mealReason($breakfast, $near, 'an easy start before heading out', $preferences), 'breakfast', $near, $prev);
                        $prev = $breakfast['place'];
                        $clock = $at + self::BREAKFAST_MINUTES;
                    }
                }
                if (! $breakfast) {
                    $excuse('breakfast', $clock + self::BREAKFAST_MINUTES > self::BREAKFAST_END
                        ? null
                        : "There's no verified breakfast place open near {$near?->name} this early, so have breakfast before you set off.");
                }
            } elseif ($sunriseDay) {
                // The tourist asked for an early start: breakfast is packed, eaten after sunrise
                // (see the first stop), because the day starts before breakfast places open.
                $clock = self::WINDOWS['dawn'][0] - $this->travelMinutes($home, $first);
                $morningNote = 'You asked for an early start, so the day begins before breakfast places open: bring a packed breakfast from your accommodation to enjoy right after sunrise.';
                if (($lastDay || $moving) && $home) {
                    $morningNote .= " Check out of {$home->name} before you leave.";
                    $checkedOut = true;
                }
                // A day that starts before 5 AM doesn't also run into the night.
                $plans = array_map(fn ($planned) => array_map(fn ($a) => $a['label'] === 'evening' ? $this->outsideWindow($a) : $a, $planned), $plans);
            } else {
                $breakfastAt = $this->minutesOf($settings['breakfast']);
                $near = $home ?? $first ?? $townCenter;
                $breakfastFits = $endsInTime($breakfastAt + self::BREAKFAST_MINUTES);
                // Where breakfast is: at the stay when it's included with the room; when the stay
                // charges for it, there too unless the tourist is on a budget and a budget eatery
                // is close by; otherwise the nearest open food place (near the stay, or without
                // one, near the day's first stop), and the stay only as a last resort.
                $arrangement = $home ? $this->breakfastAtStay($home, $preferences)['kind'] : 'none';
                $openForBreakfast = fn ($f) => $this->isOpen($f, $breakfastAt, $breakfastAt + self::BREAKFAST_MINUTES, $date);
                $breakfast = match (true) {
                    ! $near || ! $breakfastFits, $arrangement === 'free' => null,
                    in_array($arrangement, ['paid', 'unknown'], true) => $this->budgetLevel($preferences) === 'budget'
                        ? $this->pickPlace($plan['food'], $usedFood, fn ($f) => $this->kmBetween($near, $f), self::NEARBY_KM,
                            fn ($f) => $f->price_range === 'budget' && $openForBreakfast($f), $foodScore)
                        : null,
                    default => $this->pickPlace($plan['food'], $usedFood, fn ($f) => $this->kmBetween($near, $f), $mealKm, $openForBreakfast, $foodScore),
                };

                $leaving = ($lastDay || $moving) && $home;
                $listedCheckOut = $leaving ? $this->checkOutMinutes($home) : null;
                $checkOut = function (int $at) use ($add, $home, $lastDay, $tonight, $dayEnd, $leaveNote, $listedCheckOut, &$checkedOut) {
                    $checkedOut = true;
                    $add(ItineraryItem::TYPE_ACCOMMODATION, $home, $at, self::CHECKOUT_MINUTES, [
                        'accommodation_id' => $home->id,
                        'notes' => "Check out of {$home->name}.",
                        'reason' => null,
                        'preference_note' => trim(($listedCheckOut !== null ? "Check-out is by {$this->formatMinutes($listedCheckOut)}. " : '')
                            .($lastDay ? 'Ask the front desk to keep your bags if you leave later. ' : "Take your bags along: tonight is at {$tonight->name}. ")
                            .($dayEnd !== null && $lastDay ? "{$leaveNote}, so the plan ends by then." : '')) ?: null,
                    ], null);
                };

                // Leaving this stay today with breakfast at a restaurant: check out first, in time to
                // reach it, and head out with your bags.
                if ($leaving && $breakfast) {
                    $at = $breakfastAt - self::CHECKOUT_MINUTES - $this->travelMinutes($home, $breakfast['place']);
                    $checkOut($dayEnd !== null ? max(0, min($at, $dayEnd - self::CHECKOUT_MINUTES)) : $at);
                }

                // Leaving before breakfast would be over: nothing more today (said after the check-out).
                $clock = $breakfastFits ? $breakfastAt + self::BREAKFAST_MINUTES : $dayEnd;
                if ($breakfast) {
                    $addFood($breakfast, $breakfastAt, self::BREAKFAST_MINUTES, "Breakfast near {$near->name}.",
                        $this->mealReason($breakfast, $near, 'an easy start before heading out', $preferences), 'breakfast', $near, $prev);
                    $prev = $breakfast['place'];
                } elseif ($home && $breakfastFits) {
                    $addMealAtStay('breakfast', $breakfastAt, "near {$home->name}", null, $home);
                } elseif ($breakfastFits) {
                    $excuse('breakfast', "There's no verified breakfast place near ".($near?->name ?? 'your first stop').' that opens by '.$this->formatMinutes($breakfastAt).', so have breakfast before heading out.');
                } else {
                    $excuse('breakfast');
                }

                // Breakfast at the stay, then check out: right after it when the day has places to
                // go, else after a free morning there, at the listed check-out time.
                if ($leaving && ! $checkedOut) {
                    $at = $breakfastFits ? $clock : max(0, ($dayEnd ?? $clock) - self::CHECKOUT_MINUTES);
                    // Checked out in time to be at lunch by 11:30 (near tonight's stay when moving on),
                    // even when check-out is later.
                    $lunchBy = self::LUNCH_START - max(15, $moving ? $this->travelMinutes($home, $tonight) : 0);
                    $by = $listedCheckOut !== null ? min($listedCheckOut, $lunchBy) - self::CHECKOUT_MINUTES : null;
                    if ($first === null && $by !== null && $by > $at && $endsInTime($by + self::CHECKOUT_MINUTES)) {
                        $add(ItineraryItem::TYPE_ACCOMMODATION, $home, $at, $by - $at, [
                            'accommodation_id' => $home->id,
                            'notes' => "Free time at {$home->name}: rest or enjoy its amenities before you check out.",
                            'reason' => 'No more stops today, so there\'s no rush to leave before check-out.',
                        ], $home);
                        $at = $by;
                    }
                    if ($dayEnd !== null) {
                        $at = max(0, min($at, $dayEnd - self::CHECKOUT_MINUTES));
                    }
                    $checkOut($at);
                    $clock = max($clock, $at + self::CHECKOUT_MINUTES);
                    $prev = $home;
                }
                if (! $breakfastFits) {
                    $appendNote("{$leaveNote}, before breakfast would be over, so eat on the way.");
                }
            }

            $lastKind = null;
            foreach ($stops as $index => $destination) {
                $planned = $plans[$destination->id];
                $hours = $this->openingHours($destination->operating_hours, $date);
                // After lunch elsewhere, a picnic here would be a second lunch: left out.
                if ($hadLunch && count($planned) > 1) {
                    $planned = array_values(array_filter($planned, fn ($a) => stripos($a['name'], 'picnic') === false)) ?: $planned;
                }
                $kind = $this->experienceKind($destination);
                $note = $index === 0 ? $morningNote : null;

                // Time to rinse off and change after a swim or a hike, before moving on.
                if (in_array($lastKind, ['water', 'highland'], true)) {
                    $clock += self::FRESHEN_UP_MINUTES;
                }

                // The first day in a new town starts with the drive from last night's town.
                $drive = $this->travelMinutes($prev, $destination);
                if ($index === 0 && $home && ! $arrivalDay && ($dayTowns[$dayNumber - 1] ?? $town) !== $town && $drive > 60) {
                    $trip = $this->transport === 'public_transport' ? 'trip by public transport' : 'drive';
                    $note = trim("{$note} Today starts with the ~{$drive} min {$trip} from {$home->name} to {$destination->municipality?->name}.");
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
                // An early start: the packed breakfast right after sunrise, by 8:00 AM.
                if ($index === 0 && $sunriseDay) {
                    $at = max(360, collect($layout['activities'])->map(fn ($a) => $this->slotMinutes($a['time'])[1] ?? null)->filter()->min() ?? 360);
                    $planned[] = ['at' => [$at, $at + 30], 'name' => 'Breakfast — the packed breakfast you brought', 'duration' => 30, 'label' => 'morning', 'window' => null,
                        'description' => 'A light breakfast with the view before the rest of the visit.'];
                    $layout = $this->layoutVisit($arrival(), $planned);
                }

                // Lunch is 11:30 AM – 12:30 PM. A destination with its own food serves it during the
                // visit; otherwise a visit that runs into lunchtime breaks for the nearest food place,
                // leaving in time to be seated by 11:30. A visit that would only get going around
                // lunchtime comes after lunch instead.
                $siteMeal = fn (string $meal, int $start, string $label) => ['at' => [$start, $start + self::MEAL_MINUTES], 'name' => ucfirst($meal).' at the on-site dining area', 'duration' => self::MEAL_MINUTES,
                    'label' => $label, 'window' => null, 'description' => $destination->dining_details];
                $servesOnSite = fn (string $meal) => in_array($meal, (array) ($destination->meals_offered ?? []), true) && $destination->dining_details;
                $onSiteLunch = $servesOnSite('lunch');
                $lunchNote = "Lunch is at {$destination->name}'s own dining area, so there's no separate restaurant stop; prices aren't listed on SOSOGON.";
                $lunchSpot = null;
                $lunchSpotReason = null;
                if (! $hadLunch && ! $onSiteLunch) {
                    [$lunchSpot, $lunchSpotReason] = $findMeal($destination, null, self::LUNCH_START, 'a short break from your visit', 'lunch');
                }
                $away = $lunchSpot ? $this->travelMinutes($destination, $lunchSpot['place']) : 0;
                // No food place near the destination (and no picnic spot to eat at): lunch is where
                // the tourist goes next (back near the stay, or the nearest food place), so the
                // visit ends in time to get there.
                $lunchAfter = false;
                $picnicSpot = collect($planned)->contains(fn ($a) => stripos($a['name'], 'picnic') !== false);
                $packedHere = false;
                if (! $hadLunch && ! $onSiteLunch && ! $lunchSpot && ! $picnicSpot) {
                    // The nearest place to eat: a restaurant anywhere, or the stay.
                    $restaurant = $this->pickPlace($plan['food'], $usedFood, fn ($f) => $this->kmBetween($destination, $f), null,
                        fn ($f) => ! $this->isSnackPlace($f) && $this->isOpen($f, self::LUNCH_START, self::LUNCH_END, $date))['place'] ?? null;
                    $stayForLunch = $this->mealStay($tonight ?? $home, $home, $tonight, $checkedOut);
                    $options = collect([$restaurant, $stayForLunch])->filter()->sortBy(fn ($p) => $this->travelMinutes($destination, $p));
                    $elsewhere = $options->first();
                    // A restaurant a short drive away: a lunch break there, then back to the visit.
                    if ($restaurant && $this->travelMinutes($destination, $restaurant) <= self::STAY_MEAL_MINUTES) {
                        $lunchSpot = ['place' => $restaurant, 'km' => $this->kmBetween($destination, $restaurant)];
                        $lunchSpotReason = 'The nearest listed restaurant to '.$destination->name.', '.$this->travelMinutes($destination, $restaurant).' min away.';
                        $away = $this->travelMinutes($destination, $restaurant);
                    // At a beach, park or other outdoor place far from any food, a packed lunch
                    // there beats a long trip back and forth, and so does cutting a long beach or
                    // island day short just to go back for lunch.
                    } elseif ($this->outdoorStop($destination) && (! $elsewhere || $this->travelMinutes($destination, $elsewhere) > self::STAY_MEAL_MINUTES
                        || $layout['end'] > self::LUNCH_START - $this->travelMinutes($destination, $elsewhere) + 60)) {
                        $packedHere = true;
                    } elseif ($elsewhere) {
                        $away = $this->travelMinutes($destination, $elsewhere);
                        $lunchAfter = true;
                    }
                }
                // When the tourist leaves the visit for lunch (after rinsing off from a swim or a hike).
                $breakFrom = self::LUNCH_START - $away - (! $onSiteLunch && $away > 0 && in_array($kind, ['water', 'highland'], true) ? self::FRESHEN_UP_MINUTES : 0);
                // Busy times inside the visit (lunch and dinner breaks) that its activities go around.
                $blocks = [];
                // With lunch elsewhere, a visit too short to be worth it before lunch comes after it.
                $needed = min($this->minimumVisit($destination, $pace), array_sum(array_column($planned, 'duration')));
                $lunchFirst = match (true) {
                    $onSiteLunch => $layout['start'] > self::LUNCH_START,
                    // An hour there before leaving for lunch is worth it; less isn't.
                    $lunchAfter => $layout['start'] + min($needed, 60) > $breakFrom,
                    default => $layout['start'] > $breakFrom - 30,
                };
                if (! $hadLunch && $lunchFirst) {
                    if ($shopToday && ! $shopped) {
                        $goShopping(self::LUNCH_START - 15);
                    }
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
                // A visit that only starts after dinner time (an evening activity) comes after dinner.
                if (! $hadDinner && $layout['start'] >= $dinnerTarget) {
                    $beforeDinner();
                    $dinner();
                    $layout = $this->layoutVisit($arrival(), $planned);
                }
                // A long wait (for a sunset stop) is spent souvenir shopping when today has a
                // souvenir stop, and otherwise resting at the stay.
                if ($shopToday && ! $shopped && $hadLunch && $layout['start'] - ($clock + $this->travelMinutes($prev, $destination)) >= self::SHOPPING_MINUTES + 30) {
                    $goShopping($layout['start'] - 30);
                    $layout = $this->layoutVisit($arrival(), $planned);
                }
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
                if ($lunchAfter && ! $hadLunch && $layout['start'] < $breakFrom) {
                    $limit = min($limit ?? $breakFrom, $breakFrom);
                }
                if ($limit !== null && $layout['end'] > $limit) {
                    $planned = $this->shortenVisit($planned, $layout['end'] - $limit);
                    $layout = $this->layoutVisit($arrival(), $planned);
                }
                // Still too long to leave for lunch in time: its last activities wait for another visit.
                while ($lunchAfter && ! $hadLunch && $layout['end'] > $breakFrom && count(array_filter($planned, fn ($a) => $a['label'] !== 'prep')) > 1) {
                    array_pop($planned);
                    $layout = $this->layoutVisit($arrival(), $planned);
                }
                // Such a stop is left out when it would still run past the end of the day, or
                // would be cut shorter than a sensible visit. A visit over before the tourist must
                // leave for lunch is followed by it; one that runs into lunchtime breaks for it.
                // Lunch eaten right here (its dining area or picnic spot) also ends a visit that's
                // over shortly before 11:30, rather than leaving to look for lunch elsewhere.
                $eatsHere = ! $lunchSpot && ($onSiteLunch || $picnicSpot || $packedHere);
                $lunchInside = ! $hadLunch && ! $lunchAfter && $layout['start'] <= self::LUNCH_START
                    && $layout['end'] > ($eatsHere ? self::LUNCH_START - 60 : $breakFrom);
                // The whole break: getting ready, the trip there and back, and lunch itself.
                $lunchMinutes = $lunchInside ? self::LUNCH_END + $away - min($breakFrom, self::LUNCH_START) : 0;
                $tooShort = array_sum(array_column($planned, 'duration'))
                    < min($this->minimumVisit($destination, $pace), array_sum(array_column($plans[$destination->id], 'duration')));
                $pastDeparture = $dayEnd !== null && ($tooShort || $dayEnd < $layout['end'] + $lunchMinutes);
                if ($pastDeparture || ($optional && ($tooShort || $this->visitEnd($planned, $pace, $hours) < $layout['end'] + $lunchMinutes))) {
                    // Not dropped from the trip: offered to the following days.
                    $carried[$destination->id] = [$movedFrom[$destination->id] ?? $dayNumber, $day['reasons'][$destination->id] ?? null];

                    continue;
                }

                // One long activity is split around a meal break instead of starting after it.
                $breakFor = function (int $from, int $to) use (&$planned, &$layout, &$blocks, $arrival) {
                    $planned = $this->splitAt($planned, $from - $arrival());
                    $blocks[] = [$from, $to];
                    $layout = $this->layoutVisit($arrival(), $planned, $blocks);
                };
                // A meal eaten at the destination itself (its dining area, a picnic, a packed meal).
                $mealHere = function (array $activity) use (&$planned, &$layout, &$blocks, $arrival) {
                    $planned = $this->splitAt($planned, $activity['at'][0] - $arrival());
                    $planned[] = $activity;
                    $layout = $this->layoutVisit($arrival(), $planned, $blocks);
                };

                // Lunch during the visit when it runs into lunchtime: 11:30 AM – 12:30 PM.
                $lunchStop = null;
                if ($lunchInside) {
                    $hadLunch = true;
                    $picnic = collect($planned)->search(fn ($a) => stripos($a['name'], 'picnic') !== false);
                    if ($onSiteLunch) {
                        $mealHere($siteMeal('lunch', self::LUNCH_START, 'midday'));
                        $note = trim("{$note} {$lunchNote}");
                    } elseif ($lunchSpot) {
                        $breakFor($breakFrom, self::LUNCH_END + $away);
                        $lunchStop = [$lunchSpot, $lunchSpotReason];
                        // Nothing left after the break: the visit simply ends in time for lunch,
                        // which follows it as usual.
                        if ($layout['end'] <= $breakFrom) {
                            array_pop($blocks);
                            $hadLunch = false;
                            $lunchStop = null;
                        }
                    } elseif ($picnic !== false) {
                        $activity = $planned[$picnic];
                        unset($planned[$picnic]);
                        $mealHere(['at' => [self::LUNCH_START, self::LUNCH_END], 'name' => "{$activity['name']} — your picnic lunch", 'duration' => self::MEAL_MINUTES] + $activity);
                        $note = trim("{$note} Lunch is a picnic here: pack food from your accommodation or a store on the way.");
                    } else {
                        $mealHere(['at' => [self::LUNCH_START, self::LUNCH_END], 'name' => 'Lunch — your packed lunch', 'duration' => self::MEAL_MINUTES, 'label' => 'midday', 'window' => null,
                            'description' => 'No restaurant is listed close by, so bring a packed lunch and water from your accommodation; check first that outside food is allowed here.']);
                    }
                }

                // Dinner during a visit that runs on into the evening (an evening activity after
                // it): 6:00 – 7:30 PM, at the destination's own dining area or a food place nearby.
                $dinnerStop = null;
                if (! $hadDinner && $layout['start'] < $dinnerTarget && $layout['end'] > $dinnerTarget + self::MEAL_MINUTES) {
                    $hadDinner = true;
                    if ($servesOnSite('dinner')) {
                        $mealHere($siteMeal('dinner', $dinnerTarget, 'evening'));
                        $note = trim("{$note} Dinner is at {$destination->name}'s own dining area; prices aren't listed on SOSOGON.");
                    } else {
                        [$meal, $mealReason] = $findMeal($destination, null, $dinnerTarget, 'dinner before the evening activities', 'dinner');
                        if ($meal) {
                            $dinnerAway = $this->travelMinutes($destination, $meal['place']);
                            $breakFor($dinnerTarget - $dinnerAway, $dinnerTarget + self::MEAL_MINUTES + $dinnerAway);
                            $dinnerStop = [$meal, $mealReason];
                        } else {
                            $mealHere(['at' => [$dinnerTarget, $dinnerTarget + self::MEAL_MINUTES], 'name' => 'Dinner break — your packed dinner', 'duration' => self::MEAL_MINUTES, 'label' => 'evening', 'window' => null, 'description' => null]);
                            $note = trim("{$note} No restaurant is listed near {$destination->name}: bring a packed dinner for the evening.");
                        }
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
                    $addFood($lunchStop[0], self::LUNCH_START, self::MEAL_MINUTES, "Lunch break during your visit to {$destination->name}.", $lunchStop[1], 'lunch', $destination, $destination);
                }
                if ($dinnerStop) {
                    $addFood($dinnerStop[0], $dinnerTarget, self::MEAL_MINUTES, "Dinner break during your visit to {$destination->name}.", $dinnerStop[1], 'dinner', $destination, $destination);
                }
                $prev = $destination;
                $clock = $layout['end'];
                $lastKind = $kind;
            }

            // A free day at the stay, unless the tourist only arrives late in the day: the
            // morning there until lunch, then (after lunch) the afternoon around it.
            $freeDay = $stops->isEmpty() && $home && (! $arrivalDay || $clock + 120 <= $this->visitEndTime($pace));
            $freeDayNote = $this->joinNotes($morningNote, $sightseeing === []
                ? 'You picked '.$this->interestList($preferences).' without a sightseeing interest, so today has no attractions: it is built around meals at verified local restaurants'
                    .($shopDaily ? ' and a souvenir stop' : '').', with free time at your stay. Add Nature, Beaches, Culture, Adventure or Relaxation for sightseeing.'
                : null);
            // Spent at tonight's stay once the tourist has moved on.
            $freeAt = $checkedOut && $tonight ? $tonight : $home;
            [$morningAtStay, $afternoonAtStay] = $freeDay ? $this->plannedActivities(null, $freeAt, $pace, null) : [null, null];
            $freeDayStop = function (array $activity, int $start, int $end) use (&$prev, &$clock, &$freeDayNote, $add, $freeAt) {
                $layout = $this->layoutVisit($start, [['duration' => $end - $start, 'label' => 'anytime', 'window' => null] + $activity]);
                $add(ItineraryItem::TYPE_ACCOMMODATION, $freeAt, $start, $end - $start, [
                    'accommodation_id' => $freeAt->id,
                    'notes' => "Free day at {$freeAt->name}.",
                    'reason' => null,
                    'activities' => $layout['activities'],
                    'preference_note' => $freeDayNote,
                ], $prev);
                $freeDayNote = null;
                $prev = $freeAt;
                $clock = $end;
            };
            if ($freeDay && ! $hadLunch && ! $checkedOut) {
                $start = $clock + $this->travelMinutes($prev, $home);
                if ($endsInTime(self::LUNCH_START - 15) && self::LUNCH_START - 15 - $start >= 45) {
                    $freeDayStop($morningAtStay, $start, self::LUNCH_START - 15);
                }
            }

            // Nothing else before lunch: souvenir shopping first (over in time to reach lunch by
            // 11:30), else a free morning at the stay. Shopping after lunch is over in time for
            // dinner, which then still starts within its evening window.
            if (! $hadLunch && $clock < self::LUNCH_START && $shopToday && ! $shopped) {
                $goShopping(self::LUNCH_START - 15);
            }
            if (! $hadLunch && $prev) {
                $freeMorning();
                if ($missing = $lunch(null)) {
                    $appendNote($missing);
                }
            }
            if ($freeDay) {
                $start = max($clock, self::LUNCH_END) + $this->travelMinutes($prev, $freeAt);
                $length = $afternoonAtStay['duration'];
                if ($endsInTime($start + $length) && $start + $length <= $dinnerTarget - 30) {
                    $freeDayStop($afternoonAtStay, $start, $start + $length);
                }
            }
            if (! $shopped && $shopToday) {
                $goShopping($dinnerTarget - 10);
            }

            // A merienda when the afternoon has room for one (always offered on a packed pace).
            if ($hadLunch && $prev && ($settings['merienda']
                ? $clock <= 960
                : $clock >= 840 && $clock <= 990 && $dinnerTarget - $clock >= self::IDLE_MINUTES)) {
                $merienda();
            }

            if (! $hadDinner) {
                $beforeDinner();
                $dinner();
            }

            // Notes for meals the day goes without, on its first stop.
            $firstToday = collect($items)->search(fn ($i) => $i['day_number'] === $dayNumber);
            if ($dayNotes !== [] && $firstToday !== false) {
                $items[$firstToday]['preference_note'] = $this->joinNotes(implode(' ', $dayNotes), $items[$firstToday]['preference_note'] ?? null);
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

        $this->unplaced = [];
        foreach ($carried as $id => [$fromDay]) {
            $this->unplaced[$fromDay][] = $byId[$id]->name;
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

        // Its other activities first, and dinner (6:00 – 7:30 PM) before an evening one.
        $others = collect($planned)->reject(fn ($a) => in_array($a['label'], ['sunset', 'evening'], true))->sum('duration');
        $dinner = $pinned->contains(fn ($a) => $a['label'] === 'evening') ? self::MEAL_MINUTES : 0;

        return $pinned->min(fn ($a) => $a['window'][0]) - $others - $dinner;
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

            $tooEarly = $label === 'dawn' && (! $this->earlyStart || $arrivalDay || $pace === 'relaxed' || ($hours && $hours[0] > self::WINDOWS['dawn'][0] + 30));
            // A sunset or evening activity needs about an hour of it before closing time.
            $tooLate = in_array($label, ['sunset', 'evening'], true) && $hours && $hours[1] < self::WINDOWS[$label][0] + 60;
            // An activity that only makes sense at its time (sunrise viewing at noon doesn't) is left out.
            if (! $tooEarly && ! $tooLate) {
                $planned[] = $entry;
            }
        }

        // No usable listed activities: the shortest sensible visit for this kind of place at this pace.
        $planned = $planned ?: [['name' => "Explore {$destination->name}", 'duration' => max($this->minimumVisit($destination, $pace), $this->activityMinutes($pace)), 'label' => 'anytime', 'window' => null, 'description' => null]];

        // Boat trips, guided tours and the like start with registering and a briefing.
        if ($this->needsOperator($destination)) {
            $onWater = (int) $destination->category_id === 8
                || $destination->activities->contains(fn ($a) => preg_match('/island|boat|whale shark|butanding|diving|snorkel|firefly|surf/i', (string) $a->name));
            array_unshift($planned, [
                'name' => 'Registration and briefing',
                'duration' => self::REGISTRATION_MINUTES,
                'label' => 'prep',
                'window' => null,
                'description' => $onWater
                    ? 'Register with the site or the local operator, settle any fees and get the safety briefing (and life vests for a boat trip) before heading out.'
                    : 'Register at the entrance, settle any fees and meet your guide before the tour starts.',
            ]);
        }

        return $planned;
    }

    /** Whether a destination's activities need a boat, a guide or an operator (island hopping, whale sharks, diving…). */
    private function needsOperator(Destination $destination): bool
    {
        return (int) $destination->category_id === 8
            || $destination->activities->contains(fn ($a) => preg_match(self::OPERATOR_ACTIVITY, (string) $a->name));
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
     * When the activity under way $minutes into the visit (a break starts there) runs past it,
     * splits it into the part before the break and the rest after it, so the visit doesn't wait
     * for the break to start; the rest is done whenever the visit resumes. A remainder under 30
     * minutes isn't worth coming back to, so the activity just ends at the break; with under 30
     * minutes of it before the break, the visit is left as it was.
     */
    private function splitAt(array $planned, int $minutes): array
    {
        $elapsed = 0;
        foreach ($planned as $i => $activity) {
            if (isset($activity['at']) || in_array($activity['label'], ['dawn', 'sunset', 'evening'], true)) {
                continue;
            }
            if ($elapsed + $activity['duration'] <= $minutes) {
                $elapsed += $activity['duration'];

                continue;
            }
            $before = $minutes - $elapsed;
            if ($before < 30) {
                return $planned;
            }
            if ($activity['duration'] - $before < 30) {
                $planned[$i]['duration'] = $before;

                return $planned;
            }
            array_splice($planned, $i, 1, [
                ['duration' => $before] + $activity,
                ['duration' => $activity['duration'] - $before, 'label' => 'anytime', 'window' => null] + $activity,
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

        // A major attraction deserves more time than a quick stop.
        $major = $this->priorityScore($destination) >= self::PRIORITY_POINTS['popular'] ? 30 : 0;

        return (self::MIN_VISIT[$this->experienceKind($destination)] ?? 60) + $adjust + $major;
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
        $wear = $this->wearList($destination->activities->pluck('what_to_wear')->all());
        if ($wear !== []) {
            $tips[] = 'What to wear: '.ucfirst($this->naturalList($wear)).'.';
        }
        $mentionsShoes = collect($wear)->contains(fn ($item) => str_contains($item, 'shoe'));
        $category = mb_strtolower((string) $destination->category?->name);
        if ($kind === 'water') {
            $tips[] = $this->once('Bring swimwear, a towel, a change of clothes, sunscreen and drinking water; the plan leaves '.self::FRESHEN_UP_MINUTES.' minutes to rinse off before moving on.');
        } elseif ($kind === 'highland') {
            $tips[] = $this->once($mentionsShoes ? 'Bring drinking water for the walk.' : 'Wear comfortable walking shoes and bring drinking water for the walk.');
        } elseif ($category === 'church') {
            $tips[] = $this->once('Dress modestly and keep quiet if a Mass is going on.');
        } elseif ($category === 'historical' && $destination->setting !== 'outdoor') {
            $tips[] = $this->once('Respect roped-off areas and avoid touching old structures and exhibits.');
        } elseif ($destination->activities->contains('type', 'outdoor') || $destination->setting === 'outdoor') {
            $tips[] = $this->once('Bring a hat, sunscreen and drinking water: most of the visit is in the open.');
        }
        if ($destination->dining_details) {
            $tips[] = $this->once('Food: '.rtrim(Str::limit(trim($destination->dining_details), 140), '.').'.');
        }
        // Activities that depend on the sea, the weather or the season: never promised.
        $activityNames = $destination->activities->pluck('name')->implode(' ').' '.$destination->name;
        if ((int) $destination->category_id === 8 || preg_match('/island.?hopping|sland hopping|boat|banca|bangka/i', $activityNames)) {
            $tips[] = 'Confirm the boat and the weather before you go (check Coast Guard or PAGASA advisories; trips are cancelled in rough seas), and bring a waterproof bag for your phone and valuables.';
        }
        if (preg_match('/whale shark|butanding/i', $activityNames)) {
            $tips[] = 'Whale shark sightings depend on the season and conditions and are never guaranteed; confirm with the local tourism office or operator before you go.';
        }
        if (preg_match('/surf/i', $activityNames)) {
            $tips[] = 'Surfing conditions depend on the season and the swell; ask a local surf school before you book a lesson.';
        }
        if (preg_match('/firefly/i', $activityNames)) {
            $tips[] = 'Firefly watching depends on the weather and the night; it is best on dark, dry evenings.';
        }
        if (trim((string) $destination->operating_hours) === '') {
            $tips[] = "Hours aren't listed: confirm before you go.";
        } elseif (in_array('operating_hours', $destination->estimated_fields ?? [], true)) {
            $tips[] = 'Hours shown are typical, not confirmed: check before you go.';
        }
        $tips = array_values(array_filter($tips));

        return $tips === [] ? null : implode(' ', $tips);
    }

    /**
     * One list of things to wear from every activity's "what to wear": split into single items
     * ("Trekking shoes, breathable clothes and a cap; A light jacket and sturdy shoes" gives five),
     * each named once, in lower case except as written for proper nouns.
     *
     * @return array<int, string>
     */
    private function wearList(array $texts): array
    {
        $items = [];
        foreach ($texts as $text) {
            foreach (preg_split('/\s*(?:[;,.]|\band\b)\s*/i', (string) $text) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $item = lcfirst($part);
                $key = preg_replace('/^(a|an|the)\s+/', '', strtolower($item));
                $items[$key] ??= $item;
            }
        }

        return array_values($items);
    }

    /** "a, b and c". */
    private function naturalList(array $items): string
    {
        return count($items) < 2 ? (string) ($items[0] ?? '') : implode(', ', array_slice($items, 0, -1)).' and '.end($items);
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

        if ($this->transport === 'public_transport') {
            // Long trips late in the day: the last jeepneys and vans between towns leave early.
            if ($this->kmBetween($from, $to) > 15 && $start - $minutes >= 960 && ! str_contains((string) $notes, 'last trip')) {
                $notes = trim(($notes ?? '').' Jeepneys and vans between towns thin out by early evening: confirm the last trip.');
            }
            $how = $this->publicTransportLeg($from, $to);
        } else {
            // "Google Maps" marks a measured driving time (the stop's directions say so).
            $how = $this->roadLeg($from, $to) ? 'by road, per Google Maps' : 'by road';
        }

        return trim(($notes ?? '')." Leave {$from->name} by {$this->formatMinutes($start - $minutes)} (about {$minutes} min {$how}).");
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
     * What breaks the planning rules in a built trip (empty when nothing does); see dayProblems().
     *
     * @return array<int, string>
     */
    public function scheduleProblems(array $items, Collection $destinations, array $preferences): array
    {
        $problems = $this->dayProblems($items, $destinations, $preferences);

        return $problems === [] ? [] : array_merge(...array_values($problems));
    }

    /**
     * The scheduling rules every generated trip is checked against before it's saved, by day
     * (empty when nothing breaks them). Deterministic, so they hold whatever the AI suggested:
     * - every day has breakfast (6:00 – 8:00 AM), lunch (11:30 AM – 12:30 PM, never later) and
     *   dinner (6:00 – 7:30 PM), once each, unless the tourist isn't around then (a late arrival,
     *   an early departure) or there is no food place at all (the plan then says what to do);
     * - nothing else happens during lunch: no stop overlaps it, and a visit that breaks for it
     *   has no activity in the break (the trip to and from the food place included);
     * - stops are in time order without overlaps, each starting after the one before it ends
     *   plus the travel between them, and activities fit inside their stop;
     * - the trip has the requested number of days, stays in the selected towns, never repeats a
     *   destination or visits one outside its opening hours, and public-transport trips carry
     *   public-transport travel times.
     *
     * @return array<int, array<int, string>>
     */
    private function dayProblems(array $items, Collection $destinations, array $preferences): array
    {
        $startDate = ! empty($preferences['start_date']) ? Carbon::parse($preferences['start_date']) : null;
        $numDays = (int) ($preferences['num_days'] ?? collect($items)->max('day_number') ?? 1);
        $towns = array_map('intval', $preferences['municipality_ids'] ?? []);
        $byId = $destinations->keyBy(fn ($d) => (int) $d->id);
        $arrivalGiven = $this->arrivalMinutes($preferences) !== null;
        $arrive = $this->arrivalMinutes($preferences) ?? self::DEFAULT_ARRIVAL;
        $depart = $this->departureMinutes($preferences);
        $windows = [
            'breakfast' => [360, self::BREAKFAST_END],
            'lunch' => [self::LUNCH_START, self::LUNCH_END],
            'dinner' => [self::DINNER_START, self::LATEST_DINNER_START + self::MEAL_MINUTES],
        ];
        $problems = [];
        $seen = [];

        foreach (collect($items)->groupBy('day_number') as $day => $rows) {
            $day = (int) $day;
            $add = function (string $problem) use (&$problems, $day) {
                $problems[$day][] = "Day {$day}: {$problem}";
            };
            if ($day < 1 || $day > $numDays) {
                $add("the trip only has {$numDays} day(s).");
            }
            $date = $startDate?->copy()->addDays($day - 1);
            // When the tourist is around: from the arrival on Day 1, until the departure on the last day.
            $dayStart = $day === 1 ? $arrive : 0;
            $dayEnd = $day === $numDays && $depart !== null ? $depart : 1440;
            $prevEnd = null;
            $visit = null;
            $meals = [];
            $lunchBreaks = [];
            $busy = [];

            foreach ($rows->sortBy('sequence') as $row) {
                $label = "stop {$row['sequence']}";
                $slot = $this->slotMinutes($row['time_slot'] ?? null);
                if (! $slot) {
                    $add("{$label} has no readable time.");

                    continue;
                }
                [$from, $to] = $slot;
                $notes = (string) ($row['notes'] ?? '');
                $type = $row['item_type'] ?? null;
                $travel = (int) ($row['travel_time_from_prev'] ?? 0);
                if (preg_match('/^(Breakfast|Lunch|Dinner)\b/', $notes, $meal)) {
                    $meals[] = [strtolower($meal[1]), $from, $to];
                }

                // A lunch or dinner break belongs to the visit right before it, with the trip to and
                // from the food place inside that visit; the visit's end stays the time to beat.
                if (preg_match('/^(Lunch|Dinner) break\b/', $notes, $break)) {
                    if (! $visit || $from - $travel < $visit[0] || $to + $travel > $visit[1]) {
                        $add("{$label}: the ".strtolower($break[1]).' break falls outside its visit.');
                    }
                    if ($break[1] === 'Lunch') {
                        $lunchBreaks[] = [$from - $travel, $to + $travel];
                    }

                    continue;
                }
                if ($prevEnd !== null && $from < $prevEnd + $travel) {
                    $add("{$label} starts before the previous stop ends plus the travel time.");
                }
                if ($to > $dayEnd) {
                    $add("{$label} ends after the departure at {$this->formatMinutes($dayEnd)}.");
                }
                if ($day === 1 && $arrivalGiven && $from < $arrive) {
                    $add("{$label} starts before the arrival at {$this->formatMinutes($arrive)}.");
                }
                if ($this->transport === 'public_transport' && $travel > 0 && ! preg_match('/min (on foot|by tricycle|by jeepney)/', $notes)) {
                    $add("{$label}: the travel time isn't a public-transport time.");
                }
                foreach ((array) ($row['activities'] ?? []) as $activity) {
                    $span = $this->slotMinutes($activity['time'] ?? null);
                    if (! $span || $span[0] < $from || $span[1] > $to) {
                        $add("{$label}: the activity \"".($activity['name'] ?? '')."\" falls outside the stop's time.");

                        continue;
                    }
                    if (preg_match('/\b(breakfast|lunch|dinner)\b/i', $activity['name'] ?? '', $meal)) {
                        $meals[] = [strtolower($meal[1]), $span[0], $span[1]];
                    } else {
                        $busy[] = [$span[0], $span[1], $label.' ("'.($activity['name'] ?? '').'")'];
                    }
                }
                if ($type === ItineraryItem::TYPE_DESTINATION) {
                    $id = (int) $row['destination_id'];
                    if (isset($seen[$id])) {
                        $add("{$label} repeats a destination already in the trip.");
                    }
                    $seen[$id] = true;
                    $place = $byId[$id] ?? null;
                    if ($place && ! $this->isOpen($place, $from, $to, $date)) {
                        $add("{$label} is outside {$place->name}'s opening hours.");
                    }
                    if ($place && $towns !== [] && ! in_array((int) $place->municipality_id, $towns, true)) {
                        $add("{$label} ({$place->name}) is outside the selected municipalities.");
                    }
                    $visit = [$from, $to];
                } else {
                    $visit = null;
                    if (! preg_match('/^(Breakfast|Lunch|Dinner)\b/', $notes)) {
                        $busy[] = [$from, $to, $label];
                    }
                }
                $prevEnd = $to;
            }

            foreach ($windows as $meal => [$earliest, $latest]) {
                $found = array_values(array_filter($meals, fn ($m) => $m[0] === $meal));
                // Late on Day 1 a dinner can only be when the tourist gets there.
                $latest = $meal === 'dinner' && $day === 1 && $arrive + self::ARRIVAL_MINUTES > self::LATEST_DINNER_START ? self::LATEST_DINNER + self::MEAL_MINUTES : $latest;
                foreach ($found as [, $from, $to]) {
                    if ($from < $earliest || $to > $latest) {
                        $add(ucfirst($meal)." at {$this->timeRange($from, $to - $from)} is outside {$this->formatMinutes($earliest)} – {$this->formatMinutes($latest)}.");
                    }
                }
                if (count($found) > 1) {
                    $add("{$meal} is planned more than once.");
                }
                $around = match ($meal) {
                    'breakfast' => $dayStart + self::BREAKFAST_MINUTES <= self::BREAKFAST_END && $dayEnd >= self::BREAKFAST_END,
                    'lunch' => $dayStart + 15 <= self::LUNCH_START && $dayEnd >= self::LUNCH_END,
                    'dinner' => $dayStart <= self::LATEST_DINNER && $dayEnd >= self::LATEST_DINNER_START + self::MEAL_MINUTES,
                };
                if ($found === [] && $around && ! $this->mealExcused($rows, $day, $meal)) {
                    $add("there's no {$meal} in the plan.");
                }
            }

            // Nothing but lunch between 11:30 and 12:30 (including the trip to and from a lunch
            // break's food place).
            foreach ($busy as [$from, $to, $what]) {
                if ($from < self::LUNCH_END && $to > self::LUNCH_START && collect($meals)->contains(fn ($m) => $m[0] === 'lunch')) {
                    $add("{$what} overlaps lunch (11:30 AM – 12:30 PM).");
                }
                foreach ($lunchBreaks as [$breakFrom, $breakTo]) {
                    if ($from < $breakTo && $to > $breakFrom) {
                        $add("{$what} overlaps the lunch break.");
                    }
                }
            }
        }

        return $problems;
    }

    /**
     * The towns to visit when the tourist chose none: the province's best places for their
     * interests decide. Each town is rated by its two highest-ranked matching attractions (the
     * tourism office's classification, featured status, visitor ratings; see priorityScore()),
     * plus a little for having several; the best town comes first, then each next one is the
     * best remaining after a cost for its distance from the towns already chosen (higher by
     * public transport), so the trip doesn't zigzag across the province. One town per day (at
     * most six), never more towns than have something to offer, in route order. A stay the
     * tourist picked puts its town in the trip. Food- or shopping-only trips go to the towns
     * with the most listed restaurants and souvenir shops.
     *
     * @return array{towns: array<int, int>, note: string, highlights: array<int, string>}
     */
    private function chooseTowns(array $preferences): array
    {
        $days = max(1, (int) $preferences['num_days']);
        $categoryIds = array_values(array_unique(array_merge(...array_map(fn ($i) => self::INTEREST_CATEGORIES[$i] ?? [], array_values($preferences['interests'] ?? [])))));
        $picked = ! empty($preferences['accommodation_id']) ? Accommodation::find($preferences['accommodation_id']) : null;

        if ($categoryIds === []) {
            $counts = FoodBeverage::approved()->selectRaw('municipality_id, count(*) as n')->groupBy('municipality_id')->pluck('n', 'municipality_id');
            foreach ($this->souvenirShopQuery()->selectRaw('municipality_id, count(*) as n')->groupBy('municipality_id')->pluck('n', 'municipality_id') as $town => $n) {
                $counts[$town] = ($counts[$town] ?? 0) + $n;
            }
            $towns = collect($counts)->sortDesc()->keys()->map(fn ($t) => (int) $t)->take(min($days, 2))->values()->all();
            if ($picked && ! in_array((int) $picked->municipality_id, $towns, true)) {
                array_unshift($towns, (int) $picked->municipality_id);
            }
            $towns = array_slice($towns, 0, max(1, min($days, 2)));
            if ($towns === []) {
                throw new ItineraryNotPossibleException('There are no listed restaurants or souvenir shops in Sorsogon yet. Please pick a sightseeing interest.');
            }
            $names = Municipality::whereIn('id', $towns)->pluck('name', 'id');

            return ['towns' => $towns, 'highlights' => [], 'note' => 'You let SOSOGON choose the municipalities: '.$this->naturalList(array_map(fn ($t) => $names[$t] ?? '', $towns)).' have the most listed places to eat and shop.'];
        }

        $places = Destination::published()->whereIn('category_id', $categoryIds)->whereNotNull('latitude')->whereNotNull('longitude')
            ->get(['id', 'name', 'municipality_id', 'latitude', 'longitude', 'is_featured', 'tourism_priority', 'average_rating', 'total_reviews', 'view_count']);
        if ($places->isEmpty()) {
            throw new ItineraryNotPossibleException('No published destinations in Sorsogon match those interests yet. Please pick other interests.');
        }
        $byTown = $places->groupBy('municipality_id');
        $worth = $byTown->map(function ($group) {
            $scores = $group->map(fn ($d) => $this->priorityScore($d))->sortDesc()->values();

            return $scores->take(2)->sum() + min(3, $group->count()) * 5;
        });
        $centers = $byTown->map(fn ($group) => $this->centroid($group));
        $perKm = ($preferences['transport_preference'] ?? null) === 'public_transport' ? self::TOWN_GAP_KM_PUBLIC : self::TOWN_GAP_KM_CAR;
        $gap = fn ($town, array $chosen) => $chosen === [] ? 0 : collect($chosen)->min(fn ($c) => $this->haversineDistance($centers[$town][0], $centers[$town][1], $centers[$c][0], $centers[$c][1]));

        $want = min($days, 6, $byTown->count());
        $chosen = $picked && isset($byTown[(int) $picked->municipality_id]) ? [(int) $picked->municipality_id] : [];
        while (count($chosen) < $want) {
            $next = $worth->keys()->map(fn ($t) => (int) $t)
                ->reject(fn ($t) => in_array($t, $chosen, true))
                ->sortByDesc(fn ($t) => $worth[$t] - $gap($t, $chosen) * $perKm)
                ->first();
            if ($next === null) {
                break;
            }
            $chosen[] = $next;
        }

        // Route order: start from the end of the chosen line, then always the nearest next town.
        $start = collect($chosen)->sortByDesc(fn ($t) => collect($chosen)->max(fn ($o) => $this->haversineDistance($centers[$t][0], $centers[$t][1], $centers[$o][0], $centers[$o][1])))->first();
        $route = [$start];
        while (count($route) < count($chosen)) {
            $last = end($route);
            $route[] = collect($chosen)->reject(fn ($t) => in_array($t, $route, true))
                ->sortBy(fn ($t) => $this->haversineDistance($centers[$t][0], $centers[$t][1], $centers[$last][0], $centers[$last][1]))->first();
        }

        $names = Municipality::whereIn('id', $route)->pluck('name', 'id');
        $highlights = collect($route)->map(fn ($t) => $byTown[$t]->sortByDesc(fn ($d) => $this->priorityScore($d))->first()?->name)->filter()->values()->all();

        return [
            'towns' => array_values($route),
            'highlights' => $highlights,
            'note' => 'You let SOSOGON choose the municipalities, so this trip covers '.$this->naturalList(array_map(fn ($t) => $names[$t] ?? '', $route))
                .' for some of the province\'s best-known places: '.$this->naturalList($highlights).'.',
        ];
    }

    /**
     * With more selected towns than days, which towns share a day: the two closest groups of
     * towns (by their attractions' locations) are joined, again and again, until there are no
     * more groups than days. Only towns whose attractions are within SHARED_DAY_KM of each
     * other are joined; otherwise the trip can't be planned and the tourist is told why.
     *
     * @return array<int, int> town id => the first town of its group (only for joined towns)
     */
    private function shareDays(array $towns, int $days): array
    {
        if (count($towns) <= $days) {
            return [];
        }
        $centers = [];
        foreach ($towns as $town) {
            $centers[$town] = $this->centroid(Destination::published()->where('municipality_id', $town)
                ->whereNotNull('latitude')->whereNotNull('longitude')->get(['latitude', 'longitude']))
                ?? (($m = Municipality::find($town)) && $m->latitude !== null ? [(float) $m->latitude, (float) $m->longitude] : null);
        }
        $groups = array_map(fn ($t) => [$t], $towns);
        $gap = function (array $a, array $b) use ($centers): float {
            $best = INF;
            foreach ($a as $x) {
                foreach ($b as $y) {
                    if ($centers[$x] && $centers[$y]) {
                        $best = min($best, $this->haversineDistance($centers[$x][0], $centers[$x][1], $centers[$y][0], $centers[$y][1]));
                    }
                }
            }

            return $best;
        };
        while (count($groups) > $days) {
            $pair = null;
            foreach ($groups as $i => $a) {
                foreach ($groups as $j => $b) {
                    if ($j > $i && count($a) + count($b) <= 2 && ($pair === null || $gap($a, $b) < $pair[2])) {
                        $pair = [$i, $j, $gap($a, $b)];
                    }
                }
            }
            if ($pair === null || $pair[2] > self::SHARED_DAY_KM) {
                $names = Municipality::whereIn('id', $towns)->pluck('name')->implode(', ');
                throw new ItineraryNotPossibleException("{$names} are too far apart to share days in a {$days}-day trip. Please add a day or choose municipalities closer to each other.");
            }
            $groups[$pair[0]] = array_merge($groups[$pair[0]], $groups[$pair[1]]);
            unset($groups[$pair[1]]);
        }
        $map = [];
        foreach ($groups as $group) {
            foreach (count($group) > 1 ? $group : [] as $town) {
                $map[$town] = $group[0];
            }
        }

        return $map;
    }

    /** A listing in a town that shares its days is planned as part of that town's area. */
    private function planningTown(Model $place): void
    {
        if (isset($this->townOf[(int) $place->municipality_id])) {
            $place->municipality_id = $this->townOf[(int) $place->municipality_id];
        }
    }

    /**
     * How much a destination is worth a trip, from what the tourism office and visitors say:
     * the office's classification first, then being featured, then a good visitor rating (with
     * enough reviews to mean something) and page views. Never a guess: an unclassified place
     * without reviews scores 0.
     */
    private function priorityScore(Destination $destination): int
    {
        $score = self::PRIORITY_POINTS[$destination->tourism_priority] ?? 0;
        $score += $destination->is_featured ? 40 : 0;
        if ((int) $destination->total_reviews >= 5) {
            $score += (int) round(max(0, (float) $destination->average_rating - 3.5) * 20);
        }

        return $score + min(15, intdiv((int) $destination->view_count, 10));
    }

    /** What the tourism office says about a destination ("Must-visit", "Featured"), for the AI and the stop's reason. */
    private function priorityLabel(Destination $destination): ?string
    {
        $labels = array_filter([
            self::PRIORITY_LABELS[$destination->tourism_priority] ?? null,
            $destination->is_featured ? 'featured by the tourism office' : null,
        ]);

        return $labels === [] ? null : ucfirst(implode(', ', $labels));
    }

    /**
     * Each day's title and main highlight: the AI's title when its highlight is still in the
     * day, else the day's highest-ranked attraction with the others it's combined with.
     *
     * @param  array<int, string>  $aiTitles  highlight destination id => title
     * @return array<int, array{title: string, highlight_id: int}>
     */
    private function dayPlans(array $items, Collection $destinations, array $aiTitles): array
    {
        $byId = $destinations->keyBy(fn ($d) => (int) $d->id);
        $plans = [];
        foreach (collect($items)->where('item_type', ItineraryItem::TYPE_DESTINATION)->sortBy(['day_number', 'sequence'])->groupBy('day_number') as $day => $visits) {
            $ids = $visits->pluck('destination_id')->map(fn ($id) => (int) $id)->filter(fn ($id) => isset($byId[$id]))->values();
            if ($ids->isEmpty()) {
                continue;
            }
            $titled = $ids->first(fn ($id) => isset($aiTitles[$id]));
            if ($titled !== null) {
                $plans[(int) $day] = ['title' => $aiTitles[$titled], 'highlight_id' => $titled];

                continue;
            }
            $highlight = $ids->sortByDesc(fn ($id) => $this->priorityScore($byId[$id]))->first();
            $others = $ids->reject(fn ($id) => $id === $highlight)->take(2)->map(fn ($id) => $byId[$id]->name)->all();
            $plans[(int) $day] = [
                'title' => $this->naturalList([$byId[$highlight]->name, ...$others]),
                'highlight_id' => $highlight,
            ];
        }

        return $plans;
    }

    /** $text the first time it's said in this trip, else null (the same reminder isn't repeated on every stop). */
    private function once(?string $text, ?string $key = null): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }
        $key ??= $text;
        if (isset($this->said[$key])) {
            return null;
        }
        $this->said[$key] = true;

        return $text;
    }

    /**
     * The days without the $excluded destinations, topped up again (by the usual day rules)
     * from the other eligible places, so a stop that didn't fit gives its time to one that does.
     */
    private function refill(array $days, array $excluded, array $plan, Collection $destinations, array $preferences): array
    {
        $pool = $destinations->reject(fn ($d) => in_array((int) $d->id, $excluded, true))->values();
        $gone = $destinations->whereIn('id', $excluded)->pluck('name')->all();
        foreach ($days as $n => $day) {
            $days[$n]['destination_ids'] = array_values(array_diff($day['destination_ids'], $excluded));
            // A planner-added stop explained by a place now gone is explained again by the stop before it.
            foreach ($days[$n]['destination_ids'] as $k => $id) {
                $reason = (string) ($day['reasons'][$id] ?? '');
                if (in_array($id, $day['added'] ?? [], true) && collect($gone)->contains(fn ($name) => str_contains($reason, $name))) {
                    $before = $k > 0 ? $pool->firstWhere('id', $days[$n]['destination_ids'][$k - 1]) : null;
                    $days[$n]['reasons'][$id] = $this->addedReason($pool->firstWhere('id', $id), $before);
                }
            }
        }

        return $this->skipResortsAtResortStay($this->shapeDays($days, $pool, $preferences), $plan, $pool, $preferences);
    }

    /**
     * Whether a day goes without $meal on purpose: the planner said so while building it, or (for
     * a saved trip) the day's notes tell the tourist what to do instead — bring it, eat on the
     * way or before setting off.
     */
    private function mealExcused(Collection $rows, int $day, string $meal): bool
    {
        if (isset($this->mealExcuses[$day][$meal])) {
            return true;
        }
        $hint = '(bring|packed|eat on the way|on your way|on the way|before you set off|before heading out|before you arrive|about food)';

        return $rows->contains(fn ($row) => preg_match("/\\b{$meal}\\b[^.]*{$hint}|{$hint}[^.]*\\b{$meal}\\b/i", (string) ($row['preference_note'] ?? '')) === 1);
    }

    /**
     * The destination to take out of a day that breaks a scheduling rule: one the planner added
     * rather than the AI or the tourist picked, else the day's last stop that the tourist didn't
     * ask for, else its last stop. Null when the day has no destination left.
     */
    private function stopToDrop(array $days, array $items, int $day, array $preferences): ?int
    {
        $ids = collect($items)->where('day_number', $day)->where('item_type', ItineraryItem::TYPE_DESTINATION)
            ->sortBy('sequence')->pluck('destination_id')->map(fn ($id) => (int) $id)->values();
        if ($ids->isEmpty()) {
            return null;
        }
        $added = collect($days)->flatMap(fn ($d) => $d['added'] ?? [])->map(fn ($id) => (int) $id)->all();
        $asked = $this->preferredIds($preferences);

        return $ids->reverse()->first(fn ($id) => in_array($id, $added, true) && ! in_array($id, $asked, true))
            ?? $ids->reverse()->first(fn ($id) => ! in_array($id, $asked, true))
            ?? $ids->last();
    }

    /** Says on each day which stops were left out to keep its meals and travel times realistic. */
    private function noteDroppedStops(array $items, array $dropped): array
    {
        foreach ($dropped as $day => $names) {
            $first = collect($items)->search(fn ($i) => $i['day_number'] === $day);
            if ($first === false) {
                continue;
            }
            $items[$first]['preference_note'] = $this->joinNotes(
                'Left out to keep today comfortable and lunch at 11:30 AM: '.$this->naturalList(array_unique($names)).'. You can add '.(count(array_unique($names)) === 1 ? 'it' : 'them').' to another day when editing.',
                $items[$first]['preference_note'] ?? null,
            );
        }

        return $items;
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
        $rank = ['prep' => -1, 'dawn' => 0, 'morning' => 1, 'midday' => 2, 'anytime' => 2, 'afternoon' => 3, 'sunset' => 4, 'evening' => 5];
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
        // Registering doesn't count as doing something there.
        $remaining = count(array_filter($planned, fn ($a) => $a['label'] !== 'prep'));
        $done = 0;
        foreach ($order as $i) {
            $activity = $planned[$i];
            if ($activity['label'] !== 'prep') {
                $remaining--;
            }
            if (isset($activity['at'])) {
                $rows[] = [$activity['at'][0], $activity['at'][1], $activity['name'], $activity['description']];

                continue;
            }

            $length = $activity['duration'] ?? 60;
            $start = $cursor;
            // An activity whose time has already passed (sunrise viewing at 11 AM, morning swimming
            // at night) is left out, unless it's the visit's only one: then it stays, noting its best time.
            $timed = $activity['window'] && in_array($activity['label'], ['dawn', 'morning', 'midday', 'afternoon', 'sunset', 'evening'], true);
            $pinned = in_array($activity['label'], ['dawn', 'sunset', 'evening'], true);
            if ($timed && ($pinned ? $cursor + $length > $activity['window'][1] : $cursor >= $activity['window'][1])) {
                if ($done > 0 || $remaining > 0) {
                    continue;
                }
                $activity = $this->outsideWindow($activity);
            } elseif ($pinned && $activity['window']) {
                $start = max($cursor, $activity['window'][0]);
            }
            $start = $this->afterBusy($busy, $start, $length);

            $rows[] = [$start, $start + $length, $activity['name'], $activity['description']];
            $busy[] = [$start, $start + $length];
            $cursor = $start + $length;
            $done += $activity['label'] !== 'prep' ? 1 : 0;
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
        return $day['reasons'][$destination->id] ?? $this->addedReason($destination, null);
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

        // The current wording, and the one saved itineraries from before it use.
        $pattern = '/\s*Leave .+? by \d{1,2}:\d{2} [AP]M(?:: about \d+ min \([^)]*\)| \(about \d+ min[^)]*\))\.$/u';
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
            'resort' => ['Resort', 'Beach Resort'],
            'hotel' => ['Hotel'],
            'homestay' => ['Homestay'],
            'budget' => ['Inn', 'Homestay', 'Guesthouse'],
            default => [],
        };

        return $types ? fn ($a) => array_intersect($types, array_merge([$a->type], $a->classifications ?? [])) !== [] : null;
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
            // A café or snack shop isn't a proper lunch or dinner when a restaurant is near too.
            - (in_array($meal, ['lunch', 'dinner'], true) && $this->isSnackPlace($place) ? 2 : 0)
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
            $notes[] = "No {$this->cuisineLabel($preferences)} places are listed in {$this->townNames($preferences)} yet, so meals are at the closest listed restaurants.";
        }
        $missing = ! $cuisineMatched && $cuisineListed;
        if ($missing || $pricier) {
            $wanted = ($missing ? $this->cuisineLabel($preferences).' ' : '').'place'.($pricier ? ' in your '.ucfirst($budget).' range' : '');
            $notes[] = $this->once("The closest {$wanted} near {$near->name} is already in your trip, so this is the nearest listed alternative ({$this->describeListing($place->cuisine_type ? "{$place->cuisine_type} spot" : 'dining spot', $place->price_range)}).", "food-miss-{$wanted}");
        }

        return $this->joinNotes(...$notes);
    }

    /** Why a meal is at the stay: no unused verified food place was open where it was looked for ($searched: "near X or near Y"). */
    /**
     * What to know about a meal at the stay: nothing for breakfast (the usual place for it);
     * for lunch or dinner, once per stay, that it's there for want of a restaurant nearby and
     * what the stay says about its food.
     */
    private function mealAtStayNote(string $meal, string $searched, $stay): ?string
    {
        // What the stay says about its food, the first time a meal is there.
        $dining = $this->once(trim((string) $stay->dining_details) ?: null, "stay-dining-{$stay->id}");
        if ($meal === 'breakfast') {
            return $dining;
        }

        return $this->joinNotes(
            $this->once("No restaurant is listed {$searched}, so {$meal} is at {$stay->name}.", "stay-meal-{$stay->id}"),
            $dining,
            $this->once('Ask the front desk what they serve.'),
        );
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
        $ids = $preferences['all_town_ids'] ?? $preferences['municipality_ids'] ?? $this->townIds($preferences);
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
        // By public transport: slower, plus the wait for a tricycle or a jeepney/bus/van; a place
        // a few hundred metres away is a walk.
        if ($this->transport === 'public_transport') {
            $km = $this->kmBetween($from, $to);
            $minutes = match (true) {
                $km < 0.5 => $km / 4 * 60,
                $km <= self::TRICYCLE_KM => $minutes * self::PUBLIC_TRANSPORT_FACTOR + self::TRICYCLE_WAIT,
                default => $minutes * self::PUBLIC_TRANSPORT_FACTOR + self::JEEPNEY_WAIT,
            };
        }

        return (int) (ceil($minutes / 5) * 5);
    }

    /** How a leg is travelled by public transport, for the travel note. */
    private function publicTransportLeg($from, $to): string
    {
        $km = $this->kmBetween($from, $to);

        return match (true) {
            $km < 0.5 => 'on foot',
            $km <= self::TRICYCLE_KM => 'by tricycle, with the wait',
            default => 'by jeepney or van plus a tricycle, with waits and transfers',
        };
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
        $options = $stay->rooms()->approved()->get();
        $units = $options->reject(fn ($r) => $r->isEntireProperty());
        $travelers = $this->travelers($preferences);
        $priced = $units->filter(fn ($r) => (float) $r->price > 0);

        if ($priced->isEmpty()) {
            // Nothing to compare by price: suggest only what is known to hold the group.
            $fit = $units->filter(fn ($r) => $r->max_guests && $r->max_guests * max(1, (int) $r->unit_count) >= $travelers)->sortBy('max_guests')->first();
            if ($fit) {
                return [collect([$fit]), $fit->max_guests * max(1, (int) $fit->unit_count)];
            }
            $whole = $units->isEmpty() ? $options->first(fn ($r) => $r->isEntireProperty() && (! $r->max_guests || $r->max_guests >= $travelers)) : null;

            return $whole ? [collect([$whole]), $whole->max_guests ?? $travelers] : [collect(), 0];
        }

        $categories = $priced->groupBy(fn ($r) => $r->category ?: 'Room')
            ->sortBy(fn ($group) => $group->min(fn ($r) => (float) $r->price))
            ->values();
        $indexes = range(0, $categories->count() - 1);
        $middle = intdiv($categories->count() - 1, 2);
        $order = match ($this->budgetLevel($preferences)) {
            'budget' => $indexes,
            'luxury' => array_reverse($indexes),
            default => collect($indexes)->sortBy(fn ($i) => [abs($i - $middle), $i])->values()->all(),
        };

        $wanted = mb_strtolower((string) ($preferences['additional_preferences'] ?? ''));
        // Options with an amenity the tourist named come first (e.g. "a room with a kitchen").
        $matches = fn ($r) => $wanted === '' ? 0 : collect($r->room_features ?? [])->filter(fn ($f) => $f !== '' && str_contains($wanted, mb_strtolower((string) $f)))->count();
        $ranked = collect($order)
            ->flatMap(fn ($index) => $categories[$index]->sortBy(fn ($r) => (float) $r->price)->values())
            ->sortByDesc($matches)
            ->values();

        $chosen = collect();
        $sleeps = 0;
        foreach ($ranked as $room) {
            if ($sleeps >= $travelers) {
                break;
            }
            $chosen->push($room);
            $sleeps += ($this->capacity($room) ?? 2) * max(1, (int) $room->unit_count);
        }

        return [$chosen, $sleeps];
    }

    /**
     * The rooms the trip books at a stay, and how many guests they sleep: the room the tourist
     * picked (enough of it for the group), else the recommended ones.
     *
     * @return array{0: Collection, 1: int}
     */
    private function stayRooms(Accommodation $stay, array $preferences): array
    {
        $picked = ! empty($preferences['room_id']) && (int) ($preferences['accommodation_id'] ?? 0) === (int) $stay->id
            ? AccommodationRoom::approved()->where('accommodation_id', $stay->id)->find($preferences['room_id'])
            : null;

        return $picked ? $this->roomsForGroup($picked, $this->travelers($preferences)) : $this->recommendRooms($stay, $preferences);
    }

    /**
     * What breakfast at a stay means for this trip, from what the stay itself lists:
     * - 'free': every room the trip books says breakfast is included, or (when the rooms don't
     *   say) the stay lists free/complimentary breakfast as an amenity or in its dining details;
     * - 'paid': the stay serves breakfast (a restaurant, café or breakfast is listed) but it isn't
     *   included for these rooms, or isn't said to be;
     * - 'unknown': the stay says breakfast is available but to ask whether it's in the rate;
     * - 'none': nothing says the stay serves breakfast.
     * A room's own "breakfast included: no" wins over a property-wide "Free Breakfast" amenity:
     * not every room or rate includes it. An amenity called just "Breakfast" is not taken as free.
     *
     * @return array{kind: string, note: ?string}
     */
    private function breakfastAtStay(Accommodation $stay, array $preferences): array
    {
        $key = "{$stay->id}";
        if (isset($this->breakfastCache[$key])) {
            return $this->breakfastCache[$key];
        }
        [$rooms] = $this->stayRooms($stay, $preferences);
        $flags = $rooms->pluck('breakfast_included');
        $amenities = collect((array) ($stay->amenities ?? []))->map(fn ($a) => mb_strtolower(is_array($a) ? (string) ($a['name'] ?? '') : (string) $a));
        $dining = mb_strtolower((string) $stay->dining_details);
        $listedFree = $amenities->contains(fn ($a) => preg_match('/\b(free|complimentary)\b.*breakfast|breakfast.*\b(included|inclusive)\b/', $a))
            || (preg_match('/\b(free|complimentary)\b[^.]*breakfast|breakfast[^.]*\b(is|are)?\s*(included|free|complimentary)\b/', $dining) && ! str_contains($dining, 'whether'));
        $serves = $amenities->contains(fn ($a) => preg_match('/breakfast|restaurant|caf[eé]|coffee shop|dining/', $a))
            || preg_match('/breakfast|restaurant|caf[eé]|dining room/', $dining);
        $roomName = $rooms->count() === 1 ? $rooms->first()->name : 'rooms';

        $result = match (true) {
            $rooms->isNotEmpty() && $flags->every(fn ($f) => $f === true) => ['kind' => 'free', 'note' => "Breakfast is included with your {$roomName} at {$stay->name}."],
            // Strictly false: an option that doesn't say (null) isn't "not included".
            $flags->contains(fn ($f) => $f === false) => $serves
                ? ['kind' => 'paid', 'note' => "Breakfast isn't included with your {$roomName}; {$stay->name} serves it at extra cost (price not listed)."]
                : ['kind' => 'none', 'note' => null],
            $listedFree => ['kind' => 'free', 'note' => "{$stay->name} lists free breakfast for guests; confirm it's part of your booking."],
            str_contains($dining, 'whether') && str_contains($dining, 'breakfast') => ['kind' => 'unknown', 'note' => "Ask {$stay->name} whether breakfast is part of your rate."],
            (bool) $serves => ['kind' => 'paid', 'note' => "{$stay->name} serves breakfast at extra cost (price not listed)."],
            default => ['kind' => 'none', 'note' => null],
        };

        return $this->breakfastCache[$key] = $result;
    }

    /** Guests one unit of an option sleeps: as stated, else assumed from its category, else unknown. */
    private function capacity(AccommodationRoom $room): ?int
    {
        return $room->max_guests ?: (self::ROOM_CAPACITY[$room->category] ?? null);
    }

    /**
     * How many units of each picked option the group uses: a single unit each, or for a grouped
     * option as many of its units as the remaining travelers need.
     *
     * @return array<int, int> option id => units
     */
    public function roomQuantities(Collection $rooms, int $travelers): array
    {
        $left = $travelers;
        $quantities = [];
        foreach ($rooms as $room) {
            $sleeps = $this->capacity($room) ?? 2;
            $units = max(1, min(max(1, (int) $room->unit_count), (int) ceil(max($left, 1) / $sleeps)));
            $quantities[$room->id] = $units;
            $left -= $units * $sleeps;
        }

        return $quantities;
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
     * A stay's known cost: each picked option's listed price for the units the group uses, for each
     * of its $nights. Null when it can't be known (no option picked, or one without a price): the
     * tourist contacts the property for rates, and it is never counted as zero or guessed.
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
        if ($rooms->isEmpty() || $rooms->contains(fn ($r) => (float) $r->price <= 0)) {
            return [null, null];
        }

        $quantities = $this->roomQuantities($rooms, $this->travelers($preferences));
        $min = $rooms->sum(fn ($r) => (float) $r->price * $quantities[$r->id]) * $nights;
        $max = $rooms->sum(fn ($r) => (float) ($r->price_max ?: $r->price) * $quantities[$r->id]) * $nights;

        return [$min, $max];
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

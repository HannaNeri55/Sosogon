<?php

namespace App\Console\Commands;

use App\Contracts\ItineraryAI;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Municipality;
use App\Services\InterestMatcher;
use App\Services\ItineraryService;
use App\Services\PriorityDestinations;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Plans a fixed set of sample trips on this database and reports every planning problem a
 * tourist would notice, with a score per trip (100 = nothing found), so a change to the planner
 * can be measured before and after. Each trip is planned inside a transaction that is rolled
 * back: nothing is saved. Refuses to run in production.
 *
 * The AI's answers are saved per trip (storage/app/itinerary-audit/answers); --replay plans with
 * those instead of asking the AI again, so a rerun costs nothing and differs only by the code.
 */
class ItineraryAudit extends Command
{
    protected $signature = 'itinerary:audit
        {--replay : Plan with the saved AI answers instead of asking the AI}
        {--only= : Comma-separated trip keys to run}';

    protected $description = 'Plan sample trips (nothing saved) and report the planning problems found, with a score';

    private const WEIGHTS = ['critical' => 15, 'high' => 6, 'medium' => 3, 'low' => 1];

    private const ALL_INTERESTS = ['nature', 'beaches', 'culture', 'history', 'food', 'adventure', 'shopping', 'relaxation'];

    /** Reason words that name an activity: [pattern, what it means]. */
    private const ACTIVITY_WORDS = [
        '/\bkayak/i' => 'kayaking', '/\bpaddl/i' => 'paddling', '/\bsurf/i' => 'surfing', '/\bsnorkel/i' => 'snorkeling',
        '/\btrek|\btrail\b|\bhik(e|ing)\b/i' => 'trekking', '/\bcanopy/i' => 'the canopy walk', '/\bdiv(e|ing)\b/i' => 'diving',
        '/\bisland[- ]hopping/i' => 'island hopping', '/\bzip ?line/i' => 'the zipline',
    ];

    public function handle(PriorityDestinations $priorities): int
    {
        if (app()->environment('production')) {
            $this->error('The audit plans test trips; run it on a local or test database, never production.');

            return self::FAILURE;
        }

        $dir = storage_path('app/itinerary-audit');
        File::ensureDirectoryExists("{$dir}/answers");
        $only = array_filter(explode(',', (string) $this->option('only')));
        $results = [];

        foreach ($this->scenarios() as $key => $scenario) {
            if ($only !== [] && ! in_array($key, $only, true)) {
                continue;
            }
            $preferences = $this->preferences($scenario);
            if ($preferences === null) {
                $this->warn("{$key}: skipped (a town or place it names isn't in this database).");

                continue;
            }
            $answers = "{$dir}/answers/{$key}.json";
            if ($this->option('replay') && ! File::exists($answers)) {
                $this->warn("{$key}: skipped (no saved AI answer; run once without --replay).");

                continue;
            }

            $this->line("Planning {$key}…");
            $ai = $this->option('replay') ? $this->replayingAI(json_decode(File::get($answers), true)) : $this->recordingAI(app(ItineraryAI::class));
            app()->instance(ItineraryAI::class, $ai);
            app()->forgetInstance(ItineraryService::class);
            $service = app(ItineraryService::class);

            DB::beginTransaction();
            try {
                $itinerary = $service->generate(null, $preferences, hash('sha256', Str::random(40)));
                $itinerary->load(['items.destination.activities', 'items.destination.category', 'items.foodBeverage', 'items.retailShop', 'items.accommodation', 'stays.accommodation', 'rooms']);
                $problems = $this->problems($itinerary, $preferences, $service, $priorities);
                $results[$key] = ['scenario' => $scenario['label'], 'title' => $itinerary->title, 'problems' => $problems, 'score' => $this->score($problems), 'outline' => $this->outline($itinerary)];
            } catch (\Throwable $e) {
                $results[$key] = ['scenario' => $scenario['label'], 'title' => null, 'problems' => [['critical', 'failed', 'The trip could not be planned: '.Str::limit($e->getMessage(), 200)]], 'score' => 0, 'outline' => []];
            } finally {
                DB::rollBack();
            }
            if (! $this->option('replay') && $ai->answers !== []) {
                File::put($answers, json_encode($ai->answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            $this->line("  score {$results[$key]['score']}, ".count($results[$key]['problems']).' problems');
        }

        if ($results === []) {
            $this->warn('No trips were planned.');

            return self::FAILURE;
        }
        $report = $this->report($results);
        $path = "{$dir}/report-".now()->format('Ymd-His').($this->option('replay') ? '-replay' : '').'.md';
        File::put($path, $report);

        $this->newLine();
        $this->table(['Trip', 'Score', 'Critical', 'High', 'Medium', 'Low'], collect($results)->map(fn ($r, $key) => [
            $key, $r['score'], ...array_map(fn ($level) => collect($r['problems'])->where(0, $level)->count(), array_keys(self::WEIGHTS)),
        ])->values()->all());
        $this->table(['Problem', 'Count'], collect($results)->flatMap(fn ($r) => $r['problems'])->countBy(1)->sortDesc()->map(fn ($n, $code) => [$code, $n])->values()->all());
        $this->info('Average score: '.round(collect($results)->avg('score'), 1).' over '.count($results).' trips.');
        $this->info("Report: {$path}");

        return self::SUCCESS;
    }

    /**
     * The sample trips: towns and places by name (IDs differ between databases). Dates are the
     * same weekdays every run: the trip starts on the Sunday two to three weeks from now.
     */
    private function scenarios(): array
    {
        $all = self::ALL_INTERESTS;

        return [
            'pdf-4d-solo-luxury' => ['label' => 'The audited PDF: 4 days, solo, packed, luxury, seafood, hotel, car, every interest, 8 AM to 5 PM', 'days' => 4,
                'prefs' => ['interests' => $all, 'travelers' => 1, 'travel_pace' => 'packed', 'budget' => 'luxury', 'food_preference' => 'seafood', 'accommodation_type' => 'hotel', 'transport_preference' => 'private_car', 'arrival_time' => '08:00', 'departure_time' => '17:00']],
            'default-1d-beaches' => ['label' => '1 day, beaches, packed', 'days' => 1, 'prefs' => ['interests' => ['beaches'], 'travel_pace' => 'packed', 'transport_preference' => 'private_car', 'arrival_time' => '08:00', 'departure_time' => '18:00']],
            'default-2d-couple' => ['label' => '2 days, couple, nature and beaches', 'days' => 2, 'prefs' => ['interests' => ['nature', 'beaches'], 'travelers' => 2, 'transport_preference' => 'private_car']],
            'default-3d-family' => ['label' => '3 days, family of 4, relaxed, budget, Bicolano food, resort', 'days' => 3, 'prefs' => ['interests' => ['beaches', 'relaxation'], 'travelers' => 4, 'travel_pace' => 'relaxed', 'budget' => 'budget', 'food_preference' => 'filipino_bicolano', 'accommodation_type' => 'resort', 'transport_preference' => 'rented_vehicle']],
            'default-3d-early-exit' => ['label' => '3 days, leaving at 10 AM', 'days' => 3, 'prefs' => ['interests' => ['nature', 'culture'], 'transport_preference' => 'private_car', 'departure_time' => '10:00']],
            'default-5d-commute' => ['label' => '5 days, public transport, culture, history and nature, budget', 'days' => 5, 'prefs' => ['interests' => ['culture', 'history', 'nature'], 'budget' => 'budget', 'transport_preference' => 'public_transport']],
            'default-7d-everything' => ['label' => '7 days, every interest, car', 'days' => 7, 'prefs' => ['interests' => $all, 'transport_preference' => 'private_car']],
            'default-2d-no-stay' => ['label' => '2 days, no accommodation needed', 'days' => 2, 'prefs' => ['interests' => ['nature', 'adventure'], 'accommodation_needed' => false, 'transport_preference' => 'private_car']],
            'default-4d-seniors-veg' => ['label' => '4 days, 2 seniors, relaxed, vegetarian', 'days' => 4, 'prefs' => ['interests' => ['culture', 'relaxation', 'nature'], 'travelers' => 2, 'travel_pace' => 'relaxed', 'food_preference' => 'vegetarian', 'transport_preference' => 'private_car']],
            'city-1d-culture-food' => ['label' => 'Sorsogon City, 1 day, culture, history, food and shopping', 'days' => 1, 'towns' => ['Sorsogon City'], 'prefs' => ['interests' => ['culture', 'history', 'food', 'shopping'], 'arrival_time' => '09:00', 'departure_time' => '18:00', 'accommodation_needed' => false]],
            'city-1d-food-shopping' => ['label' => 'Sorsogon City, 1 day, food and shopping only', 'days' => 1, 'towns' => ['Sorsogon City'], 'prefs' => ['interests' => ['food', 'shopping'], 'accommodation_needed' => false]],
            'gubat-2d-surf' => ['label' => 'Gubat, 2 days, adventure and beaches, motorcycle, budget', 'days' => 2, 'towns' => ['Gubat'], 'prefs' => ['interests' => ['adventure', 'beaches'], 'budget' => 'budget', 'transport_preference' => 'motorcycle']],
            'gubat-2d-late-arrival' => ['label' => 'Gubat, 2 days, arriving at 3 PM', 'days' => 2, 'towns' => ['Gubat'], 'prefs' => ['interests' => ['beaches', 'food'], 'arrival_time' => '15:00']],
            'bulusan-2d-packed' => ['label' => 'Bulusan, 2 days, nature and adventure, packed', 'days' => 2, 'towns' => ['Bulusan'], 'prefs' => ['interests' => ['nature', 'adventure'], 'travel_pace' => 'packed', 'transport_preference' => 'private_car']],
            'irosin-bulusan-2d-relax' => ['label' => 'Irosin and Bulusan, 2 days, relaxation, homestay', 'days' => 2, 'towns' => ['Irosin', 'Bulusan'], 'prefs' => ['interests' => ['relaxation', 'nature'], 'travel_pace' => 'relaxed', 'accommodation_type' => 'homestay']],
            'matnog-2d-subic-last' => ['label' => 'Matnog, 2 days, beaches, leaving at 3 PM', 'days' => 2, 'towns' => ['Matnog'], 'prefs' => ['interests' => ['beaches'], 'departure_time' => '15:00', 'transport_preference' => 'private_car']],
            'matnog-3d-subic-wanted' => ['label' => 'Matnog, 3 days, Subic Beach asked for, leaving at noon', 'days' => 3, 'towns' => ['Matnog'], 'places' => ['Subic Beach'], 'prefs' => ['interests' => ['beaches', 'nature'], 'departure_time' => '12:00']],
            'bulan-matnog-3d' => ['label' => 'Bulan and Matnog, 3 days, beaches and culture', 'days' => 3, 'towns' => ['Bulan', 'Matnog'], 'prefs' => ['interests' => ['beaches', 'culture'], 'transport_preference' => 'private_car']],
            'donsol-2d-wildlife' => ['label' => 'Donsol, 2 days, nature and adventure', 'days' => 2, 'towns' => ['Donsol'], 'prefs' => ['interests' => ['nature', 'adventure'], 'transport_preference' => 'public_transport']],
            'pilar-donsol-3d' => ['label' => 'Pilar and Donsol, 3 days, nature, beaches and food', 'days' => 3, 'towns' => ['Pilar', 'Donsol'], 'prefs' => ['interests' => ['nature', 'beaches', 'food']]],
            'three-towns-luxury-seafood' => ['label' => 'Sorsogon City, Gubat and Bulusan, 3 days, every interest, luxury, seafood', 'days' => 3, 'towns' => ['Sorsogon City', 'Gubat', 'Bulusan'], 'prefs' => ['interests' => $all, 'budget' => 'luxury', 'food_preference' => 'seafood', 'transport_preference' => 'private_car']],
            'five-towns-5d' => ['label' => 'Five towns in 5 days, moderate', 'days' => 5, 'towns' => ['Sorsogon City', 'Gubat', 'Bulusan', 'Irosin', 'Matnog'], 'prefs' => ['interests' => ['nature', 'beaches', 'culture'], 'transport_preference' => 'private_car']],
            'castilla-city-2d' => ['label' => 'Castilla and Sorsogon City, 2 days, nature', 'days' => 2, 'towns' => ['Castilla', 'Sorsogon City'], 'prefs' => ['interests' => ['nature', 'beaches']]],
            'barcelona-gubat-2d' => ['label' => 'Barcelona and Gubat, 2 days, history and beaches', 'days' => 2, 'towns' => ['Barcelona', 'Gubat'], 'prefs' => ['interests' => ['history', 'beaches'], 'transport_preference' => 'private_car']],
            'prieto-diaz-1d' => ['label' => 'Prieto Diaz, 1 day, nature, street food', 'days' => 1, 'towns' => ['Prieto Diaz'], 'prefs' => ['interests' => ['nature'], 'food_preference' => 'street_food', 'accommodation_needed' => false]],
        ];
    }

    /** The scenario as validated preferences (as ItineraryController builds them); null when a name is unknown. */
    private function preferences(array $scenario): ?array
    {
        $towns = [];
        foreach ($scenario['towns'] ?? [] as $name) {
            $id = Municipality::where('name', $name)->value('id');
            if ($id === null) {
                return null;
            }
            $towns[] = (int) $id;
        }
        $places = [];
        foreach ($scenario['places'] ?? [] as $name) {
            $id = Destination::published()->where('name', $name)->value('id');
            if ($id === null) {
                return null;
            }
            $places[] = (int) $id;
        }
        $start = now()->startOfWeek()->addWeeks(2)->next('Sunday');
        $days = (int) $scenario['days'];
        $prefs = $scenario['prefs'] + ['travel_pace' => 'moderate', 'budget' => 'moderate', 'travelers' => 2, 'accommodation_needed' => true];

        return $prefs + [
            'municipality_ids' => $towns,
            'preferred_destinations' => $places,
            'num_days' => $days,
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
            'interests_balanced' => empty($prefs['interests']),
        ] + ['interests' => InterestMatcher::BALANCED];
    }

    /** The real AI, keeping every answer so the trip can be replayed. */
    private function recordingAI(ItineraryAI $ai): ItineraryAI
    {
        return new class($ai) implements ItineraryAI
        {
            public array $answers = [];

            public function __construct(private ItineraryAI $ai) {}

            public function generate(string $prompt): string
            {
                return $this->answers[] = $this->ai->generate($prompt);
            }
        };
    }

    /** Answers saved by an earlier run, in the order they were given. */
    private function replayingAI(array $saved): ItineraryAI
    {
        return new class($saved) implements ItineraryAI
        {
            public array $answers = [];

            public function __construct(private array $saved) {}

            public function generate(string $prompt): string
            {
                return array_shift($this->saved) ?? throw new \RuntimeException('The saved AI answers ran out; run the trip again without --replay.');
            }
        };
    }

    /**
     * Every problem found: [level, code, what a tourist would notice].
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function problems(Itinerary $itinerary, array $preferences, ItineraryService $service, PriorityDestinations $priorities): array
    {
        $problems = [];
        $add = function (string $level, string $code, string $text) use (&$problems) {
            $problems[] = [$level, $code, $text];
        };
        $items = $itinerary->items->sortBy([['day_number', 'asc'], ['sequence', 'asc']])->values();
        $stops = $items->where('item_type', ItineraryItem::TYPE_DESTINATION)->filter(fn ($i) => $i->destination)->values();
        $lastDay = (int) $items->max('day_number');
        $travelers = max(1, (int) ($preferences['travelers'] ?? 1));

        // Made-up businesses: the demo listings' contacts use the 000 exchange and example.com.
        $listings = $items->map(fn ($i) => $i->accommodation ?? $i->foodBeverage ?? $i->retailShop)
            ->merge($itinerary->stays->pluck('accommodation'))->filter()->unique(fn ($l) => $l::class.$l->id);
        foreach ($listings as $listing) {
            if (preg_match('/\b000-\d{4}\b/', (string) $listing->contact_number) || Str::contains((string) $listing->email.' '.$listing->website, 'example.com')) {
                $add('critical', 'demo_listing', "{$listing->name} is a demo listing (made-up business, contact {$listing->contact_number}).");
            }
        }

        // Places named as left out, and whether the title, the town note or the day still promise them.
        $leftOut = [];
        foreach ($items as $item) {
            if (preg_match('/Left out [^:]*: (.+?)\. You can add/u', (string) $item->preference_note, $m)) {
                foreach (preg_split('/, | and /', $m[1]) as $name) {
                    $leftOut[$name] = (int) $item->day_number;
                }
            }
        }
        $firstNote = (string) $items->first()?->preference_note;
        foreach ($leftOut as $name => $day) {
            if (Str::contains($itinerary->title, $name, true) || Str::contains(Str::before($firstNote, 'Left out'), $name)) {
                $add('critical', 'promise_broken', "{$name} is named in the title or the trip summary but was left out (day {$day}).");
            }
            $place = Destination::published()->where('name', $name)->first();
            if ($place && $priorities->isPriority($place) && $stops->contains(fn ($s) => ! $priorities->isPriority($s->destination))) {
                $add('high', 'priority_dropped', "{$name}, one of the best-known places, was left out (day {$day}) while less important stops stayed.");
            }
            if ($place && $stops->contains(fn ($s) => $this->km($place, $s->destination) !== null && $this->km($place, $s->destination) < 0.3)) {
                $add('high', 'neighbour_left_out', "{$name} was left out (day {$day}) although it is next door (under 300 m) to a stop in the trip.");
            }
        }

        // The last day must leave time to reach the way home.
        if (! empty($preferences['departure_time']) && $last = $items->where('day_number', $lastDay)->last()) {
            [$h, $m] = array_map('intval', explode(':', $preferences['departure_time']));
            $end = $this->slot($last->time_slot)[1] ?? null;
            if ($end !== null && $end > $h * 60 + $m - 30) {
                $add('critical', 'no_exit', "The last day ends at {$this->clock($end)} at {$this->name($last)}, with departure at {$this->clock($h * 60 + $m)}: no time to reach the way out.");
            }
        }

        foreach ($stops as $stop) {
            $place = $stop->destination;
            $label = "Day {$stop->day_number}, {$place->name}";
            [$start, $end] = $this->slot($stop->time_slot) + [null, null];
            $activities = collect($stop->activities ?? []);
            $own = $activities->reject(fn ($a) => Str::startsWith((string) ($a['name'] ?? ''), ['Lunch', 'Registration', 'Free time']));

            // Stretched visits: the time its own activities take (not lunch or registering).
            $minutes = $own->sum(fn ($a) => ($slot = $this->slot((string) ($a['time'] ?? ''))) ? $slot[1] - $slot[0] : 0);
            if ($priorities->isFullDay($place) && (int) $stop->day_number !== $lastDay && (int) $stop->day_number !== 1 && (int) $stop->duration_minutes < 300) {
                $add('high', 'full_day_cut', "{$label}: a full-day destination visited for only {$stop->duration_minutes} min.");
            }
            if (! $priorities->isFullDay($place) && $minutes > 210) {
                $add('high', 'stretched_visit', "{$label}: ".intdiv($minutes, 60).' h '.($minutes % 60).' min of activities, longer than the place needs.');
            }
            $detour = $items->where('day_number', $stop->day_number)->first(fn ($i) => Str::contains((string) $i->notes, "break during your visit to {$place->name}"));
            if ($detour && (int) $detour->travel_time_from_prev >= 10) {
                $add('medium', 'lunch_detour', "{$label}: leaves mid-visit for a meal {$detour->travel_time_from_prev} min away, then comes back.");
            }
            foreach ($own->countBy('name') as $name => $times) {
                if ($times > 1) {
                    $add('high', 'repeated_activity', "{$label}: \"{$name}\" is scheduled {$times} times.");
                }
            }

            // Activities outside the time they're best at.
            foreach ($own as $activity) {
                [$from, $to] = $this->slot((string) ($activity['time'] ?? '')) + [null, null];
                $best = mb_strtolower((string) $place->activities->firstWhere('name', $activity['name'] ?? null)?->best_time_to_visit);
                if ($from === null || $best === '') {
                    continue;
                }
                // Sunrise, sunset and evening activities only make sense then; "morning" is a preference.
                if (preg_match('/sunrise|early morning|dawn/', $best) && $from > 8 * 60
                    || preg_match('/sunset|late afternoon|evening|night/', $best) && ! preg_match('/morning|any/', $best) && $to <= 15 * 60) {
                    $add('high', 'wrong_time', "{$label}: \"{$activity['name']}\" at {$this->clock($from)}, but it is best \"{$best}\".");
                } elseif (preg_match('/morning/', $best) && ! preg_match('/afternoon|evening|any/', $best) && $from >= 12 * 60) {
                    $add('medium', 'late_for_morning', "{$label}: \"{$activity['name']}\" at {$this->clock($from)}, but it is best \"{$best}\".");
                }
                if (preg_match('/\b(trek|hik|climb)/i', (string) $activity['name']) && $from >= 11 * 60 && $from < 14 * 60) {
                    $add('medium', 'midday_heat', "{$label}: \"{$activity['name']}\" at {$this->clock($from)}, in the midday heat.");
                }
                if (preg_match('/\bmass\b/i', (string) $activity['name'])) {
                    $add('medium', 'invented_schedule', "{$label}: \"{$activity['name']}\" at {$this->clock($from)}, but no Mass schedule is listed.");
                }
            }

            // The reason promises an activity or a time the schedule doesn't have.
            $planned = mb_strtolower($own->map(fn ($a) => ($a['name'] ?? '').' '.($a['description'] ?? ''))->implode(' '));
            foreach (self::ACTIVITY_WORDS as $pattern => $meaning) {
                if (preg_match($pattern, (string) $stop->reason) && ! preg_match($pattern, $planned)) {
                    $add('high', 'reason_mismatch', "{$label}: the reason mentions {$meaning}, which isn't in the schedule.");
                }
            }
            if ($start !== null && preg_match('/\bin the morning\b|\bmorning,|\bstart early\b/i', (string) $stop->reason) && $start >= 12 * 60) {
                $add('high', 'reason_mismatch', "{$label}: the reason says morning, but the visit starts at {$this->clock($start)}.");
            }

            // Sea and boat warnings at a place with no sea or boat activity.
            $category = mb_strtolower((string) $place->category?->name);
            if (Str::contains((string) $stop->preference_note.' '.$stop->notes, 'Coast Guard') && ! in_array($category, ['beach', 'island'], true)
                && ! preg_match('/island|boat|sea\b|snorkel|div(e|ing)|whale|sandbar/i', $planned)) {
                $add('medium', 'wrong_tip', "{$label}: Coast Guard / rough-sea warning at a place with no sea trip.");
            }
        }

        // Food: the cuisine asked for, and the budget level.
        $levels = ['budget' => 1, 'moderate' => 2, 'medium' => 2, 'luxury' => 3];
        $budget = $levels[$preferences['budget'] ?? 'moderate'] ?? 2;
        $meals = $items->filter(fn ($i) => $i->foodBeverage);
        if (! in_array($preferences['food_preference'] ?? 'no_preference', [null, 'no_preference'], true)) {
            foreach (range(1, $lastDay) as $day) {
                $dayMeals = $meals->where('day_number', $day);
                if ($dayMeals->isNotEmpty() && ! $dayMeals->contains(fn ($i) => $service->matchesFoodPreference($i->foodBeverage, $preferences))) {
                    $add('high', 'cuisine_missed', "Day {$day}: no meal matches the {$preferences['food_preference']} preference.");
                }
            }
        }
        foreach ($meals as $meal) {
            if ($budget - ($levels[$meal->foodBeverage->price_range] ?? $budget) >= 2) {
                $add('medium', 'budget_mismatch', "Day {$meal->day_number}, {$meal->foodBeverage->name}: a {$meal->foodBeverage->price_range} place on a {$preferences['budget']} budget.");
            }
        }
        $snacks = $items->filter(fn ($i) => Str::startsWith((string) $i->notes, 'Afternoon merienda'));
        foreach ($snacks as $snack) {
            $add('low', 'filler_snack', "Day {$snack->day_number}: a merienda stop at {$this->name($snack)}.");
        }
        $souvenirs = $items->filter(fn ($i) => $i->retailShop);
        if ($souvenirs->count() > 1) {
            $add('medium', 'many_souvenir_stops', "{$souvenirs->count()} souvenir stops (days ".$souvenirs->pluck('day_number')->implode(', ').'); one on the last day is enough.');
        } elseif ($souvenirs->count() === 1 && $lastDay > 1 && (int) $souvenirs->first()->day_number !== $lastDay) {
            $add('low', 'early_souvenirs', "The souvenir stop is on day {$souvenirs->first()->day_number}, so pasalubong is carried for the rest of the trip.");
        }

        // Stays: how often the tourist packs up, and rooms too big for the group.
        $stays = $itinerary->stays->filter(fn ($s) => $s->accommodation && $s->night_days !== []);
        $nights = max(0, $lastDay - 1);
        if ($nights > 0 && $stays->count() > max(1, (int) ceil($nights / 2))) {
            $add('medium', 'hotel_changes', "{$stays->count()} different stays in {$nights} nights.");
        }
        foreach ($itinerary->rooms as $room) {
            if ($room->max_guests && $room->max_guests >= $travelers + 3) {
                $add('medium', 'room_too_big', "{$room->name} (up to {$room->max_guests} guests) for {$travelers} ".Str::plural('traveller', $travelers).'.');
            }
        }
        if (in_array($preferences['transport_preference'] ?? null, ['private_car', 'motorcycle', 'rented_vehicle'], true)
            && $items->contains(fn ($i) => Str::contains((string) $i->preference_note, 'keep your bags'))) {
            $add('low', 'wrong_tip', 'Asks the front desk to keep the bags although they travel in the tourist\'s own vehicle.');
        }

        // Guessed travel: legs with a distance but no Google measurement.
        $legs = $items->filter(fn ($i) => (float) $i->distance_from_prev_km > 0);
        $guessed = $legs->reject(fn ($i) => Str::contains((string) $i->notes, 'Google Maps'))->count();
        if ($guessed > 0) {
            $add('medium', 'guessed_travel', "{$guessed} of {$legs->count()} legs use a straight-line distance and an estimated time.");
        }

        return $problems;
    }

    private function score(array $problems): int
    {
        return max(0, 100 - array_sum(array_map(fn ($p) => self::WEIGHTS[$p[0]], $problems)));
    }

    /** "Day 1: 9:10 AM – 2:50 PM Paguriran Lagoon; …" per day, for the report. */
    private function outline(Itinerary $itinerary): array
    {
        return $itinerary->items->sortBy([['day_number', 'asc'], ['sequence', 'asc']])->groupBy('day_number')
            ->map(fn (Collection $day) => $day->map(fn ($i) => "{$i->time_slot} {$this->name($i)}"
                .($i->activities ? ' ['.collect($i->activities)->map(fn ($a) => ($a['time'] ?? '').' '.($a['name'] ?? ''))->implode(', ').']' : '')
                .($i->destination_id && $i->reason ? " (why: {$i->reason})" : ''))->implode('; '))->all();
    }

    private function report(array $results): string
    {
        $lines = ['# Itinerary audit, '.now()->format('F j, Y g:i A'), '', 'Database: '.DB::connection()->getDatabaseName().'. Average score: **'.round(collect($results)->avg('score'), 1).'** over '.count($results).' trips (100 = nothing found).', ''];
        $lines[] = '| Problem | Count |';
        $lines[] = '|---|---|';
        foreach (collect($results)->flatMap(fn ($r) => $r['problems'])->countBy(1)->sortDesc() as $code => $n) {
            $lines[] = "| {$code} | {$n} |";
        }
        foreach ($results as $key => $r) {
            $lines[] = '';
            $lines[] = "## {$key} — score {$r['score']}";
            $lines[] = "{$r['scenario']}. Title: ".($r['title'] ?? '—');
            $lines[] = '';
            foreach ($r['problems'] as [$level, $code, $text]) {
                $lines[] = "- **{$level}** `{$code}`: {$text}";
            }
            if ($r['problems'] === []) {
                $lines[] = '- No problems found.';
            }
            foreach ($r['outline'] as $day => $text) {
                $lines[] = "- Day {$day}: {$text}";
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** [start, end] in minutes after midnight from "9:10 AM – 2:50 PM"; [] when unreadable. */
    private function slot(?string $slot): array
    {
        preg_match_all('/(\d{1,2}):(\d{2}) ?(AM|PM)/i', (string) $slot, $m, PREG_SET_ORDER);
        $minutes = array_map(fn ($t) => ((int) $t[1] % 12 + (strtoupper($t[3]) === 'PM' ? 12 : 0)) * 60 + (int) $t[2], $m);

        return count($minutes) >= 2 ? [$minutes[0], $minutes[1]] : [];
    }

    private function clock(int $minutes): string
    {
        return now()->startOfDay()->addMinutes($minutes)->format('g:i A');
    }

    private function name(ItineraryItem $item): string
    {
        return $item->destination?->name ?? $item->foodBeverage?->name ?? $item->retailShop?->name ?? $item->accommodation?->name ?? 'a stop';
    }

    private function km($a, $b): ?float
    {
        if ($a->latitude === null || $b->latitude === null) {
            return null;
        }
        $rad = M_PI / 180;
        $h = sin(($b->latitude - $a->latitude) * $rad / 2) ** 2 + cos($a->latitude * $rad) * cos($b->latitude * $rad) * sin(($b->longitude - $a->longitude) * $rad / 2) ** 2;

        return 6371 * 2 * asin(min(1, sqrt($h)));
    }
}

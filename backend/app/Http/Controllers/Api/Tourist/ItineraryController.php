<?php

namespace App\Http\Controllers\Api\Tourist;

use App\Exceptions\AIServiceUnavailableException;
use App\Exceptions\ItineraryNotPossibleException;
use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\MenuItem;
use App\Models\Municipality;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\User;
use App\Services\ItineraryService;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ItineraryController extends Controller
{
    /**
     * Everything the itinerary page shows: each stop's place, the booked rooms and picked
     * dishes/souvenirs, and what else can be picked (the stay's rooms, each food place's
     * menu, each shop's products).
     */
    public static function relations(): array
    {
        return [
            'municipality:id,name',
            'municipalities:id,name',
            'stays.municipality:id,name',
            'stays.accommodation.images',
            'stays.accommodation.municipality:id,name',
            'stays.accommodation.rooms' => fn ($q) => $q->approved()->with('images')->reorder('price'),
            'accommodation.images',
            'accommodation.municipality',
            'accommodation.rooms' => fn ($q) => $q->approved()->with('images')->reorder('price'),
            'rooms.images',
            'items.destination.images',
            'items.destination.category',
            'items.destination.municipality',
            'items.accommodation.images',
            'items.accommodation.municipality',
            'items.foodBeverage.images',
            'items.foodBeverage.municipality',
            'items.foodBeverage.menuItems' => fn ($q) => $q->approved()->reorder('price'),
            'items.retailShop.images',
            'items.retailShop.municipality',
            'items.retailShop.products' => fn ($q) => $q->approved()->with('images')->reorder('price'),
            'items.dishes',
            'items.souvenirs.images',
        ];
    }

    /** Stop types a tourist can add while editing: model and the itinerary_items column it fills. */
    private const STOP_TYPES = [
        'destination' => [Destination::class, 'destination_id'],
        'accommodation' => [Accommodation::class, 'accommodation_id'],
        'food_beverage' => [FoodBeverage::class, 'food_beverage_id'],
        'retail_shop' => [RetailShop::class, 'retail_shop_id'],
    ];

    /** How long a guest's itinerary can still be claimed by logging in. */
    private const CLAIM_DAYS = 7;

    public function __construct(private ItineraryService $itineraryService) {}

    public function generate(Request $request): JsonResponse
    {
        $validated = $this->validatedPreferences($request);
        $user = $request->user();
        $limit = config('services.itinerary.daily_limit');

        return $this->withGenerationQuota(
            $this->userQuotaKey($user),
            $limit,
            "You've reached today's limit of {$limit} itineraries. You can generate more after midnight (Philippine time).",
            ['user_id' => $user->id],
            fn () => ['itinerary' => $this->itineraryService->generate($user, $validated)->load($this->relations())]
        );
    }

    /**
     * Guests can generate too, but only get a preview back. The full itinerary is saved
     * without an owner and unlocks once they log in and claim it with the returned token.
     */
    public function guestGenerate(Request $request): JsonResponse
    {
        $validated = $this->validatedPreferences($request);
        $limit = config('services.itinerary.guest_daily_limit');

        // Housekeeping: guest itineraries nobody claimed in time (items cascade).
        Itinerary::whereNull('user_id')->where('created_at', '<', now()->subDays(self::CLAIM_DAYS))->delete();

        return $this->withGenerationQuota(
            $this->guestQuotaKey($request),
            $limit,
            "You've reached today's limit of {$limit} itineraries as a guest. Log in to generate more.",
            ['guest' => sha1($request->ip())],
            function () use ($validated) {
                $token = Str::random(40);
                $itinerary = $this->itineraryService->generate(null, $validated, hash('sha256', $token));

                return [
                    'itinerary_id' => $itinerary->id,
                    'claim_token' => $token,
                    'preview' => $this->guestPreview($itinerary),
                ];
            }
        );
    }

    /** Attaches a guest's itinerary to the tourist who just logged in. */
    public function claim(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['claim_token' => 'required|string|max:100']);

        // One atomic update, so the same itinerary can't be claimed twice.
        $claimed = Itinerary::whereKey($id)
            ->whereNull('user_id')
            ->where('claim_token', hash('sha256', $validated['claim_token']))
            ->where('created_at', '>=', now()->subDays(self::CLAIM_DAYS))
            ->update(['user_id' => $request->user()->id, 'claim_token' => null]);

        if (! $claimed) {
            return response()->json(['message' => 'This itinerary has expired or was already saved.'], 404);
        }

        return response()->json(
            $request->user()->itineraries()->with($this->relations())->findOrFail($id)
        );
    }

    public function guestGenerationQuota(Request $request): JsonResponse
    {
        return response()->json($this->quota($this->guestQuotaKey($request), config('services.itinerary.guest_daily_limit')));
    }

    /**
     * What a guest may see before logging in: the trip summary and each day's destination.
     * Times, the stay, meals, the souvenir shop and the reasons stay locked.
     */
    private function guestPreview(Itinerary $itinerary): array
    {
        $itinerary->load(['municipality:id,name', 'municipalities:id,name', 'stays.municipality:id,name', 'stays.accommodation:id,name', 'items.destination.images']);
        $destinationStops = $itinerary->items->where('item_type', ItineraryItem::TYPE_DESTINATION);
        $towns = $itinerary->municipalities->pluck('name')->whenEmpty(fn () => collect([$itinerary->municipality?->name])->filter());

        return [
            'title' => $itinerary->title,
            'num_days' => $itinerary->num_days,
            'travel_pace' => $itinerary->travel_pace,
            'budget' => $itinerary->budget,
            'travelers' => $itinerary->travelers,
            'estimated_cost_min' => $itinerary->estimated_cost_min,
            'estimated_cost_max' => $itinerary->estimated_cost_max,
            'municipality' => $towns->implode(', ') ?: null,
            'municipalities' => $towns->values()->all(),
            // One recommended stay per town (which one is locked until login).
            'stays' => $itinerary->stays->map(fn ($s) => ['town' => $s->municipality?->name, 'nights' => $s->nights()])->values()->all(),
            'start_date' => $itinerary->start_date?->toDateString(),
            'end_date' => $itinerary->end_date?->toDateString(),
            'days' => collect(range(1, $itinerary->num_days))->map(function ($dayNumber) use ($destinationStops) {
                $destinations = $destinationStops->where('day_number', $dayNumber)->pluck('destination')->filter()
                    ->map(fn ($destination) => [
                        'id' => $destination->id,
                        'name' => $destination->name,
                        'image_path' => $destination->images->first()?->image_path,
                    ])->values();

                return [
                    'day_number' => $dayNumber,
                    // The first destination (older clients show one per day), then all of them.
                    'destination' => $destinations->first(),
                    'destinations' => $destinations->all(),
                ];
            })->all(),
            'locked_stops' => $itinerary->items->count() - $destinationStops->count(),
        ];
    }

    /**
     * The trip preferences with municipality_ids filled in (older clients send one
     * municipality_id). Travelling between towns takes most of a day, so every town needs a
     * day of its own: a trip can't cover more towns than it has days.
     */
    private function validatedPreferences(Request $request): array
    {
        $validated = $request->validate($this->preferenceRules());

        $ids = $validated['municipality_ids'] ?? [$validated['municipality_id']];
        $validated['municipality_ids'] = array_values(array_unique(array_map('intval', $ids)));
        unset($validated['municipality_id']);

        $days = (int) $validated['num_days'];
        $towns = count($validated['municipality_ids']);
        if ($towns > $days) {
            $short = $towns - $days;
            throw ValidationException::withMessages([
                'municipality_ids' => "You've picked {$towns} municipalities for a {$days}-day trip. Since travelling between municipalities takes most of a day, "
                    .'each one needs at least one day of its own — please add '.$short.' more '.($short === 1 ? 'day' : 'days').' or choose fewer municipalities.',
            ]);
        }

        // A one-day trip can't end before it starts.
        if ($days === 1 && ! empty($validated['arrival_time']) && ! empty($validated['departure_time'])
            && $validated['departure_time'] <= $validated['arrival_time']) {
            throw ValidationException::withMessages([
                'departure_time' => 'For a one-day trip, your departure time must be after your arrival time.',
            ]);
        }

        $this->validateStayChoice($validated);

        return $validated;
    }

    /**
     * The tourist's stay choice: with no accommodation needed, any picked stay or room is
     * dropped. A picked stay must be an approved listing (with a map location) in one of the
     * selected towns; a picked option must be an approved option of that stay, with or without
     * a price, and its room category must sleep the whole group when its capacity is known.
     */
    private function validateStayChoice(array &$validated): void
    {
        $validated['accommodation_needed'] = (bool) ($validated['accommodation_needed'] ?? true);
        if (! $validated['accommodation_needed']) {
            unset($validated['accommodation_id'], $validated['room_id']);

            return;
        }
        if (empty($validated['accommodation_id'])) {
            if (! empty($validated['room_id'])) {
                throw ValidationException::withMessages(['room_id' => 'Choose the accommodation before choosing a room.']);
            }

            return;
        }

        $stay = Accommodation::approved()->whereNotNull('latitude')->whereNotNull('longitude')->find($validated['accommodation_id']);
        if (! $stay) {
            throw ValidationException::withMessages(['accommodation_id' => 'This accommodation is not available on SOSOGON. Please choose another.']);
        }
        if (! in_array((int) $stay->municipality_id, $validated['municipality_ids'], true)) {
            throw ValidationException::withMessages(['accommodation_id' => "{$stay->name} isn't in one of the municipalities you selected. Choose a stay in one of them."]);
        }
        if (empty($validated['room_id'])) {
            return;
        }

        $room = AccommodationRoom::approved()->find($validated['room_id']);
        if (! $room || (int) $room->accommodation_id !== (int) $stay->id) {
            throw ValidationException::withMessages(['room_id' => "This room doesn't belong to {$stay->name}."]);
        }
        $travelers = max(1, (int) ($validated['travelers'] ?? 1));
        [, $sleeps] = $this->itineraryService->roomsForGroup($room, $travelers);
        if ($sleeps < $travelers) {
            $type = $room->category ?: 'room';
            throw ValidationException::withMessages([
                'room_id' => "{$stay->name}'s {$type} rooms sleep at most {$sleeps} of your {$travelers} travelers. Choose a bigger room type or fewer travelers.",
            ]);
        }
    }

    private function preferenceRules(): array
    {
        return [
            'municipality_ids' => 'required_without:municipality_id|array|min:1|max:30',
            'municipality_ids.*' => 'integer|distinct|exists:municipalities,id',
            'municipality_id' => 'nullable|integer|exists:municipalities,id',
            'num_days' => 'required|integer|min:1|max:30',
            'interests' => 'required|array|min:1',
            // Added 'history' to the allowed interests
            'interests.*' => 'string|in:nature,beaches,culture,food,adventure,shopping,relaxation,history',
            'travel_pace' => 'required|in:relaxed,moderate,packed',
            // Added 'medium' to the allowed budgets
            'budget' => 'required|in:budget,moderate,luxury,medium',
            'travelers' => 'nullable|integer|min:1|max:20',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            // When the tourist arrives on Day 1 ("14:30"); optional, Day 1 assumes mid-morning without it.
            'arrival_time' => 'nullable|date_format:H:i',
            // When the tourist leaves on the last day ("17:00"); optional, the last day runs through dinner without it.
            'departure_time' => 'nullable|date_format:H:i',
            // Whether the planner suggests a stay (default yes), and the stay and option picked before generating.
            'accommodation_needed' => 'sometimes|boolean',
            'accommodation_id' => 'nullable|integer',
            'room_id' => 'nullable|integer',
            'food_preference' => 'nullable|string|in:filipino_bicolano,seafood,fast_food,fine_dining,street_food,vegetarian,no_preference',
            'accommodation_type' => 'nullable|string|in:resort,hotel,homestay,budget,no_preference',
            // How the tourist plans to get around; recorded for later transportation guidance, not used in planning yet.
            'transport_preference' => 'nullable|string|in:private_car,motorcycle,rented_vehicle,public_transport,no_preference',
            'additional_preferences' => 'nullable|string|max:1000',
            'preferred_destinations' => 'nullable|array',
            'preferred_destinations.*' => 'integer|exists:destinations,id',
        ];
    }

    /**
     * Runs $generate against a daily quota and turns its outcome into the API response:
     * 201 with $generate's payload plus the quota, 429 over the limit, 422 when the tourist's
     * choices rule out a trip, 503 when the AI is busy or out of quota, 500 on any other failure.
     */
    private function withGenerationQuota(string $key, int $limit, string $limitMessage, array $logContext, Closure $generate): JsonResponse
    {
        // Reserve one of today's slots up front (atomic, so parallel requests can't both
        // slip past the limit); it is handed back below if generation fails.
        Cache::add($key, 0, $this->quotaResetsAt());

        if (Cache::increment($key) > $limit) {
            Cache::decrement($key);

            return response()->json([
                'message' => $limitMessage,
                'quota' => $this->quota($key, $limit),
            ], 429);
        }

        $succeeded = false;
        set_time_limit($this->generationTimeLimit());

        try {
            $payload = $generate();
            $succeeded = true;

            return response()->json([
                'message' => 'Itinerary generated successfully.',
                ...$payload,
                'quota' => $this->quota($key, $limit),
            ], 201);
        } catch (AIServiceUnavailableException $e) {
            Log::warning('Itinerary generation skipped: AI busy', $logContext + ['quota_exhausted' => $e->quotaExhausted]);

            return response()->json([
                'message' => $e->quotaExhausted
                    ? "The AI planner has reached today's AI usage limit. Please try again tomorrow."
                    : 'The AI planner is busy right now — please try again in a minute.',
            ], 503);
        } catch (ItineraryNotPossibleException $e) {
            // The tourist's choices, not a fault: tell them what to change, and keep it out of the error log.
            Log::info('Itinerary not possible with these choices', $logContext + ['reason' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Itinerary generation failed', $logContext + ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Failed to generate itinerary.',
                // Only leak the raw exception message in local/debug environments —
                // production clients get a generic message so future exception
                // types can't accidentally expose internal details.
                'error' => config('app.debug') ? $e->getMessage() : 'Please try again in a moment.',
            ], 500);
        } finally {
            // Only successful generations count toward the daily limit.
            if (! $succeeded) {
                Cache::decrement($key);
            }
        }
    }

    /**
     * Seconds a generation request may run. The AI call alone can outlast PHP's usual 120 s
     * limit (Claude: ANTHROPIC_TIMEOUT per attempt, ANTHROPIC_MAX_RETRIES + 1 attempts), and
     * hitting that limit kills the request: the tourist gets a bare server error and the quota
     * slot reserved above is never handed back. A minute on top covers planning and saving.
     */
    private function generationTimeLimit(): int
    {
        $anthropic = config('services.anthropic');
        $aiSeconds = config('services.itinerary.ai_provider') === 'claude'
            ? (float) $anthropic['timeout'] * ((int) $anthropic['max_retries'] + 1)
            : 300; // Gemini: 90 s per attempt while it works through its fallback models

        return (int) ceil($aiSeconds) + 60;
    }

    public function generationQuota(Request $request): JsonResponse
    {
        return response()->json($this->quota($this->userQuotaKey($request->user()), config('services.itinerary.daily_limit')));
    }

    /** @return array{limit: int, used: int, remaining: int, resets_at: string} */
    private function quota(string $key, int $limit): array
    {
        $used = min((int) Cache::get($key, 0), $limit);

        return [
            'limit' => $limit,
            'used' => $used,
            'remaining' => $limit - $used,
            'resets_at' => $this->quotaResetsAt()->toIso8601String(),
        ];
    }

    private function userQuotaKey(User $user): string
    {
        return "itinerary-generate:{$user->id}:".$this->quotaDay();
    }

    // Guests are counted per IP address (hashed, so raw IPs don't sit in the cache).
    private function guestQuotaKey(Request $request): string
    {
        return 'itinerary-guest:'.sha1($request->ip()).':'.$this->quotaDay();
    }

    // The "day" is Philippine local time, independent of the app's UTC timezone.
    private function quotaDay(): string
    {
        return now(config('services.itinerary.timezone'))->toDateString();
    }

    private function quotaResetsAt(): CarbonInterface
    {
        return now(config('services.itinerary.timezone'))->addDay()->startOfDay();
    }

    public function index(Request $request): JsonResponse
    {
        $itineraries = $request->user()
            ->itineraries()
            ->orderByDesc('created_at')
            ->paginate(10);

        return response()->json($itineraries);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $itinerary = $request->user()
            ->itineraries()
            ->with($this->relations())
            ->findOrFail($id);

        return response()->json($itinerary);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $itinerary = $request->user()->itineraries()->findOrFail($id);

        $validated = $request->validate([
            'accommodation_id' => 'sometimes|integer',
            'edit_items' => 'array',
            'edit_items.*.id' => 'required|integer|exists:itinerary_items,id',
            'edit_items.*.listing_id' => 'sometimes|integer',
            'edit_items.*.time_slot' => 'nullable|string|max:50',
            'edit_items.*.notes' => 'nullable|string|max:2000',
            'edit_items.*.activities' => 'sometimes|nullable|array|max:15',
            'edit_items.*.activities.*.time' => 'nullable|string|max:50',
            'edit_items.*.activities.*.name' => 'required|string|max:120',
            'edit_items.*.activities.*.description' => 'nullable|string|max:500',
            'delete_item_ids' => 'array',
            'delete_item_ids.*' => 'integer|exists:itinerary_items,id',
            'new_items' => 'array',
            'new_items.*.day_number' => 'required|integer|min:1',
            'new_items.*.item_type' => 'sometimes|in:'.implode(',', array_keys(self::STOP_TYPES)),
            'new_items.*.listing_id' => 'required_without:new_items.*.destination_id|integer',
            'new_items.*.destination_id' => 'required_without:new_items.*.listing_id|integer',
            'new_items.*.time_slot' => 'nullable|string|max:50',
            'new_items.*.notes' => 'nullable|string|max:2000',
            'new_items.*.sequence' => 'required|integer|min:1',
            'new_items.*.activities' => 'sometimes|nullable|array|max:15',
            'new_items.*.activities.*.time' => 'nullable|string|max:50',
            'new_items.*.activities.*.name' => 'required|string|max:120',
            'new_items.*.activities.*.description' => 'nullable|string|max:500',
            'edit_items.*.dishes' => 'sometimes|array|max:20',
            'edit_items.*.dishes.*.id' => 'required|integer|distinct',
            'edit_items.*.dishes.*.quantity' => 'required|integer|min:1|max:50',
            'edit_items.*.souvenirs' => 'sometimes|array|max:20',
            'edit_items.*.souvenirs.*.id' => 'required|integer|distinct',
            'edit_items.*.souvenirs.*.quantity' => 'required|integer|min:1|max:50',
            'room_ids' => 'sometimes|array|max:30',
            'room_ids.*' => 'integer|distinct',
            'stays' => 'sometimes|array|max:20',
            'stays.*.id' => 'required|integer|distinct',
            'stays.*.accommodation_id' => 'sometimes|integer',
            'stays.*.room_ids' => 'sometimes|array|max:30',
            'stays.*.room_ids.*' => 'integer|distinct',
        ]);

        $items = $itinerary->items()->get()->keyBy('id');
        $referencedIds = collect($validated['edit_items'] ?? [])->pluck('id')
            ->merge($validated['delete_item_ids'] ?? []);
        foreach ($referencedIds as $itemId) {
            abort_unless($items->has($itemId), 403, 'This stop does not belong to this itinerary.');
        }

        // Swapped and added places must be live listings in the itinerary's municipalities.
        $townIds = $itinerary->municipalityIds();
        $swaps = collect($validated['edit_items'] ?? [])
            ->filter(fn ($edit) => isset($edit['listing_id']))
            ->mapWithKeys(function ($edit, $i) use ($items, $townIds) {
                $type = $items[$edit['id']]->item_type;
                $this->assertAvailable($type, $edit['listing_id'], $townIds, "edit_items.{$i}.listing_id");

                return [$edit['id'] => $edit['listing_id']];
            });
        $newItems = collect($validated['new_items'] ?? [])->map(function ($new, $i) use ($townIds) {
            $type = $new['item_type'] ?? 'destination';
            $listingId = $new['listing_id'] ?? $new['destination_id'];
            $this->assertAvailable($type, $listingId, $townIds, "new_items.{$i}.listing_id");

            return ['item_type' => $type, self::STOP_TYPES[$type][1] => $listingId] + $new;
        });
        // Stay edits: one per town's stay. The older single accommodation_id / room_ids
        // change the main stay (the one the itinerary shows as its accommodation).
        $this->itineraryService->ensureStays($itinerary);
        $stays = $itinerary->stays()->get()->keyBy('id');
        $stayEdits = collect($validated['stays'] ?? [])->mapWithKeys(fn ($edit, $i) => [$edit['id'] => $edit + ['field' => "stays.{$i}"]]);
        $main = $stays->firstWhere('accommodation_id', $itinerary->accommodation_id) ?? $stays->first();
        if ($main && (isset($validated['accommodation_id']) || isset($validated['room_ids']))) {
            $stayEdits[$main->id] = ($stayEdits[$main->id] ?? ['id' => $main->id, 'field' => null])
                + array_filter(['accommodation_id' => $validated['accommodation_id'] ?? null, 'room_ids' => $validated['room_ids'] ?? null], fn ($v) => $v !== null);
        }
        $field = fn (array $edit, string $key) => $edit['field'] ? "{$edit['field']}.{$key}" : $key;
        foreach ($stayEdits as $stayId => $edit) {
            abort_unless($stays->has($stayId), 403, 'This stay does not belong to this itinerary.');
            if (isset($edit['accommodation_id'])) {
                // A stay is replaced within its own town.
                $town = $stays[$stayId]->municipality_id;
                $this->assertAvailable('accommodation', $edit['accommodation_id'], $town ? [$town] : $townIds, $field($edit, 'accommodation_id'));
            }
        }

        // Destinations are placed by time, so a new, swapped or re-timed one needs a start–end time.
        $timeError = 'Set a start and end time for this destination.';
        foreach ($newItems as $i => $new) {
            if ($new['item_type'] === ItineraryItem::TYPE_DESTINATION && ! $this->itineraryService->slotMinutes($new['time_slot'] ?? null)) {
                throw ValidationException::withMessages(["new_items.{$i}.time_slot" => $timeError]);
            }
        }
        foreach ($validated['edit_items'] ?? [] as $i => $edit) {
            $item = $items[$edit['id']];
            $timed = array_key_exists('time_slot', $edit) || $swaps->has($item->id);
            if ($item->item_type === ItineraryItem::TYPE_DESTINATION && $timed
                && ! $this->itineraryService->slotMinutes(array_key_exists('time_slot', $edit) ? $edit['time_slot'] : $item->time_slot)) {
                throw ValidationException::withMessages(["edit_items.{$i}.time_slot" => $timeError]);
            }
        }

        // No destination, food place or shop may appear twice (the stay may).
        $deleteIds = $validated['delete_item_ids'] ?? [];
        $placeKey = fn (string $type, $listingId) => "{$type}:{$listingId}";
        $finalPlaces = $items->except($deleteIds)->toBase()
            ->map(fn ($item) => $placeKey($item->item_type, $swaps[$item->id] ?? $item->{self::STOP_TYPES[$item->item_type][1]}))
            ->values()
            ->merge($newItems->map(fn ($new) => $placeKey($new['item_type'], $new[self::STOP_TYPES[$new['item_type']][1]])));
        $counts = $finalPlaces->countBy();
        $changedPlaces = $swaps->map(fn ($listingId, $itemId) => $placeKey($items[$itemId]->item_type, $listingId))->values()
            ->merge($newItems->map(fn ($new) => $placeKey($new['item_type'], $new[self::STOP_TYPES[$new['item_type']][1]])));
        foreach ($changedPlaces as $key) {
            if (! str_starts_with($key, 'accommodation:') && ($counts[$key] ?? 0) > 1) {
                throw ValidationException::withMessages(['edit_items' => 'That place is already in your itinerary. Pick a different one.']);
            }
        }

        // Picked dishes, souvenirs and rooms must be on that place's approved menu, product
        // list or rooms. A stop that moves to another place gets fresh picks instead.
        $changedStayIds = $stayEdits->filter(fn ($edit, $stayId) => isset($edit['accommodation_id']) && (int) $edit['accommodation_id'] !== (int) $stays[$stayId]->accommodation_id)->keys()->all();
        $pickedStayIds = $stayEdits->filter(fn ($edit, $stayId) => isset($edit['room_ids']) && ! in_array($stayId, $changedStayIds, true))->keys()->all();
        $picks = collect($validated['edit_items'] ?? [])->reject(fn ($edit) => $swaps->has($edit['id']));
        foreach ($picks as $i => $edit) {
            $item = $items[$edit['id']];
            foreach (['dishes' => [MenuItem::class, 'food_beverage_id'], 'souvenirs' => [RetailProduct::class, 'retail_shop_id']] as $key => [$model, $owner]) {
                if (! isset($edit[$key])) {
                    continue;
                }
                $ids = array_column($edit[$key], 'id');
                $found = $item->{$owner} ? $model::approved()->where($owner, $item->{$owner})->whereIn('id', $ids)->count() : 0;
                if ($found !== count($ids)) {
                    throw ValidationException::withMessages(["edit_items.{$i}.{$key}" => "Pick {$key} from this stop's own list."]);
                }
            }
        }
        foreach ($pickedStayIds as $stayId) {
            $roomIds = $stayEdits[$stayId]['room_ids'];
            $found = $roomIds === [] ? 0 : AccommodationRoom::approved()->where('accommodation_id', $stays[$stayId]->accommodation_id)->whereIn('id', $roomIds)->count();
            if ($found !== count($roomIds)) {
                throw ValidationException::withMessages([$field($stayEdits[$stayId], 'room_ids') => 'Pick rooms from this stay.']);
            }
        }

        $changedItemIds = [];
        $pickedItemIds = [];

        DB::transaction(function () use ($itinerary, $validated, $deleteIds, $items, $swaps, $newItems, $picks, $stays, $stayEdits, $changedStayIds, $pickedStayIds, &$changedItemIds, &$pickedItemIds) {
            ItineraryItem::whereIn('id', $deleteIds)->where('itinerary_id', $itinerary->id)->delete();

            // Days whose stops moved in time, and destinations whose activities need laying out again.
            $touchedDays = [];
            $refreshIds = [];

            foreach ($validated['edit_items'] ?? [] as $edit) {
                $item = $items[$edit['id']];
                $fields = collect($edit)->only(['time_slot', 'notes', 'activities'])->toArray();
                if ($swaps->has($item->id)) {
                    $fields[self::STOP_TYPES[$item->item_type][1]] = $swaps[$item->id];
                    $fields['reason'] = 'You picked this place.';
                    $fields['preference_note'] = null;
                    $changedItemIds[] = $item->id;
                }
                if (array_key_exists('time_slot', $fields) || $swaps->has($item->id)) {
                    $touchedDays[] = $item->day_number;
                    if ($item->item_type === ItineraryItem::TYPE_DESTINATION && ! array_key_exists('activities', $edit)) {
                        $refreshIds[] = $item->id;
                    }
                }
                if ($fields !== []) {
                    $item->update($fields);
                }
            }

            foreach ($newItems as $new) {
                $column = self::STOP_TYPES[$new['item_type']][1];
                $created = ItineraryItem::create([
                    'itinerary_id' => $itinerary->id,
                    'item_type' => $new['item_type'],
                    $column => $new[$column],
                    'day_number' => $new['day_number'],
                    'sequence' => $new['sequence'],
                    'time_slot' => $new['time_slot'] ?? null,
                    'notes' => $new['notes'] ?? null,
                    'activities' => ($new['activities'] ?? []) ?: null,
                    'reason' => 'You picked this place.',
                ]);
                $changedItemIds[] = $created->id;
                $touchedDays[] = $created->day_number;
                // A destination added without activities gets its own listed ones.
                if ($created->item_type === ItineraryItem::TYPE_DESTINATION && empty($new['activities'])) {
                    $refreshIds[] = $created->id;
                }
            }

            $this->arrangeDays($itinerary, array_unique($touchedDays), $refreshIds);

            foreach ($picks as $edit) {
                $item = $items[$edit['id']];
                foreach (['dishes', 'souvenirs'] as $key) {
                    if (isset($edit[$key])) {
                        $item->{$key}()->sync(collect($edit[$key])->mapWithKeys(fn ($p) => [$p['id'] => ['quantity' => $p['quantity']]])->all());
                        $pickedItemIds[] = $item->id;
                    }
                }
            }

            foreach ($changedStayIds as $stayId) {
                $stays[$stayId]->update([
                    'accommodation_id' => $stayEdits[$stayId]['accommodation_id'],
                    'reason' => 'You picked this stay.',
                    'note' => null,
                ]);
            }
            foreach ($pickedStayIds as $stayId) {
                if ($stays[$stayId]->accommodation_id) {
                    $this->itineraryService->setStayRooms($itinerary, $stays[$stayId]->accommodation_id, $stayEdits[$stayId]['room_ids']);
                }
            }

            $this->itineraryService->recalculate($itinerary, $changedItemIds, $pickedItemIds, $changedStayIds, $pickedStayIds);
        });

        return response()->json([
            'message' => 'Itinerary updated.',
            'itinerary' => $itinerary->fresh($this->relations()),
        ]);
    }

    /**
     * After an edit, for each day in $days: blocks a day's destinations spanning two towns (a
     * day is spent in one municipality) and overlapping stops (only a meal or shop stop may
     * sit inside a visit, like a lunch break), lays out the listed activities of the
     * destinations in $refreshIds inside their new times, and puts the stops in time order.
     * Stops without a readable time keep their place after the stop they followed.
     */
    private function arrangeDays(Itinerary $itinerary, array $days, array $refreshIds): void
    {
        if ($days === []) {
            return;
        }

        $service = $this->itineraryService;
        $nestable = [ItineraryItem::TYPE_FOOD_BEVERAGE, ItineraryItem::TYPE_RETAIL_SHOP];
        $name = fn ($s) => $s->destination?->name ?? $s->foodBeverage?->name ?? $s->retailShop?->name ?? $s->accommodation?->name ?? 'A stop';

        $byDay = $itinerary->items()->whereIn('day_number', $days)
            ->with(['destination.activities', 'destination.municipality:id,name', 'foodBeverage:id,name', 'retailShop:id,name', 'accommodation:id,name'])
            ->get()->groupBy('day_number');

        foreach ($byDay as $day => $stops) {
            $visits = $stops->filter(fn ($s) => $s->destination)->sortBy('sequence');
            if ($visits->pluck('destination.municipality_id')->unique()->count() > 1) {
                // Name the town the day was already in (a visit that wasn't just added or moved).
                $town = ($visits->first(fn ($s) => ! in_array($s->id, $refreshIds, true)) ?? $visits->first())->destination->municipality?->name ?? 'one town';
                throw ValidationException::withMessages(['new_items' => "Day {$day} is already in {$town}. Visiting two municipalities in one day isn't practical — please pick a place in {$town} or add it to another day."]);
            }

            $timed = $stops->map(fn ($s) => ['stop' => $s, 'range' => $service->slotMinutes($s->time_slot)])
                ->filter(fn ($t) => $t['range'] !== null)->values();

            foreach ($timed as $i => $a) {
                foreach ($timed->slice($i + 1) as $b) {
                    [$a0, $a1] = $a['range'];
                    [$b0, $b1] = $b['range'];
                    if ($a0 >= $b1 || $b0 >= $a1) {
                        continue;
                    }
                    $inner = $a0 <= $b0 && $b1 <= $a1 ? $b['stop'] : ($b0 <= $a0 && $a1 <= $b1 ? $a['stop'] : null);
                    if (! $inner || ! in_array($inner->item_type, $nestable, true)) {
                        throw ValidationException::withMessages(['edit_items' => "{$name($a['stop'])} ({$a['stop']->time_slot}) overlaps {$name($b['stop'])} ({$b['stop']->time_slot}) on Day {$day}. Change one of their times."]);
                    }
                }
            }

            foreach ($timed as $t) {
                $stop = $t['stop'];
                if (! in_array($stop->id, $refreshIds, true) || ! $stop->destination) {
                    continue;
                }
                [$start, $end] = $t['range'];
                // Like a generated visit, an added or changed one stays within the place's listed
                // (or typical estimated) opening hours on that day.
                $date = $itinerary->start_date?->copy()->addDays($day - 1);
                if ($problem = $service->visitHoursProblem($stop->destination, $start, $end, $date)) {
                    throw ValidationException::withMessages(['edit_items' => $problem]);
                }
                $blocks = $timed
                    ->filter(fn ($o) => $o['stop']->id !== $stop->id && in_array($o['stop']->item_type, $nestable, true)
                        && $o['range'][0] >= $start && $o['range'][1] <= $end)
                    ->map(fn ($o) => [$o['range'][0], $o['range'][1], "Break at {$name($o['stop'])}", null])
                    ->values()->all();
                $stop->update(['activities' => $service->scheduleDestinationActivities($stop->destination, $start, $end, $blocks, $itinerary->travel_pace)]);
            }

            $after = -1;
            $order = $stops->sortBy('sequence')->values()->map(function ($s) use ($service, &$after) {
                $range = $service->slotMinutes($s->time_slot);
                $after = $range ? $range[0] : $after;

                return ['stop' => $s, 'key' => [$after, $s->sequence]];
            })->sortBy('key')->values();
            foreach ($order as $i => $row) {
                if ($row['stop']->sequence !== $i + 1) {
                    $row['stop']->update(['sequence' => $i + 1]);
                }
            }
        }
    }

    /** 422 unless $listingId is a live listing of $type in one of the itinerary's municipalities. */
    private function assertAvailable(string $type, int $listingId, array $townIds, string $field): void
    {
        [$model] = self::STOP_TYPES[$type];
        $scope = $type === 'destination' ? 'published' : 'approved';

        if (! $model::$scope()->whereIn('municipality_id', $townIds)->whereKey($listingId)->exists()) {
            throw ValidationException::withMessages([$field => 'That place is not available in this itinerary\'s municipalities.']);
        }
    }

    /**
     * Everything the place pickers can offer, per town of the itinerary: destinations, stays,
     * food places and souvenir shops in its municipalities, each with its town, nearest that
     * town's stay first, a picture and whether it's already in the itinerary. Also the towns,
     * in trip order, so the picker can show one town at a time.
     */
    public function eligibleListings(Request $request, int $id): JsonResponse
    {
        // Ensure the itinerary belongs to this tourist before exposing a picker for it.
        $itinerary = $request->user()->itineraries()
            ->with(['accommodation:id,latitude,longitude', 'stays.accommodation:id,latitude,longitude', 'items'])->findOrFail($id);
        $townIds = $itinerary->municipalityIds();
        $withTown = count($townIds) > 1;

        // Distances are measured from the stay in the listing's own town (else the main stay).
        $coords = fn ($place) => $place && $place->latitude !== null && $place->longitude !== null
            ? [(float) $place->latitude, (float) $place->longitude]
            : null;
        $origins = $itinerary->stays->mapWithKeys(fn ($s) => [(int) $s->municipality_id => $coords($s->accommodation)])->filter();
        $mainOrigin = $coords($itinerary->accommodation);
        $stayIds = $itinerary->stays->pluck('accommodation_id')->filter()->push($itinerary->accommodation_id)->filter()->all();

        $rows = function ($listings, ?string $usedColumn, callable $subtitle) use ($itinerary, $origins, $mainOrigin, $withTown, $stayIds) {
            $used = $usedColumn ? $itinerary->items->pluck($usedColumn)->filter()->all() : [];

            return $listings
                ->map(fn ($l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'subtitle' => implode(' · ', array_filter([$subtitle($l), $withTown ? $l->municipality?->name : null])),
                    'description' => Str::limit(trim(strip_tags((string) $l->description)), 140),
                    'image_path' => $l->images->first()?->thumbnail_path ?: $l->images->first()?->image_path,
                    'municipality_id' => $l->municipality_id,
                    'km' => ($origin = $origins[(int) $l->municipality_id] ?? $mainOrigin) && $l->latitude !== null ? round($this->kmFrom($origin, $l), 1) : null,
                    'in_itinerary' => in_array($l->id, $used),
                    'is_stay' => $usedColumn === null && in_array($l->id, $stayIds),
                ])
                ->sortBy(fn ($row) => [$row['km'] ?? PHP_FLOAT_MAX, $row['name']])
                ->values();
        };
        $label = fn (...$parts) => implode(' · ', array_filter(array_map(fn ($p) => $p ? ucfirst($p) : null, $parts)));

        $towns = Municipality::whereIn('id', $townIds)->pluck('name', 'id');

        return response()->json([
            'municipalities' => collect($townIds)->map(fn ($id) => ['id' => $id, 'name' => $towns[$id] ?? null])->values(),
            'destinations' => $rows(
                Destination::published()->whereIn('municipality_id', $townIds)->with(['category:id,name', 'municipality:id,name', 'images'])->get(),
                'destination_id',
                fn ($d) => $d->category?->name
            ),
            'accommodations' => $rows(
                Accommodation::approved()->whereIn('municipality_id', $townIds)->with(['municipality:id,name', 'images'])->get(),
                null,
                fn ($a) => $label($a->type, $a->price_range)
            ),
            'food_beverages' => $rows(
                FoodBeverage::approved()->whereIn('municipality_id', $townIds)->with(['municipality:id,name', 'images'])->get(),
                'food_beverage_id',
                fn ($f) => $label($f->cuisine_type, $f->price_range)
            ),
            'shops' => $rows(
                RetailShop::approved()->whereIn('municipality_id', $townIds)->with(['municipality:id,name', 'images'])->get(),
                'retail_shop_id',
                fn () => 'Souvenir shop'
            ),
        ]);
    }

    private function kmFrom(array $origin, $listing): float
    {
        $dLat = deg2rad((float) $listing->latitude - $origin[0]);
        $dLon = deg2rad((float) $listing->longitude - $origin[1]);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($origin[0])) * cos(deg2rad((float) $listing->latitude)) * sin($dLon / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $itinerary = $request->user()->itineraries()->findOrFail($id);
        $itinerary->delete();

        return response()->json(['message' => 'Itinerary deleted.']);
    }
}

<?php

namespace App\Services;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\ListingVerification;
use App\Models\RetailShop;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Last verified" for the published information of destinations, accommodations, restaurants and
 * shops, in four parts (aspects): price, location, operating hours and contact information.
 *
 * A part is verified only by an explicit action of an admin, never by an edit. The check stores a
 * fingerprint of the part's published values (the listing's own columns, and the approved rooms,
 * menu items, products or fees for prices; owners' edits still waiting for approval are not
 * published, so they don't count). When the published values later differ from the fingerprint,
 * the part shows as changed since it was verified, not as verified. Every check is a new
 * ListingVerification row, so the history is kept.
 *
 * Status of a part: not_verified (never checked), verified, overdue (checked longer ago than its
 * overdue days, see overdueDays()) or changed (its published values changed since the last check).
 */
class ListingVerifications
{
    public const SETTING_KEY = 'verification.overdue_days';

    public const ASPECTS = [
        'price' => 'Price',
        'location' => 'Location',
        'hours' => 'Operating Hours',
        'contact' => 'Contact Information',
    ];

    public const STATUSES = [
        'not_verified' => 'Not yet verified',
        'verified' => 'Recently verified',
        'overdue' => 'Verification overdue',
        'changed' => 'Updated since last verified',
    ];

    /** The listings that can be verified: model and the public page's path. */
    public const TYPES = [
        'destination' => [Destination::class, '/destinations'],
        'accommodation' => [Accommodation::class, '/accommodations'],
        'food_beverage' => [FoodBeverage::class, '/food-beverage'],
        'retail_shop' => [RetailShop::class, '/shops'],
    ];

    public const TYPE_LABELS = [
        'destination' => 'Destination',
        'accommodation' => 'Accommodation',
        'food_beverage' => 'Food & Beverage',
        'retail_shop' => 'Shop',
    ];

    /**
     * The parts each type of listing has. Every type has all four today (shops' prices are their
     * products'); a type without one would simply leave it out, never show it as unverified.
     */
    private const TYPE_ASPECTS = [
        'destination' => ['price', 'location', 'hours', 'contact'],
        'accommodation' => ['price', 'location', 'hours', 'contact'],
        'food_beverage' => ['price', 'location', 'hours', 'contact'],
        'retail_shop' => ['price', 'location', 'hours', 'contact'],
    ];

    /** Contact columns each type has. */
    private const CONTACT_FIELDS = [
        'destination' => ['contact_number' => 'Contact number', 'website' => 'Website'],
        'accommodation' => ['contact_number' => 'Contact number', 'email' => 'Email', 'website' => 'Website'],
        'food_beverage' => ['contact_number' => 'Contact number', 'website' => 'Website'],
        'retail_shop' => ['contact_number' => 'Contact number'],
    ];

    /** @var array<string, array> Price items loaded in bulk by preload(), keyed "type:id". */
    private array $priceItems = [];

    /** @var array<string, Collection> Verification rows loaded in bulk by preload(), keyed "type:id". */
    private array $history = [];

    public static function aspectsFor(string $type): array
    {
        return self::TYPE_ASPECTS[$type] ?? [];
    }

    public static function typeOf(Model $listing): ?string
    {
        foreach (self::TYPES as $type => [$class]) {
            if ($listing instanceof $class) {
                return $type;
            }
        }

        return null;
    }

    /** The published (public) listings of $type. */
    public static function publicQuery(string $type): Builder
    {
        $class = self::TYPES[$type][0];

        return $class === Destination::class ? $class::published() : $class::approved();
    }

    public static function isPublic(string $type, Model $listing): bool
    {
        return self::publicQuery($type)->whereKey($listing->getKey())->exists();
    }

    /** Days after which each part's check is overdue: the saved settings over the defaults. */
    public static function overdueDays(): array
    {
        $saved = (array) SiteSetting::get(self::SETTING_KEY, []);

        return collect(config('verification.overdue_days'))
            ->map(fn ($days, $aspect) => isset($saved[$aspect]) && (int) $saved[$aspect] > 0 ? (int) $saved[$aspect] : (int) $days)
            ->all();
    }

    /** Loads the price items and verification history of many listings of one type at once. */
    public function preload(string $type, Collection $listings): void
    {
        $ids = $listings->modelKeys();
        if ($ids === []) {
            return;
        }
        foreach ($this->loadPriceItems($type, $ids) as $id => $items) {
            $this->priceItems["{$type}:{$id}"] = $items;
        }
        foreach ($ids as $id) {
            $this->priceItems["{$type}:{$id}"] ??= $this->emptyPriceItems($type);
        }
        $rows = ListingVerification::with('verifier:id,name')->where('listing_type', $type)->whereIn('listing_id', $ids)
            ->orderByDesc('verified_at')->orderByDesc('id')->get()->groupBy('listing_id');
        foreach ($ids as $id) {
            $this->history["{$type}:{$id}"] = $rows->get($id, collect());
        }
    }

    /**
     * The published values of one part of a listing, in a fixed order and normal form, so the same
     * information always gives the same fingerprint.
     */
    public function values(string $type, Model $listing, string $aspect): array
    {
        $text = fn ($value) => ($value = trim(preg_replace('/\s+/u', ' ', (string) $value))) === '' ? null : $value;
        $id = fn ($value) => $value === null || $value === '' ? null : (int) $value;

        return match ($aspect) {
            'location' => [
                'municipality_id' => $id($listing->municipality_id),
                'barangay_id' => $id($listing->barangay_id),
                'address' => $text($listing->address),
                'latitude' => $listing->latitude === null ? null : round((float) $listing->latitude, 6),
                'longitude' => $listing->longitude === null ? null : round((float) $listing->longitude, 6),
            ],
            'hours' => array_merge(
                ['operating_hours' => $text($listing->operating_hours)],
                $type === 'accommodation' ? ['check_in_time' => $text($listing->check_in_time), 'check_out_time' => $text($listing->check_out_time)] : [],
            ),
            'contact' => collect(self::CONTACT_FIELDS[$type])->map(fn ($label, $field) => $text($listing->{$field}))->all(),
            'price' => array_merge(match ($type) {
                'destination' => ['entrance_fee' => self::money($listing->entrance_fee)],
                'accommodation', 'food_beverage' => ['price_range' => $text($listing->price_range)],
                default => [],
            }, $this->priceItemsOf($type, $listing)),
        };
    }

    public static function fingerprint(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Each part of a listing with its status. $withDetails adds who verified it, the note, and
     * the current values and fingerprint (admin side only).
     *
     * @return array<int, array<string, mixed>>
     */
    public function statusOf(string $type, Model $listing, bool $withDetails = false): array
    {
        $rows = $this->historyOf($type, $listing);
        $days = self::overdueDays();

        return collect(self::aspectsFor($type))->map(function ($aspect) use ($type, $listing, $rows, $days, $withDetails) {
            $values = $this->values($type, $listing, $aspect);
            $fingerprint = self::fingerprint($values);
            $last = $rows->firstWhere('aspect', $aspect);
            $dueAt = $last ? Carbon::parse($last->verified_at)->addDays($days[$aspect]) : null;
            $status = match (true) {
                ! $last => 'not_verified',
                $last->fingerprint !== $fingerprint => 'changed',
                $dueAt->isPast() => 'overdue',
                default => 'verified',
            };

            return [
                'aspect' => $aspect,
                'label' => self::ASPECTS[$aspect],
                'status' => $status,
                'status_label' => self::STATUSES[$status],
                'verified_at' => $last?->verified_at?->toIso8601String(),
                'overdue_days' => $days[$aspect],
            ] + ($withDetails ? [
                'due_at' => $dueAt?->toIso8601String(),
                'verified_by' => $last?->verifier?->name,
                'note' => $last?->note,
                'fingerprint' => $fingerprint,
                'values' => $values,
                'display' => $this->display($type, $listing, $aspect, $values),
            ] : []);
        })->values()->all();
    }

    /**
     * What the public detail page shows: each part's state and last check date, never who did it.
     * Called "state" there: the public API never sends a field named status (PublicData::HIDDEN).
     */
    public function publicSummary(Model $listing): array
    {
        $type = self::typeOf($listing);

        return [
            'verified_by' => 'Tourism Office',
            'aspects' => collect($this->statusOf($type, $listing))->map(fn ($row) => [
                'aspect' => $row['aspect'],
                'label' => $row['label'],
                'state' => $row['status'],
                'state_label' => $row['status_label'],
                'verified_at' => $row['verified_at'],
                'overdue_days' => $row['overdue_days'],
            ])->all(),
        ];
    }

    /** Every check of a listing, newest first. */
    public function historyOf(string $type, Model $listing): Collection
    {
        return $this->history["{$type}:{$listing->getKey()}"] ??= ListingVerification::with('verifier:id,name')
            ->where('listing_type', $type)->where('listing_id', $listing->getKey())
            ->orderByDesc('verified_at')->orderByDesc('id')->get();
    }

    /**
     * Records that $admin checked $aspects of a published listing now. $seen are the fingerprints
     * the admin reviewed: if the information changed in the meantime, nothing is recorded, so a
     * check never covers values the admin didn't see.
     *
     * @return Collection<int, ListingVerification>
     */
    public function verify(string $type, Model $listing, array $aspects, User $admin, ?string $note = null, array $seen = []): Collection
    {
        if (! self::isPublic($type, $listing)) {
            throw ValidationException::withMessages(['listing' => 'Only published listings can be verified.']);
        }

        $now = now();
        $rows = collect($aspects)->unique()->map(function ($aspect) use ($type, $listing, $seen) {
            $values = $this->values($type, $listing, $aspect);
            $fingerprint = self::fingerprint($values);
            if (isset($seen[$aspect]) && $seen[$aspect] !== $fingerprint) {
                throw ValidationException::withMessages(['fingerprints' => 'This information changed while you were reviewing it. Please review it again before verifying.']);
            }

            return compact('aspect', 'values', 'fingerprint');
        });

        $created = DB::transaction(fn () => $rows->map(fn ($row) => ListingVerification::create([
            'listing_type' => $type,
            'listing_id' => $listing->getKey(),
            'aspect' => $row['aspect'],
            'fingerprint' => $row['fingerprint'],
            'snapshot' => $row['values'],
            'note' => $note,
            'verified_by' => $admin->id,
            'verified_at' => $now,
        ]))->values());

        unset($this->history["{$type}:{$listing->getKey()}"]);

        return $created;
    }

    /** Lines an admin reads to check a part: "Entrance fee: ₱20.00", "Deluxe Room: ₱2,500.00". */
    private function display(string $type, Model $listing, string $aspect, array $values): array
    {
        $none = 'Not stated';
        $peso = fn ($amount) => $amount === null ? $none : '₱'.number_format((float) $amount, 2);

        return match ($aspect) {
            'location' => [
                ['label' => 'Municipality', 'value' => $listing->municipality?->name ?? $none],
                ['label' => 'Barangay', 'value' => $listing->barangay?->name ?? $none],
                ['label' => 'Address', 'value' => $values['address'] ?? $none],
                ['label' => 'Map pin', 'value' => $values['latitude'] !== null && $values['longitude'] !== null ? "{$values['latitude']}, {$values['longitude']}" : $none],
            ],
            'hours' => array_values(array_filter([
                ['label' => 'Operating hours', 'value' => $values['operating_hours'] ?? $none],
                $type === 'accommodation' ? ['label' => 'Check-in', 'value' => $values['check_in_time'] ?? $none] : null,
                $type === 'accommodation' ? ['label' => 'Check-out', 'value' => $values['check_out_time'] ?? $none] : null,
            ])),
            'contact' => collect(self::CONTACT_FIELDS[$type])->map(fn ($label, $field) => ['label' => $label, 'value' => $values[$field] ?? $none])->values()->all(),
            'price' => array_merge(
                array_key_exists('entrance_fee', $values) ? [['label' => 'Entrance fee', 'value' => $peso($values['entrance_fee'])]] : [],
                array_key_exists('price_range', $values) ? [['label' => 'Price range', 'value' => $values['price_range'] ? ucfirst($values['price_range']) : $none]] : [],
                collect($values['fees'] ?? [])->map(fn ($fee) => ['label' => 'Fee: '.$fee['label'], 'value' => $peso($fee['amount'])])->all(),
                collect($values['rates'] ?? [])->map(fn ($rate) => ['label' => 'Rate: '.$rate['label'], 'value' => $peso($rate['amount'])])->all(),
                collect($values['options'] ?? [])->map(fn ($room) => ['label' => $room['name'], 'value' => $peso($room['price']).($room['price_max'] !== null ? ' – '.$peso($room['price_max']) : '').($room['price_note'] ? " ({$room['price_note']})" : '')])->all(),
                collect($values['menu'] ?? [])->map(fn ($item) => ['label' => $item['name'], 'value' => $peso($item['price'])])->all(),
                collect($values['products'] ?? [])->map(fn ($item) => ['label' => $item['name'], 'value' => $peso($item['price'])])->all(),
            ),
        };
    }

    private function priceItemsOf(string $type, Model $listing): array
    {
        return $this->priceItems["{$type}:{$listing->getKey()}"]
            ??= $this->loadPriceItems($type, [$listing->getKey()])[$listing->getKey()] ?? $this->emptyPriceItems($type);
    }

    private function emptyPriceItems(string $type): array
    {
        return match ($type) {
            'destination' => ['fees' => [], 'rates' => []],
            'accommodation' => ['options' => []],
            'food_beverage' => ['menu' => []],
            'retail_shop' => ['products' => []],
        };
    }

    /**
     * The published prices that belong to listings: a destination's fees and rates, and the
     * approved rooms, menu items and products (their pending changes are not published).
     *
     * @return array<int, array> keyed by listing id
     */
    private function loadPriceItems(string $type, array $ids): array
    {
        $text = fn ($value) => ($value = trim(preg_replace('/\s+/u', ' ', (string) $value))) === '' ? null : $value;
        $rows = fn (string $table, string $key, array $columns, bool $approved) => DB::table($table)->whereIn($key, $ids)
            ->when($approved, fn ($q) => $q->where('status', 'approved'))
            ->orderBy('id')->get([$key, 'id', ...$columns])->groupBy($key);

        $items = [];
        if ($type === 'destination') {
            $fees = $rows('destination_fees', 'destination_id', ['label', 'amount', 'description'], false);
            $rates = $rows('destination_rates', 'destination_id', ['label', 'amount', 'description'], false);
            $list = fn ($group) => collect($group)->map(fn ($row) => ['label' => $text($row->label), 'amount' => self::money($row->amount), 'description' => $text($row->description)])->values()->all();
            foreach ($ids as $id) {
                $items[$id] = ['fees' => $list($fees->get($id, [])), 'rates' => $list($rates->get($id, []))];
            }
        } elseif ($type === 'accommodation') {
            $rooms = $rows('accommodation_rooms', 'accommodation_id', ['name', 'pricing_mode', 'price', 'price_max', 'price_note'], true);
            foreach ($ids as $id) {
                $items[$id] = ['options' => collect($rooms->get($id, []))->map(fn ($row) => [
                    'id' => (int) $row->id, 'name' => $text($row->name), 'pricing_mode' => $text($row->pricing_mode),
                    'price' => self::money($row->price), 'price_max' => self::money($row->price_max), 'price_note' => $text($row->price_note),
                ])->values()->all()];
            }
        } else {
            [$table, $key, $name] = $type === 'food_beverage' ? ['menu_items', 'food_beverage_id', 'menu'] : ['retail_products', 'retail_shop_id', 'products'];
            $found = $rows($table, $key, ['name', 'price'], true);
            foreach ($ids as $id) {
                $items[$id] = [$name => collect($found->get($id, []))->map(fn ($row) => ['id' => (int) $row->id, 'name' => $text($row->name), 'price' => self::money($row->price)])->values()->all()];
            }
        }

        return $items;
    }

    private static function money(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : number_format((float) $value, 2, '.', '');
    }
}

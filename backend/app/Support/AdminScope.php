<?php

namespace App\Support;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\AccountViolation;
use App\Models\Destination;
use App\Models\Event;
use App\Models\FoodBeverage;
use App\Models\MenuItem;
use App\Models\Municipality;
use App\Models\Rating;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Which municipalities an admin may manage, in one place for every admin endpoint.
 *
 *  - Municipal admins ('municipal') manage the records of users.municipality_id only. Anything
 *    else answers 403 Forbidden.
 *  - A municipality with its own tourism office (an active municipal admin) is managed by that
 *    office alone. Provincial admins (users.admin_scope = 'provincial', or not assigned yet) see
 *    it but don't change it, except for the province's own decisions: Featured and Tourism
 *    priority, and moving a listing to another municipality.
 *  - Provincial admins manage the province-wide records (events and announcements without a
 *    municipality, hero slides) and every municipality without an office of its own; such a
 *    municipality passes to its office as soon as it gets an active municipal admin, and back
 *    when it no longer has one.
 *  - Any other admin_scope value is invalid and grants nothing (fail-closed): such an account is
 *    refused by the admin.scope middleware, and every check here treats it as managing nothing.
 *
 * Public pages, tourists and the itinerary planner are never limited by this: it only applies to
 * the admin area.
 */
class AdminScope
{
    /** Every model a review can be about; each has its own municipality_id. */
    public const RATEABLE_TYPES = [Destination::class, Accommodation::class, FoodBeverage::class, RetailShop::class, RetailProduct::class];

    /**
     * The municipalities with their own tourism office: an active municipal admin is assigned to
     * them (not suspended, deactivated, closed or pending). Remembered for the rest of an HTTP
     * request; outside one (commands, tests calling it directly) it is read each time.
     *
     * @return array<int, int>
     */
    public static function townsWithOwnOffice(): array
    {
        $request = request();
        if ($request->route() !== null && $request->attributes->has('admin_scope.own_offices')) {
            return $request->attributes->get('admin_scope.own_offices');
        }

        $towns = self::activeMunicipalAdmins()->distinct()->pluck('municipality_id')
            ->map(fn ($id) => (int) $id)->values()->all();
        if ($request->route() !== null) {
            $request->attributes->set('admin_scope.own_offices', $towns);
        }

        return $towns;
    }

    /** Drops the remembered townsWithOwnOffice(), e.g. after an account was saved (see User::booted). */
    public static function forgetOwnOffices(): void
    {
        request()->attributes->remove('admin_scope.own_offices');
    }

    public static function hasOwnOffice(?int $municipalityId): bool
    {
        return $municipalityId !== null && in_array($municipalityId, self::townsWithOwnOffice(), true);
    }

    /** Municipal admins who can sign in now, with their municipality set. */
    private static function activeMunicipalAdmins(): Builder
    {
        return User::where('role_id', Role::where('name', 'admin')->value('id'))
            ->where('admin_scope', User::ADMIN_SCOPE_MUNICIPAL)
            ->whereNotNull('municipality_id')
            ->whereNull('closed_at')
            ->where(fn ($q) => $q->where('status', 'active')
                // A temporary suspension that has ended (not yet written back) no longer restricts.
                ->orWhere(fn ($s) => $s->where('status', 'suspended')->whereNotNull('suspended_until')->where('suspended_until', '<=', now())));
    }

    /**
     * The municipality ids an admin sees: null for all of them, an empty list for none (a
     * municipal admin whose municipality isn't set). Lists, reports and searches use this; a
     * change to a record also needs managesMunicipality() (see authorize()).
     *
     * @return array<int, int>|null
     */
    public static function municipalityIds(User $admin): ?array
    {
        if ($admin->isProvincialAdmin()) {
            return null;
        }

        return $admin->isMunicipalAdmin() && $admin->municipality_id ? [(int) $admin->municipality_id] : [];
    }

    /** Limits a query to records whose $column is one of the admin's municipalities. */
    public static function apply(QueryBuilder $query, User $admin, string $column = 'municipality_id'): QueryBuilder
    {
        $ids = self::municipalityIds($admin);

        return $ids === null ? $query : $query->whereIn($column, $ids);
    }

    /**
     * The municipality a new record gets when the request names none: a municipal admin's own
     * (they can only add to it), otherwise null (province-wide, a provincial admin's choice).
     */
    public static function defaultMunicipality(User $admin, mixed $requested): ?int
    {
        if ($requested !== null && $requested !== '') {
            return (int) $requested;
        }

        return $admin->isMunicipalAdmin() && $admin->municipality_id ? (int) $admin->municipality_id : null;
    }

    /**
     * Marks each record with can_manage and managed_by (see mark()), so the admin screens can
     * show province-wide items, and a provincial admin a municipality's own office's, read-only.
     */
    public static function markManageable(iterable $records, User $admin, string $column = 'municipality_id'): void
    {
        foreach ($records as $record) {
            $town = $record instanceof Model ? $record->getAttribute($column) : ($record->{$column} ?? null);
            self::mark($record, $admin, $town === null ? null : (int) $town);
        }
    }

    /**
     * Sets can_manage on a record (a model or a query row) of $municipalityId, and managed_by:
     * the office that manages it instead (e.g. "Bulan Tourism Office") when the admin can't
     * because the municipality has its own office; null otherwise. $canManage overrides the
     * municipality check (e.g. a user account, see managesUser()).
     */
    public static function mark(object $record, User $admin, ?int $municipalityId, ?bool $canManage = null): void
    {
        $canManage ??= $admin->managesMunicipality($municipalityId);
        $managedBy = ! $canManage && self::hasOwnOffice($municipalityId) ? self::officeName($municipalityId) : null;

        if ($record instanceof Model) {
            $record->setAttribute('can_manage', $canManage);
            $record->setAttribute('managed_by', $managedBy);
        } else {
            $record->can_manage = $canManage;
            $record->managed_by = $managedBy;
        }
    }

    /**
     * can_manage and managed_by (see mark()) for a room, menu item, product or listing, by its
     * municipalities (itemMunicipalities()): the admin must manage each of them.
     *
     * @return array{can_manage: bool, managed_by: string|null}
     */
    public static function itemAccess(User $admin, ?Model $item): array
    {
        $towns = $item ? self::itemMunicipalities($item) : [];
        $canManage = collect($towns ?: [null])->every(fn ($town) => $admin->managesMunicipality($town));
        $town = $towns[0] ?? null;

        return ['can_manage' => $canManage, 'managed_by' => ! $canManage && self::hasOwnOffice($town) ? self::officeName($town) : null];
    }

    /**
     * can_manage and managed_by (see mark()) for a violation (and its appeal): whether the
     * admin may act on its user over its review (managesUser()).
     *
     * @return array{can_manage: bool, managed_by: string|null}
     */
    public static function violationAccess(User $admin, AccountViolation $violation): array
    {
        $canManage = $violation->user !== null && self::managesUser($admin, $violation->user, $violation->rating);
        $town = $canManage ? null : self::violationMunicipality($violation);

        return ['can_manage' => $canManage, 'managed_by' => $admin->isProvincialAdmin() && self::hasOwnOffice($town) ? self::officeName($town) : null];
    }

    /** @var array<int, string> */
    private static array $officeNames = [];

    /** How a municipality's own tourism office is named, e.g. "Bulan Tourism Office". */
    public static function officeName(int $municipalityId): string
    {
        return self::$officeNames[$municipalityId] ??= (Municipality::whereKey($municipalityId)->value('name') ?? 'Municipal').' Tourism Office';
    }

    /**
     * Limits a query to the records an admin may change: apply(), and for a provincial admin
     * without the municipalities that have their own office. For counts of work waiting on them.
     */
    public static function applyManageable(QueryBuilder $query, User $admin, string $column = 'municipality_id'): QueryBuilder
    {
        $query = self::apply($query, $admin, $column);
        $ownOffices = $admin->isProvincialAdmin() ? self::townsWithOwnOffice() : [];

        return $ownOffices === [] ? $query : $query->where(fn ($q) => $q->whereNull($column)->orWhereNotIn($column, $ownOffices));
    }

    /**
     * Limits a query to records whose $relation (e.g. a room's 'accommodation') is in one of the
     * admin's municipalities.
     */
    public static function applyThrough(Builder $query, User $admin, string $relation, string $column = 'municipality_id'): Builder
    {
        $ids = self::municipalityIds($admin);

        return $ids === null ? $query : $query->whereHas($relation, fn ($q) => $q->whereIn($column, $ids));
    }

    /** Limits a query of ratings (or of rows with a 'rating') to reviews of the admin's listings. */
    public static function applyToRatings(Builder $query, User $admin, ?string $through = null): Builder
    {
        $ids = self::municipalityIds($admin);
        if ($ids === null) {
            return $query;
        }
        $limit = fn ($q) => $q->whereHasMorph('rateable', self::RATEABLE_TYPES, fn ($listing) => $listing->whereIn('municipality_id', $ids));

        return $through ? $query->whereHas($through, $limit) : $limit($query);
    }

    /**
     * The municipalities a room, menu item or product belongs to: its listing's. A product also
     * carries its own municipality_id, so both its own and its shop's count (they always match;
     * if they ever don't, an admin needs authority over both).
     *
     * @return array<int, int>
     */
    public static function itemMunicipalities(Model $item): array
    {
        $towns = match (true) {
            $item instanceof AccommodationRoom => [$item->accommodation?->municipality_id],
            $item instanceof MenuItem => [$item->foodBeverage?->municipality_id],
            $item instanceof RetailProduct => [$item->municipality_id, $item->shop?->municipality_id],
            default => [$item->getAttribute('municipality_id')],
        };

        return array_values(array_unique(array_map('intval', array_filter($towns, fn ($t) => $t !== null))));
    }

    /** authorize() for a room, menu item or product (see itemMunicipalities()). */
    public static function authorizeItem(User $admin, Model $item): void
    {
        $towns = self::itemMunicipalities($item);
        foreach ($towns ?: [null] as $town) {
            self::authorize($admin, $town);
        }
    }

    /** authorizeView() for a room, menu item, product or listing (see itemMunicipalities()). */
    public static function authorizeItemView(User $admin, Model $item): void
    {
        $towns = self::itemMunicipalities($item);
        foreach ($towns ?: [null] as $town) {
            self::authorizeView($admin, $town);
        }
    }

    /** authorize() for a review: its listing's municipality decides (a deleted listing: provincial). */
    public static function authorizeRating(User $admin, ?Rating $rating): void
    {
        $listing = $rating?->rateable;
        if (! $listing) {
            self::authorize($admin, null);

            return;
        }
        self::authorizeItem($admin, $listing);
    }

    /** authorizeRating() for reading a review (e.g. a report's evidence). */
    public static function authorizeRatingView(User $admin, ?Rating $rating): void
    {
        $listing = $rating?->rateable;
        if (! $listing) {
            self::authorizeView($admin, null);

            return;
        }
        self::authorizeItemView($admin, $listing);
    }

    /**
     * Whether an admin may act on a user account (warn, suspend, deactivate, reactivate, close,
     * rename). A business owner belongs to their business's municipality (see
     * User::businessMunicipalityId) and a tourist, over $rating (one of their reviews), to the
     * reviewed listing's: the admin must manage that municipality (managesMunicipality, so a
     * municipality with its own office decides alone). A municipal admin acts on a tourist only
     * over such a review. Provincial admins also handle tourists outside any review, owners
     * without a municipality, and admin accounts.
     */
    public static function managesUser(User $admin, User $target, ?Rating $rating = null): bool
    {
        if ($admin->isProvincialAdmin()) {
            if ($target->isBusinessOwner()) {
                return $admin->managesMunicipality($target->businessMunicipalityId());
            }
            if ($target->isTourist() && $rating?->rateable) {
                return collect(self::itemMunicipalities($rating->rateable))->every(fn ($town) => $admin->managesMunicipality($town));
            }

            return true;
        }
        if (! $admin->isMunicipalAdmin() || $target->isAdmin()) {
            return false;
        }
        if ($target->isBusinessOwner()) {
            return $admin->managesMunicipality($target->businessMunicipalityId());
        }
        if (! $rating || (int) $rating->user_id !== (int) $target->id || ! $rating->rateable) {
            return false;
        }

        $towns = self::itemMunicipalities($rating->rateable);

        return $towns !== [] && collect($towns)->every(fn ($town) => $admin->managesMunicipality($town));
    }

    /** Whether an admin may view a user account: provincial admins every account, a municipal admin those they manage. */
    public static function seesUser(User $admin, User $target, ?Rating $rating = null): bool
    {
        return $admin->isProvincialAdmin() || self::managesUser($admin, $target, $rating);
    }

    /** A violation (and its appeal) is the business of whoever may act on it: its user over its review. */
    public static function authorizeViolation(User $admin, AccountViolation $violation): void
    {
        self::authorizeUser($admin, $violation->user, $violation->rating);
    }

    /** Reading a violation's evidence or an appeal's attachments: whoever sees its user over its review. */
    public static function authorizeViolationView(User $admin, AccountViolation $violation): void
    {
        abort_unless(self::seesUser($admin, $violation->user, $violation->rating), 403, self::userMessage($violation->user));
    }

    public static function authorizeUser(User $admin, User $target, ?Rating $rating = null): void
    {
        if (self::managesUser($admin, $target, $rating)) {
            return;
        }

        $town = $target->isBusinessOwner() ? $target->businessMunicipalityId() : self::municipalityOf($rating?->rateable);
        abort(403, $admin->isProvincialAdmin() && self::hasOwnOffice($town) ? self::ownOfficeMessage($town) : self::userMessage($target));
    }

    private static function userMessage(User $target): string
    {
        return match (true) {
            $target->isAdmin() => 'Only the provincial administrator can manage administrator accounts.',
            $target->isBusinessOwner() => 'This business belongs to another municipality. Only its municipal tourism office can act on it.',
            default => 'A municipal tourism office can act on a tourist only over their review of a listing in its municipality. Other cases are handled by the provincial administrator.',
        };
    }

    /** Why a provincial admin can't change a record of a municipality with its own tourism office. */
    private static function ownOfficeMessage(?int $municipalityId): string
    {
        $name = $municipalityId ? Municipality::whereKey($municipalityId)->value('name') : null;

        return ($name ? "{$name} has its own tourism office, which manages its records." : 'This municipality has its own tourism office, which manages its records.')
            .' The provincial administrator can view them.';
    }

    /**
     * Limits a user query to the accounts a municipal admin works with: business owners of their
     * municipality (by listing, registration application or business submission), and tourists
     * who reviewed a listing there. Admin accounts are never included.
     */
    public static function applyToUsers(Builder $query, User $admin): Builder
    {
        $ids = self::municipalityIds($admin);
        if ($ids === null) {
            return $query;
        }
        $inTown = fn ($q) => $q->whereIn('municipality_id', $ids);

        return $query->where(fn ($q) => $q
            ->where(fn ($owner) => $owner
                ->whereHas('role', fn ($r) => $r->where('name', 'business_owner'))
                ->where(fn ($business) => $business
                    ->whereExists(fn ($e) => $e->from('accommodations')->whereColumn('accommodations.user_id', 'users.id')->whereIn('municipality_id', $ids))
                    ->orWhereExists(fn ($e) => $e->from('food_beverages')->whereColumn('food_beverages.user_id', 'users.id')->whereIn('municipality_id', $ids))
                    ->orWhereExists(fn ($e) => $e->from('retail_shops')->whereColumn('retail_shops.user_id', 'users.id')->whereIn('municipality_id', $ids))
                    ->orWhereHas('ownerApplication', $inTown)
                    ->orWhereHas('businessSubmissions', $inTown)))
            ->orWhere(fn ($tourist) => $tourist
                ->whereHas('role', fn ($r) => $r->where('name', 'tourist'))
                ->whereHas('ratings', fn ($ratings) => self::applyToRatings($ratings, $admin))));
    }

    /**
     * Limits a violation query to those a municipal admin may see: of business owners in their
     * municipality, and of tourists over a review of a listing there.
     */
    public static function applyToViolations(Builder $query, User $admin): Builder
    {
        if (self::municipalityIds($admin) === null) {
            return $query;
        }

        return $query->where(fn ($v) => $v
            ->whereHas('user', fn ($users) => self::applyToUsers($users->whereHas('role', fn ($r) => $r->where('name', 'business_owner')), $admin))
            ->orWhereHas('rating', fn ($ratings) => self::applyToRatings($ratings, $admin)));
    }

    /**
     * The public pages whose page views belong to the admin's municipality: the detail pages of
     * its destinations, accommodations, food & beverage establishments, shops and events. Null
     * for provincial admins (the whole site).
     *
     * @return array<int, string>|null
     */
    public static function pagePaths(User $admin): ?array
    {
        $ids = self::municipalityIds($admin);
        if ($ids === null) {
            return null;
        }
        $paths = [];
        foreach ([Destination::class => '/destinations/', Accommodation::class => '/accommodations/', FoodBeverage::class => '/food-beverage/',
            RetailShop::class => '/shops/', Event::class => '/events/'] as $model => $prefix) {
            foreach ($model::whereIn('municipality_id', $ids)->pluck('id') as $id) {
                $paths[] = $prefix.$id;
            }
        }

        return $paths;
    }

    /** The municipality a room, menu item, product or listing belongs to (for notifications), if any. */
    public static function municipalityOf(?Model $record): ?int
    {
        return $record ? (self::itemMunicipalities($record)[0] ?? null) : null;
    }

    /**
     * The municipality a violation is handled in: its review's listing's, else a business owner's
     * business municipality; null (provincial) otherwise.
     */
    public static function violationMunicipality(AccountViolation $violation): ?int
    {
        if ($violation->rating?->rateable) {
            return self::municipalityOf($violation->rating->rateable);
        }

        return $violation->user?->isBusinessOwner() ? $violation->user->businessMunicipalityId() : null;
    }

    /** Limits a product query: the product's own municipality and its shop's must both be the admin's. */
    public static function applyToProducts(Builder $query, User $admin): Builder
    {
        $ids = self::municipalityIds($admin);
        if ($ids === null) {
            return $query;
        }

        return $query->whereIn('municipality_id', $ids)
            ->where(fn ($q) => $q->whereNull('retail_shop_id')->orWhereHas('shop', fn ($shop) => $shop->whereIn('municipality_id', $ids)));
    }

    /** Stops the request with 403 unless the admin manages $municipalityId (null: province-wide). */
    public static function authorize(User $admin, ?int $municipalityId): void
    {
        if ($admin->managesMunicipality($municipalityId)) {
            return;
        }

        abort(403, match (true) {
            $municipalityId === null => 'Province-wide records are managed by the provincial administrator.',
            $admin->isProvincialAdmin() => self::ownOfficeMessage($municipalityId),
            default => 'This record belongs to another municipality. Only its municipal tourism office can manage it.',
        });
    }

    /** Stops the request with 403 unless the admin may view records of $municipalityId. */
    public static function authorizeView(User $admin, ?int $municipalityId): void
    {
        abort_unless($admin->seesMunicipality($municipalityId), 403, $municipalityId === null
            ? 'Province-wide records are managed by the provincial administrator.'
            : 'This record belongs to another municipality.');
    }

    /** authorize() for a record with a municipality column. */
    public static function authorizeRecord(User $admin, Model $record, string $column = 'municipality_id'): void
    {
        $id = $record->getAttribute($column);
        self::authorize($admin, $id === null ? null : (int) $id);
    }

    /** authorizeView() for a record with a municipality column. */
    public static function authorizeRecordView(User $admin, Model $record, string $column = 'municipality_id'): void
    {
        $id = $record->getAttribute($column);
        self::authorizeView($admin, $id === null ? null : (int) $id);
    }

    /**
     * Reviewing an owner's revision of a listing (approve or request changes). A revision that
     * moves the listing to another municipality is the provincial administrator's decision, so a
     * provincial admin may review it even when the listing's municipality has its own office;
     * any other revision needs authorize() over the listing.
     */
    public static function authorizeRevision(User $admin, Model $listing, ?Model $revision): void
    {
        $proposed = $revision?->getAttribute('proposed_data')['municipality_id'] ?? null;
        if ($admin->isProvincialAdmin() && self::isTransfer($listing, $proposed)) {
            return;
        }
        self::authorizeRecord($admin, $listing);
    }

    /** Whether $newMunicipalityId moves $listing to another municipality. */
    public static function isTransfer(Model $listing, mixed $newMunicipalityId): bool
    {
        return $newMunicipalityId !== null && $newMunicipalityId !== ''
            && (int) $newMunicipalityId !== (int) $listing->getAttribute('municipality_id');
    }

    /**
     * Moving a listing to another municipality (an owner's revision, or an admin's edit) changes
     * which office manages it, so only a provincial admin may do it. Same or no municipality: no-op.
     */
    public static function authorizeTransfer(User $admin, Model $listing, mixed $newMunicipalityId): void
    {
        if (! self::isTransfer($listing, $newMunicipalityId)) {
            return;
        }

        abort_unless($admin->isProvincialAdmin(), 403, 'Moving a listing to another municipality needs the provincial administrator\'s approval.');
    }

    /**
     * Province-wide rankings (Featured, Tourism priority) are set by provincial admins only. A
     * municipal admin's request may still carry them (the admin forms send every field), as long
     * as it doesn't change them: a new record keeps the defaults (not featured, no priority), an
     * existing one its current values. Any change is 403.
     *
     * @param  array<int, string>  $fields
     */
    public static function guardProvincialFields(User $admin, array $validated, ?Model $record, array $fields = ['is_featured', 'tourism_priority']): void
    {
        if ($admin->isProvincialAdmin()) {
            return;
        }
        $normalize = fn ($value) => $value === null || $value === '' || $value === false || $value === 0 || $value === '0' ? null : (is_bool($value) || $value === 1 || $value === '1' ? true : (string) $value);
        foreach ($fields as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }
            $current = $record?->getAttribute($field);
            abort_if($normalize($validated[$field]) !== $normalize($current), 403,
                'Only the provincial administrator can set Featured and Tourism priority, since they rank places across the whole province.');
        }
    }

    /** Stops the request with 403 unless the admin manages every municipality. */
    public static function requireProvincial(User $admin): void
    {
        abort_unless($admin->isProvincialAdmin(), 403, 'Only the provincial administrator can do this.');
    }

    /**
     * Admins responsible for a record of $municipalityId: a municipality with its own tourism
     * office, its active municipal admins only; a province-wide record (null) or a municipality
     * without an office of its own, the provincial admins.
     */
    public static function adminsFor(?int $municipalityId): Builder
    {
        if (self::hasOwnOffice($municipalityId)) {
            return self::activeMunicipalAdmins()->where('municipality_id', $municipalityId);
        }

        // Provincial (or not yet assigned); an invalid scope receives nothing.
        return User::where('role_id', Role::where('name', 'admin')->value('id'))
            ->where(fn ($q) => $q->whereNull('admin_scope')->orWhere('admin_scope', User::ADMIN_SCOPE_PROVINCIAL));
    }
}

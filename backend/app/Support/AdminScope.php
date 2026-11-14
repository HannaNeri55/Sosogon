<?php

namespace App\Support;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\AccountViolation;
use App\Models\Destination;
use App\Models\Event;
use App\Models\FoodBeverage;
use App\Models\MenuItem;
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
 *  - Provincial admins (users.admin_scope = 'provincial', or not assigned yet) manage every
 *    municipality and the province-wide records (events and announcements without one, hero
 *    slides).
 *  - Municipal admins ('municipal') manage the records of users.municipality_id only. Anything
 *    else answers 403 Forbidden.
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
     * The municipality ids an admin manages: null for all of them, an empty list for none
     * (a municipal admin whose municipality isn't set).
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
     * For content that can be province-wide (events, announcements): the admin's municipalities
     * plus the province-wide records (no municipality), which a municipal admin may read but not
     * change.
     */
    public static function applyWithProvinceWide(QueryBuilder $query, User $admin, string $column = 'municipality_id'): QueryBuilder
    {
        $ids = self::municipalityIds($admin);

        return $ids === null ? $query : $query->where(fn ($q) => $q->whereIn($column, $ids)->orWhereNull($column));
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

    /** Marks each record with can_manage, so the admin screens can show province-wide items read-only. */
    public static function markManageable(iterable $records, User $admin, string $column = 'municipality_id'): void
    {
        foreach ($records as $record) {
            $town = $record->getAttribute($column);
            $record->setAttribute('can_manage', $admin->managesMunicipality($town === null ? null : (int) $town));
        }
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

    /**
     * Whether an admin may act on a user account (warn, suspend, deactivate, reactivate, close,
     * rename): provincial admins on anyone but other admins (as before); a municipal admin on
     * business owners of their municipality (the business's municipality, see
     * User::businessMunicipalityId) and on a tourist only over $rating, one of that tourist's
     * reviews of a listing in their municipality. Other admin accounts: provincial only.
     */
    public static function managesUser(User $admin, User $target, ?Rating $rating = null): bool
    {
        if ($admin->isProvincialAdmin()) {
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

    /** A violation (and its evidence, appeal) is the business of whoever may act on it: its user over its review. */
    public static function authorizeViolation(User $admin, AccountViolation $violation): void
    {
        self::authorizeUser($admin, $violation->user, $violation->rating);
    }

    public static function authorizeUser(User $admin, User $target, ?Rating $rating = null): void
    {
        if (self::managesUser($admin, $target, $rating)) {
            return;
        }

        abort(403, match (true) {
            $target->isAdmin() => 'Only the provincial administrator can manage administrator accounts.',
            $target->isBusinessOwner() => 'This business belongs to another municipality. Only its municipal tourism office or the provincial administrator can act on it.',
            default => 'A municipal tourism office can act on a tourist only over their review of a listing in its municipality. Other cases are handled by the provincial administrator.',
        });
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

        abort(403, $municipalityId === null
            ? 'Province-wide records are managed by the provincial administrator.'
            : 'This record belongs to another municipality. Only its municipal tourism office or the provincial administrator can manage it.');
    }

    /** authorize() for a record with a municipality column. */
    public static function authorizeRecord(User $admin, Model $record, string $column = 'municipality_id'): void
    {
        $id = $record->getAttribute($column);
        self::authorize($admin, $id === null ? null : (int) $id);
    }

    /**
     * Moving a listing to another municipality (an owner's revision, or an admin's edit) changes
     * which office manages it, so only a provincial admin may do it. Same or no municipality: no-op.
     */
    public static function authorizeTransfer(User $admin, Model $listing, mixed $newMunicipalityId): void
    {
        if ($newMunicipalityId === null || $newMunicipalityId === '' || (int) $newMunicipalityId === (int) $listing->getAttribute('municipality_id')) {
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
     * Admins responsible for a record of $municipalityId: its municipal admins and every
     * provincial admin. A province-wide record (null), or a municipality without a municipal
     * admin, goes to the provincial admins.
     */
    public static function adminsFor(?int $municipalityId): Builder
    {
        return User::where('role_id', Role::where('name', 'admin')->value('id'))
            ->where(function ($q) use ($municipalityId) {
                // Provincial (or not yet assigned); an invalid scope receives nothing.
                $q->whereNull('admin_scope')->orWhere('admin_scope', User::ADMIN_SCOPE_PROVINCIAL);
                if ($municipalityId !== null) {
                    $q->orWhere(fn ($m) => $m->where('admin_scope', User::ADMIN_SCOPE_MUNICIPAL)->where('municipality_id', $municipalityId));
                }
            });
    }
}

<?php

namespace App\Support;

use App\Models\Accommodation;
use App\Models\FoodBeverage;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Collection;

/**
 * What the public API leaves out of records it returns: fields only the admin side uses (who
 * created or approved a record, admin notes, workflow status, view counts, the planner's
 * ranking, review moderation) and a listing owner's own account. Applied to a model, collection
 * or page of results and to every relation loaded on them; the admin API is unaffected.
 */
class PublicData
{
    public const HIDDEN = ['approved_by', 'created_by', 'admin_notes', 'status', 'view_count', 'tourism_priority', 'is_demo', 'hidden_at'];

    /** Listings whose owner (the `user` relation) is a private account, never shown publicly. */
    private const OWNED = [Accommodation::class, FoodBeverage::class, RetailShop::class, RetailProduct::class];

    /**
     * @template T
     *
     * @param  T  $value
     * @return T
     */
    public static function strip(mixed $value): mixed
    {
        if ($value instanceof AbstractPaginator) {
            $value->getCollection()->each(fn ($item) => self::strip($item));
        } elseif ($value instanceof Collection) {
            $value->each(fn ($item) => self::strip($item));
        } elseif ($value instanceof Model) {
            $value->makeHidden(self::HIDDEN);
            if (in_array($value::class, self::OWNED, true)) {
                $value->unsetRelation('user');
            }
            foreach ($value->getRelations() as $related) {
                self::strip($related);
            }
        }

        return $value;
    }
}

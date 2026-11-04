<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An estimated value for a detail a listing is missing (see the listing_estimates migration).
 * Public pages show it as approximate; the basis stays internal.
 */
class ListingEstimate extends Model
{
    protected $fillable = ['estimable_type', 'estimable_id', 'field', 'value', 'basis'];

    protected $hidden = ['basis', 'estimable_type', 'estimable_id', 'created_at', 'updated_at'];

    public function estimable(): MorphTo
    {
        return $this->morphTo();
    }
}

<?php

namespace App\Models\Concerns;

use App\Models\ListingEstimate;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Destinations, accommodations, food places and shops: estimated values for details they're
 * missing, kept in listing_estimates. A real value in the listing's own column always wins.
 */
trait HasEstimates
{
    public function estimates(): MorphMany
    {
        return $this->morphMany(ListingEstimate::class, 'estimable');
    }

    /** The estimated value for $field, or null (uses the loaded relation when there is one). */
    public function estimate(string $field): ?string
    {
        return $this->estimates->firstWhere('field', $field)?->value;
    }
}

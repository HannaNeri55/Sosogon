<?php

namespace App\Models\Concerns;

use App\Models\BusinessSubmission;

/**
 * For business listings (accommodations, food & beverage, shops): when a listing moves to another
 * municipality, its business submission (and a shop's products) follow, so the new
 * municipality's admin manages the business (see App\Support\AdminScope). Only a provincial
 * admin can approve such a move.
 *
 * The using model defines SUBMISSION_TYPE (business_submissions.type).
 */
trait KeepsSubmissionMunicipality
{
    protected static function bootKeepsSubmissionMunicipality(): void
    {
        static::updated(function ($listing) {
            if ($listing->wasChanged('municipality_id') && $listing->municipality_id !== null) {
                BusinessSubmission::where('type', static::SUBMISSION_TYPE)
                    ->where('listing_id', $listing->getKey())
                    ->update(['municipality_id' => $listing->municipality_id]);
                // A shop's products carry its municipality too.
                if (method_exists($listing, 'products')) {
                    $listing->products()->update(['municipality_id' => $listing->municipality_id]);
                }
            }
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One confirmation by the Tourism Office that one part (aspect) of a listing's published
 * information was checked: what was checked (snapshot), its fingerprint, who and when. Never
 * updated or deleted: a new check adds a row. See App\Services\ListingVerifications.
 */
class ListingVerification extends Model
{
    protected $fillable = ['listing_type', 'listing_id', 'aspect', 'fingerprint', 'snapshot', 'note', 'verified_by', 'verified_at'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'verified_at' => 'datetime'];
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}

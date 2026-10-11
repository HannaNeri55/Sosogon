<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The recommended stay in one of the trip's towns, and the nights (after which days) spent
 * there. Its rooms are the itinerary's rooms that belong to its accommodation.
 */
class ItineraryStay extends Model
{
    protected $fillable = [
        'itinerary_id',
        'municipality_id',
        'accommodation_id',
        'night_days',
        'reason',
        'note',
        'cost_min',
        'cost_max',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'night_days' => 'array',
            'cost_min' => 'decimal:2',
            'cost_max' => 'decimal:2',
        ];
    }

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(Itinerary::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function nights(): int
    {
        return count($this->night_days ?? []);
    }
}

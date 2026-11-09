<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A room type a stay offers (name, beds, guests, amenities, published price), for tourists
 * and the trip planner to read. Information only: the bookable rooms are AccommodationRoom.
 */
class AccommodationRoomType extends Model
{
    protected $fillable = [
        'accommodation_id',
        'name',
        'description',
        'bed_configuration',
        'max_guests',
        'amenities',
        'price_min',
        'price_max',
        'price_note',
        'breakfast_included',
        'source_url',
        'sort_order',
    ];

    protected $hidden = ['source_url'];

    protected function casts(): array
    {
        return [
            'amenities' => 'array',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
            'breakfast_included' => 'boolean',
            'max_guests' => 'integer',
        ];
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }
}

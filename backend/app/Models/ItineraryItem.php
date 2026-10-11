<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ItineraryItem extends Model
{
    public const TYPE_DESTINATION = 'destination';

    public const TYPE_ACCOMMODATION = 'accommodation';

    public const TYPE_FOOD_BEVERAGE = 'food_beverage';

    public const TYPE_RETAIL_SHOP = 'retail_shop';

    protected $fillable = [
        'itinerary_id',
        'item_type',
        'destination_id',
        'accommodation_id',
        'food_beverage_id',
        'retail_shop_id',
        'day_number',
        'sequence',
        'time_slot',
        'duration_minutes',
        'travel_time_from_prev',
        'distance_from_prev_km',
        'notes',
        'reason',
        'preference_note',
        'activities',
        'cost_min',
        'cost_max',
        'cost_note',
    ];

    protected function casts(): array
    {
        return [
            // [{time: "8:15 AM – 9:30 AM", name, description}] for destination/free-day blocks.
            'activities' => 'array',
            'cost_min' => 'decimal:2',
            'cost_max' => 'decimal:2',
        ];
    }

    public function itinerary(): BelongsTo
    {
        return $this->belongsTo(Itinerary::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function foodBeverage(): BelongsTo
    {
        return $this->belongsTo(FoodBeverage::class);
    }

    public function retailShop(): BelongsTo
    {
        return $this->belongsTo(RetailShop::class);
    }

    /** Dishes picked at a food stop, each with a quantity. */
    public function dishes(): BelongsToMany
    {
        return $this->belongsToMany(MenuItem::class, 'itinerary_item_dishes')->withPivot('quantity')->withTimestamps();
    }

    /** Souvenirs picked at a souvenir shop stop, each with a quantity. */
    public function souvenirs(): BelongsToMany
    {
        return $this->belongsToMany(RetailProduct::class, 'itinerary_item_souvenirs')->withPivot('quantity')->withTimestamps();
    }
}

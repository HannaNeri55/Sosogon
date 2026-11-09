<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Itinerary extends Model
{
    protected $fillable = [
        'user_id',
        'claim_token',
        'municipality_id',
        'accommodation_id',
        'accommodation_reason',
        'accommodation_note',
        'accommodation_cost_min',
        'accommodation_cost_max',
        'title',
        'num_days',
        'interests',
        'travel_pace',
        'budget',
        'travelers',
        'start_date',
        'end_date',
        'food_preference',
        'accommodation_type',
        'transport_preference',
        'additional_preferences',
        'ai_response_raw',
        'total_distance_km',
        'estimated_cost_min',
        'estimated_cost_max',
    ];

    // Hash of the secret a guest uses to claim this itinerary after logging in.
    protected $hidden = ['claim_token', 'share_token'];

    protected function casts(): array
    {
        return [
            'interests' => 'array',
            'saved_at' => 'datetime',
            'start_date' => 'date',
            'end_date' => 'date',
            'total_distance_km' => 'decimal:2',
            'travelers' => 'integer',
            'estimated_cost_min' => 'decimal:2',
            'estimated_cost_max' => 'decimal:2',
            'accommodation_cost_min' => 'decimal:2',
            'accommodation_cost_max' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The first town the trip was planned for (older code paths and itineraries use this). */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    /** Every town the trip was planned for. */
    public function municipalities(): BelongsToMany
    {
        return $this->belongsToMany(Municipality::class, 'itinerary_municipality')->withTimestamps();
    }

    /**
     * Ids of the trip's towns. Itineraries from before municipalities were tracked fall back to
     * municipality_id, and the oldest ones (no town at all) were always generated for Bulan (id 1).
     *
     * @return array<int, int>
     */
    public function municipalityIds(): array
    {
        $ids = $this->municipalities()->pluck('municipalities.id')->map(fn ($id) => (int) $id)->all();

        return $ids ?: [(int) ($this->municipality_id ?? 1)];
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    /** The rooms booked at the stay; their nightly prices make up the stay cost. */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(AccommodationRoom::class, 'itinerary_rooms')->withTimestamps();
    }

    /** One recommended stay per town of the trip, in town order. */
    public function stays(): HasMany
    {
        return $this->hasMany(ItineraryStay::class)->orderBy('sort');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ItineraryItem::class)->orderBy('day_number')->orderBy('sequence');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FoodBeverage extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'cuisine_type',
        'municipality_id',
        'barangay_id',
        'address',
        'latitude',
        'longitude',
        'price_range',
        'contact_number',
        'website',
        'operating_hours',
        'status',
        'average_rating',
        'total_reviews',
        'view_count',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(FoodBeverageImage::class)->orderBy('sort_order');
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('created_at');
    }

    public function ratings(): MorphMany
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}

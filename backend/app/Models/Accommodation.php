<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Accommodation extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'type',
        'municipality_id',
        'barangay_id',
        'address',
        'latitude',
        'longitude',
        'price_range',
        'contact_number',
        'email',
        'website',
        'operating_hours',
        'amenities',
        'status',
        'average_rating',
        'total_reviews',
        'view_count',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'amenities' => 'array',
            'approved_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
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
        return $this->hasMany(AccommodationImage::class)->orderBy('sort_order');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(AccommodationRoom::class);
    }

    public function ratings(): MorphMany
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}

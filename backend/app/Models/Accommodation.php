<?php

namespace App\Models;

use App\Models\Concerns\HasEstimates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Accommodation extends Model
{
    use HasEstimates;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'dining_details',
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
        'publication_review_status',
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
            'is_sample' => 'boolean',
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

    // Pending and reviewed changes submitted by the business owner.
    public function revisions(): HasMany
    {
        return $this->hasMany(AccommodationRevision::class);
    }

    /** The room types the stay offers, for information (never booked; see AccommodationRoomType). */
    public function roomTypes(): HasMany
    {
        return $this->hasMany(AccommodationRoomType::class)->orderBy('sort_order')->orderBy('id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(AccommodationRoom::class);
    }

    public function ratings(): MorphMany
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    // Publicly listable: approved, and not sample data unless samples are shown (config/listings.php).
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved')
            ->when(! config('listings.show_samples'), fn ($q) => $q->where('is_sample', false));
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}

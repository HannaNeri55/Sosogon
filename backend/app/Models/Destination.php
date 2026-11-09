<?php

namespace App\Models;

use App\Models\Concerns\HasEstimates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Destination extends Model
{
    use HasEstimates;

    protected $fillable = [
        'name',
        'description',
        'dining_details',
        'meals_offered',
        'visitor_guidelines',
        'category_id',
        'municipality_id',
        'barangay_id',
        'address',
        'latitude',
        'longitude',
        'operating_hours',
        'entrance_fee',
        'contact_number',
        'website',
        'status',
        'is_featured',
        'average_rating',
        'total_reviews',
        'view_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'meals_offered' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'entrance_fee' => 'decimal:2',
            'average_rating' => 'decimal:2',
            'is_featured' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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
        return $this->hasMany(DestinationImage::class)->orderBy('sort_order');
    }

    public function fees(): HasMany
    {
        return $this->hasMany(DestinationFee::class)->orderBy('sort_order');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(DestinationRate::class)->orderBy('sort_order');
    }

    public function amenities(): HasMany
    {
        return $this->hasMany(DestinationAmenity::class)->orderBy('id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    public function primaryImage(): HasMany
    {
        return $this->hasMany(DestinationImage::class)->where('is_primary', true);
    }

    public function ratings(): MorphMany
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function recalculateRating(): void
    {
        $this->average_rating = $this->ratings()->visible()->avg('score') ?? 0;
        $this->total_reviews = $this->ratings()->visible()->count();
        $this->save();
    }
}

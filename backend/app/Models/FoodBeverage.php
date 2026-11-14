<?php

namespace App\Models;

use App\Models\Concerns\HasEstimates;
use App\Models\Concerns\KeepsSubmissionMunicipality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FoodBeverage extends Model
{
    use HasEstimates, KeepsSubmissionMunicipality;

    /** business_submissions.type for this kind of listing. */
    public const SUBMISSION_TYPE = 'food_beverage';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'meals_served',
        'specialties',
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
            'meals_served' => 'array',
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
        return $this->hasMany(FoodBeverageImage::class)->orderBy('sort_order');
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('created_at');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(FoodBeverageRevision::class);
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
}

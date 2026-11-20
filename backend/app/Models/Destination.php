<?php

namespace App\Models;

use App\Models\Concerns\HasEstimates;
use App\Models\Concerns\TracksInfoUpdates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Destination extends Model
{
    use HasEstimates, TracksInfoUpdates;

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
        'tourism_priority',
        'setting',
        // The same place's accommodation listing (a resort listed both ways), set in Manage Destinations.
        'accommodation_id',
        'average_rating',
        'total_reviews',
        'view_count',
        'created_by',
    ];

    /**
     * Experience review fields are admin-only: they are set by AdminDestinationController (never
     * mass-assigned) and stay out of the public API until tags are shown publicly.
     */
    protected $hidden = ['experiences_reviewed_at', 'experiences_reviewed_by'];

    /** How worth a trip a place is, set by the tourism office (empty until classified). */
    public const PRIORITIES = ['iconic', 'popular', 'recommended', 'hidden_gem'];

    /** Meals a destination may serve on site (meals_offered); the trip planner serves lunch and dinner there. */
    public const MEALS = ['breakfast', 'lunch', 'merienda', 'dinner'];

    /** Where a visit happens: indoors, in the open air, or partly under cover. */
    public const SETTINGS = ['indoor', 'outdoor', 'mixed'];

    /** The columns visitors see; a real change to one while public is a "Last Updated" (TracksInfoUpdates). */
    protected function infoFields(): array
    {
        return [
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
            'accommodation_id',
        ];
    }

    public static function isPublicStatus(?string $status): bool
    {
        return $status === 'published';
    }

    protected function casts(): array
    {
        return [
            'info_updated_at' => 'datetime',
            'meals_offered' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'entrance_fee' => 'decimal:2',
            'average_rating' => 'decimal:2',
            'is_featured' => 'boolean',
            'experiences_reviewed_at' => 'datetime',
        ];
    }

    /** What visitors can experience here, from the controlled vocabulary (see ExperienceTag). */
    public function experienceTags(): BelongsToMany
    {
        return $this->belongsToMany(ExperienceTag::class, 'destination_experience_tag')
            ->withTimestamps()
            ->orderBy('experience_tags.sort_order');
    }

    /** The admin who last confirmed this destination's experience tags. */
    public function experiencesReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'experiences_reviewed_by');
    }

    /** Whether an admin has confirmed the experience tags (only then may they be relied on). */
    public function experiencesReviewed(): bool
    {
        return $this->experiences_reviewed_at !== null;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** This place's own accommodation listing: a resort that is visited for the day and also stayed at. */
    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
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

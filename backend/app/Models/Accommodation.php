<?php

namespace App\Models;

use App\Models\Concerns\HasEstimates;
use App\Models\Concerns\KeepsSubmissionMunicipality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Accommodation extends Model
{
    use HasEstimates, KeepsSubmissionMunicipality;

    /** business_submissions.type for this kind of listing. */
    public const SUBMISSION_TYPE = 'accommodation';

    /** Whether the stay rents units, the entire property, or both. Empty means not stated. */
    public const ARRANGEMENTS = ['units', 'entire_property', 'both'];

    public const CLASSIFICATIONS = ['Hotel', 'Resort', 'Beach Resort', 'Inn', 'Guesthouse', 'Homestay', 'Villa', 'Cottage'];

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'dining_details',
        'type',
        'offering_arrangement',
        'classifications',
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
        'check_in_time',
        'check_out_time',
        'amenities',
        'status',
        'publication_review_status',
        'average_rating',
        'total_reviews',
        'view_count',
        'approved_at',
        'approved_by',
    ];

    protected static function booted(): void
    {
        // Filters match the main type or any category, so the main type is kept as one of the
        // categories: a stay unticked from "Resort" then stops turning up under the Resort filter.
        static::saving(function (Accommodation $stay) {
            $categories = array_values(array_intersect(self::CLASSIFICATIONS, $stay->classifications ?? []));
            if ($categories !== [] && ! in_array($stay->type, $categories, true)) {
                $stay->type = $categories[0];
            }
        });
    }

    protected function casts(): array
    {
        return [
            'amenities' => 'array',
            'classifications' => 'array',
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

    /** Legacy display-only room types, kept for reference; a stay's options are rooms(). */
    public function roomTypes(): HasMany
    {
        return $this->hasMany(AccommodationRoomType::class)->orderBy('sort_order')->orderBy('id');
    }

    /** The stay's accommodation options: rooms, villas, cottages or the entire property. */
    public function rooms(): HasMany
    {
        return $this->hasMany(AccommodationRoom::class)->orderBy('sort_order')->orderBy('id');
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

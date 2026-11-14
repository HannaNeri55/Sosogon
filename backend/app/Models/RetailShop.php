<?php

namespace App\Models;

use App\Models\Concerns\HasEstimates;
use App\Models\Concerns\KeepsSubmissionMunicipality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class RetailShop extends Model
{
    use HasEstimates, KeepsSubmissionMunicipality;

    /** business_submissions.type for this kind of listing. */
    public const SUBMISSION_TYPE = 'retail_product';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'product_categories',
        'municipality_id',
        'barangay_id',
        'address',
        'latitude',
        'longitude',
        'contact_number',
        'operating_hours',
        'status',
        'publication_review_status',
        'admin_notes',
        'view_count',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'product_categories' => 'array',
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
        return $this->hasMany(RetailShopImage::class)->orderBy('sort_order');
    }

    public function products(): HasMany
    {
        return $this->hasMany(RetailProduct::class)->orderBy('created_at');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(RetailShopRevision::class);
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

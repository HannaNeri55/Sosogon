<?php

namespace App\Models;

use App\Models\Concerns\HasPendingChanges;
use App\Models\Concerns\UpdatesListingInfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class RetailProduct extends Model
{
    use HasPendingChanges, UpdatesListingInfo;

    protected $fillable = [
        'user_id',
        'retail_shop_id',
        'name',
        'description',
        'product_type',
        'municipality_id',
        'barangay_id',
        'address',
        'latitude',
        'longitude',
        'price',
        'contact_number',
        'website',
        'status',
        'admin_notes',
        'view_count',
        'approved_at',
        'approved_by',
    ];

    /** The published details that are part of the listing's information (UpdatesListingInfo). */
    protected function infoFields(): array
    {
        return [
            'name',
            'description',
            'product_type',
            'price',
        ];
    }

    public function infoListing(): ?Model
    {
        return $this->shop;
    }

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'approved_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(RetailShop::class, 'retail_shop_id');
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
        return $this->hasMany(RetailProductImage::class)->orderBy('sort_order');
    }

    public function ratings(): MorphMany
    {
        return $this->morphMany(Rating::class, 'rateable');
    }

    // Publicly listable: approved, and its shop is publicly listed too (approved, and not a sample
    // shop unless samples are shown: RetailShop::scopeApproved). A product of a shop still in
    // drafts or under review stays hidden with its shop.
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved')
            ->where(fn ($q) => $q
                ->whereNull('retail_shop_id')
                ->orWhereHas('shop', fn ($shop) => $shop->approved()));
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}

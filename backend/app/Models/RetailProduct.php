<?php

namespace App\Models;

use App\Models\Concerns\HasPendingChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class RetailProduct extends Model
{
    use HasPendingChanges;

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

    // Publicly listable: approved, and not under a sample shop unless samples are shown (config/listings.php).
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved')
            ->when(! config('listings.show_samples'), fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('retail_shop_id')
                ->orWhereHas('shop', fn ($shop) => $shop->where('is_sample', false))));
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}

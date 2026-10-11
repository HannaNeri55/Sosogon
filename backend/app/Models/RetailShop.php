<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class RetailShop extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'municipality_id',
        'barangay_id',
        'address',
        'latitude',
        'longitude',
        'contact_number',
        'operating_hours',
        'status',
        'admin_notes',
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
        return $this->hasMany(RetailShopImage::class)->orderBy('sort_order');
    }

    public function products(): HasMany
    {
        return $this->hasMany(RetailProduct::class)->orderBy('created_at');
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

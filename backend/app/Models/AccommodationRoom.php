<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccommodationRoom extends Model
{
    protected $fillable = [
        'accommodation_id',
        'room_number',
        'category',
        'price',
        'description',
        'occupancy_status',
        'status',
        'admin_notes',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'price' => 'decimal:2',
        ];
    }

    public function accommodation(): BelongsTo
    {
        return $this->belongsTo(Accommodation::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(AccommodationRoomImage::class, 'room_id')->orderBy('sort_order');
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

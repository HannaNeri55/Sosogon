<?php

namespace App\Models;

use App\Models\Concerns\HasPendingChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One accommodation option of a stay: a room or room type, villa, cottage or other unit, or the
 * entire property. Information only: SOSOGON doesn't track availability or take bookings, so
 * occupancy_status is never read. room_number is the legacy label, kept equal to name.
 */
class AccommodationRoom extends Model
{
    use HasPendingChanges;

    public const UNIT_TYPES = ['room', 'villa', 'cottage', 'cabin', 'guest_house', 'entire_property', 'other'];

    public const PRICING_MODES = ['indicative', 'varies', 'contact'];

    protected $hidden = ['occupancy_status', 'source_url', 'legacy_room_type_id'];

    protected $fillable = [
        'accommodation_id',
        'unit_type',
        'name',
        'unit_count',
        'room_number',
        'category',
        'price',
        'price_max',
        'price_note',
        'pricing_mode',
        'description',
        'notes',
        'max_guests',
        'bedrooms',
        'bed_configuration',
        'size_sqm',
        'breakfast_included',
        'sort_order',
        'room_features',
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
            'price_max' => 'decimal:2',
            'size_sqm' => 'decimal:2',
            'room_features' => 'array',
            'breakfast_included' => 'boolean',
            'unit_count' => 'integer',
            'max_guests' => 'integer',
            'bedrooms' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $room) {
            if ($room->isDirty('name') && filled($room->name)) {
                $room->room_number = $room->name;
            } elseif ($room->isDirty('room_number') || blank($room->name)) {
                $room->name = $room->room_number;
            }
        });
    }

    public function isEntireProperty(): bool
    {
        return $this->unit_type === 'entire_property';
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

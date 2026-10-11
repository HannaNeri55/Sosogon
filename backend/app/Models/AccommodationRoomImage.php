<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccommodationRoomImage extends Model
{
    protected $fillable = ['room_id', 'image_path', 'thumbnail_path', 'is_primary', 'sort_order'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(AccommodationRoom::class, 'room_id');
    }
}

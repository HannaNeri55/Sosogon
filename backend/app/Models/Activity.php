<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    protected $fillable = [
        'destination_id',
        'name',
        'description',
        'type',
        'best_time_to_visit',
        'what_to_wear',
        'things_to_do',
        'image_path',
        'thumbnail_path',
    ];

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DestinationAmenity extends Model
{
    protected $fillable = [
        'destination_id',
        'name',
    ];

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }
}

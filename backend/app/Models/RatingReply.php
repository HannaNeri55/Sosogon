<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RatingReply extends Model
{
    protected $fillable = ['rating_id', 'user_id', 'body'];

    public function rating(): BelongsTo
    {
        return $this->belongsTo(Rating::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Rating extends Model
{
    // is_demo is deliberately not fillable: only seeders mark demo reviews.
    protected $fillable = ['user_id', 'rateable_type', 'rateable_id', 'score', 'comment'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean'];
    }

    /** Reviews the public may see and that count toward public averages. */
    public function scopeVisible(Builder $query): Builder
    {
        return config('reviews.show_demo') ? $query : $query->where('is_demo', false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rateable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The listing owner's response, stored separately from the review comment. */
    public function reply(): HasOne
    {
        return $this->hasOne(RatingReply::class);
    }
}

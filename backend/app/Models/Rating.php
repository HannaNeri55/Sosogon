<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Rating extends Model
{
    // is_demo is deliberately not fillable: only seeders mark demo reviews.
    protected $fillable = ['user_id', 'rateable_type', 'rateable_id', 'score', 'comment'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'hidden_at' => 'datetime'];
    }

    /**
     * Reviews the public may see and that count toward public averages: not hidden by the Tourism
     * Office after a report (see ReviewReport).
     */
    public function scopeVisible(Builder $query): Builder
    {
        $query->whereNull($query->qualifyColumn('hidden_at'));

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

    /** Owners' reports about this review, and the Tourism Office's decisions. */
    public function reports(): HasMany
    {
        return $this->hasMany(ReviewReport::class);
    }

    public function latestReport(): HasOne
    {
        return $this->hasOne(ReviewReport::class)->latestOfMany();
    }
}

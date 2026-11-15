<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One entry of the controlled experience vocabulary (beach, waterfall, heritage…): what visitors can
 * experience at a destination. Seeded by ExperienceTagSeeder; admins choose from it, never add to it.
 */
class ExperienceTag extends Model
{
    protected $fillable = ['slug', 'name', 'group', 'description', 'sort_order'];

    public function destinations(): BelongsToMany
    {
        return $this->belongsToMany(Destination::class, 'destination_experience_tag')->withTimestamps();
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}

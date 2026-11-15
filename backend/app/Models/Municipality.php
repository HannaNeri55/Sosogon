<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Municipality extends Model
{
    protected $fillable = [
        'name', 'description', 'latitude', 'longitude',
        // About-page profile, kept by provincial admins
        'contact_number', 'email', 'address', 'other_contacts', 'geography', 'boundaries', 'history',
        'name_origin', 'sources', 'image_path', 'thumbnail_path', 'profile_updated_by',
    ];

    // The profile is fetched through its own endpoints; lists of towns (filters, forms) stay small.
    protected $hidden = [
        'contact_number', 'email', 'address', 'other_contacts', 'geography', 'boundaries', 'history',
        'name_origin', 'sources', 'image_path', 'thumbnail_path', 'profile_updated_by',
    ];

    public function barangays(): HasMany
    {
        return $this->hasMany(Barangay::class);
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class);
    }

    public function profileEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'profile_updated_by');
    }

    /**
     * The About page's photo of this town: the one a provincial admin uploaded, otherwise the
     * main photo of its most prominent published destination (a public place before a private
     * resort, then featured, then most viewed), so every card shows a real place in that town.
     * Null when the town has neither.
     *
     * @return array{image_path: string, thumbnail_path: ?string, caption: ?string, credit: ?string, source_url: ?string, uploaded: bool}|null
     */
    public function representativePhoto(): ?array
    {
        if ($this->image_path) {
            return ['image_path' => $this->image_path, 'thumbnail_path' => $this->thumbnail_path, 'caption' => null, 'credit' => null, 'source_url' => null, 'uploaded' => true];
        }

        $destination = $this->destinations()->published()->whereHas('images')
            ->with(['images' => fn ($query) => $query->reorder()->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id')])
            ->orderByRaw("coalesce((select name from categories where categories.id = destinations.category_id), '') = 'Resort'")
            ->orderByDesc('is_featured')->orderByDesc('view_count')->orderBy('id')
            ->first();
        $image = $destination?->images->first();

        return $image ? [
            'image_path' => $image->image_path,
            'thumbnail_path' => $image->thumbnail_path,
            'caption' => $destination->name,
            'credit' => $image->credit,
            'source_url' => $image->source_url,
            'uploaded' => false,
        ] : null;
    }

    /** "Sorsogon City" is the province's one component city; the rest are municipalities. */
    public function lguLabel(): string
    {
        return $this->lgu_type === 'city' ? 'City' : 'Municipality';
    }
}

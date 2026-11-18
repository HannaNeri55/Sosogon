<?php

namespace App\Models\Concerns;

use App\Support\InfoValue;

/**
 * "Last Updated" on a listing's public page: info_updated_at is set whenever the information
 * visitors see is published or changes while public, on whatever path that happens (approval of
 * a new listing or of an owner's edit, a minor correction published directly, an admin's edit).
 * Owners' edits waiting for approval live in revisions or pending_changes, not on the listing, so
 * they don't count; neither do page views, ratings, photos or other columns not in infoFields().
 * Changes to the listing's rooms, menu items, products or fees count through markInfoUpdated().
 *
 * The model defines infoFields() (its public information columns) and isPublicStatus().
 */
trait TracksInfoUpdates
{
    abstract protected function infoFields(): array;

    abstract public static function isPublicStatus(?string $status): bool;

    protected static function bootTracksInfoUpdates(): void
    {
        static::saving(function (self $listing) {
            if (! static::isPublicStatus($listing->status)) {
                return;
            }
            $published = ! $listing->exists || ! static::isPublicStatus($listing->getOriginal('status'));
            if ($published || $listing->infoChanged()) {
                $listing->info_updated_at = now();
            }
        });
    }

    /** Records an update of information kept outside the listing's row (rooms, menu, products, fees). */
    public function markInfoUpdated(): void
    {
        if ($this->exists && static::isPublicStatus($this->status)) {
            $this->forceFill(['info_updated_at' => now()])->saveQuietly();
        }
    }

    /** Whether a public information column really changes: not just its spacing or number format. */
    protected function infoChanged(): bool
    {
        foreach (array_intersect(array_keys($this->getDirty()), $this->infoFields()) as $field) {
            if (InfoValue::differs($this->getOriginal($field), $this->getAttribute($field))) {
                return true;
            }
        }

        return false;
    }
}

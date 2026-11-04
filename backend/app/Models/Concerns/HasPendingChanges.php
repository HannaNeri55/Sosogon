<?php

namespace App\Models\Concerns;

/**
 * Rooms, menu items and retail products: proposed changes to an APPROVED item
 * are held in `pending_changes` while the approved values stay public.
 * Hidden from serialization by default so public endpoints never expose them;
 * owner and admin endpoints call makeVisible('pending_changes').
 *
 * Shape: {values: {...}, base: {...}, new_images: [{image_path, thumbnail_path}],
 *         remove_image_ids: [...], status: pending|rejected, notes, submitted_at}
 */
trait HasPendingChanges
{
    public function initializeHasPendingChanges(): void
    {
        $this->mergeCasts(['pending_changes' => 'array']);
        $this->hidden[] = 'pending_changes';
    }

    public function hasPendingChanges(): bool
    {
        return ($this->pending_changes['status'] ?? null) === 'pending';
    }
}

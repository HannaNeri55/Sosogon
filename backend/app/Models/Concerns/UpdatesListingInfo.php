<?php

namespace App\Models\Concerns;

use App\Support\InfoValue;
use Illuminate\Database\Eloquent\Model;

/**
 * For a listing's rooms, menu items and products: their published details are part of the
 * listing's information, so when one is approved, withdrawn, deleted, or its details change while
 * approved, the listing's "Last Updated" moves (TracksInfoUpdates::markInfoUpdated()). Changes
 * still waiting for approval (pending_changes, or an item not yet approved) don't count.
 *
 * The model defines infoFields() and infoListing() (the listing it belongs to).
 */
trait UpdatesListingInfo
{
    abstract protected function infoFields(): array;

    abstract public function infoListing(): ?Model;

    protected static function bootUpdatesListingInfo(): void
    {
        static::saved(function (self $item) {
            $wasPublic = $item->wasRecentlyCreated ? false : $item->getOriginal('status') === 'approved';
            $isPublic = $item->status === 'approved';
            $changed = $wasPublic && $isPublic && collect($item->getChanges())->keys()->intersect($item->infoFields())
                ->contains(fn ($field) => InfoValue::differs($item->getOriginal($field), $item->getAttribute($field)));
            if ($wasPublic !== $isPublic || $changed) {
                $item->infoListing()?->markInfoUpdated();
            }
        });

        static::deleted(function (self $item) {
            if ($item->status === 'approved') {
                $item->infoListing()?->markInfoUpdated();
            }
        });
    }
}

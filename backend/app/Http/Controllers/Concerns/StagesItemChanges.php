<?php

namespace App\Http\Controllers\Concerns;

use App\Services\ImageOptimizationService;
use App\Services\ListingEditClassifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Owner edits to an APPROVED room, menu item or retail product. A declared and
 * verified minor correction is published directly; anything else is merged into
 * the item's pending_changes for admin review while the approved values stay
 * public. Items that aren't approved yet are edited directly (they aren't public).
 */
trait StagesItemChanges
{
    use AppliesMinorEdits;

    /**
     * @param  array  $values  owner-editable values sent in this request
     * @param  array  $newImages  already-stored staged images [{image_path, thumbnail_path}]
     * @param  bool  $singleImage  the item has one image: a new one replaces any staged one
     * @return string 'minor' | 'staged' | 'unchanged'
     */
    protected function stageItemChanges(Model $item, array $values, Request $request, array $newImages = [], array $removeImageIds = [], bool $singleImage = false): string
    {
        $hasImageChanges = $newImages || $removeImageIds;
        if (! $hasImageChanges && $this->applyMinorEdit($item, $values, $request, $item->hasPendingChanges())) {
            return 'minor';
        }

        $classifier = app(ListingEditClassifier::class);
        $pending = $item->pending_changes ?? [];
        $base = $pending['base'] ?? [];
        $proposed = $pending['values'] ?? [];

        foreach ($values as $field => $value) {
            $base[$field] ??= $item->getAttribute($field);
            $proposed[$field] = $value;
        }
        // Values set back to what is already live are no longer a proposed change.
        foreach ($proposed as $field => $value) {
            if ($classifier->changedFields([$field => $item->getAttribute($field)], [$field => $value]) === []) {
                unset($proposed[$field], $base[$field]);
            }
        }

        $staged = $pending['new_images'] ?? [];
        if ($newImages && $singleImage) {
            $this->deleteStagedImages($staged);
            $staged = [];
        }
        $staged = array_merge($staged, $newImages);
        $removals = array_values(array_unique(array_merge($pending['remove_image_ids'] ?? [], $removeImageIds)));

        if (! $proposed && ! $staged && ! $removals) {
            $item->forceFill(['pending_changes' => null])->save();

            return $hasImageChanges || $pending ? 'staged' : 'unchanged';
        }

        $item->forceFill(['pending_changes' => [
            'values' => $proposed,
            'base' => $base,
            'new_images' => $staged,
            'remove_image_ids' => $removals,
            'status' => 'pending',
            'notes' => null,
            'submitted_at' => now()->toIso8601String(),
        ]])->save();

        return 'staged';
    }

    protected function deleteStagedImages(array $staged): void
    {
        $images = app(ImageOptimizationService::class);
        foreach ($staged as $image) {
            $images->delete($image['image_path'] ?? null, $image['thumbnail_path'] ?? null);
        }
    }

    protected function stagedMessage(string $result, string $noun, Request $request): string
    {
        return match ($result) {
            'minor' => "Minor correction published. It was recorded for the Tourism Office.",
            'unchanged' => "No changes to save.",
            default => "Changes saved for Tourism Office review. The approved {$noun} stays visible to tourists until they are approved.".$this->minorEditRejectedNote($request),
        };
    }
}

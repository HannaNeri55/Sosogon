<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Lets an owner arrange saved AND newly selected photos in one save:
 *   photo_order[]      every remaining photo, in order: "e:<id>" (saved) or "n:<i>" (i-th upload in this request)
 *   cover              the cover photo, same token format
 *   remove_image_ids[] saved photos to delete
 * All optional; without them new photos are appended and the cover is kept
 * (falling back to the first photo). Validated before any file is stored.
 */
trait PlansPhotoArrangement
{
    protected function hasPhotoArrangement(Request $request): bool
    {
        return $request->filled('photo_order') || $request->filled('cover') || $request->filled('remove_image_ids');
    }

    /**
     * @param  string[]  $currentTokens  saved photos in their current order ("e:<id>")
     * @return array{order: string[], cover: ?string, removed: string[]}
     */
    protected function planPhotoArrangement(array $currentTokens, ?string $currentCover, int $uploadCount, Request $request): array
    {
        $input = validator($request->only(['photo_order', 'cover', 'remove_image_ids']), [
            'photo_order' => 'sometimes|array|max:200',
            'photo_order.*' => ['string', 'distinct', 'regex:/^(e:-?\d+|n:\d+)$/'],
            'cover' => ['sometimes', 'nullable', 'string', 'regex:/^(e:-?\d+|n:\d+)$/'],
            'remove_image_ids' => 'sometimes|array|max:200',
            'remove_image_ids.*' => 'integer|distinct',
        ])->validate();

        $removed = array_map(fn ($id) => 'e:'.(int) $id, $input['remove_image_ids'] ?? []);
        if ($unknown = array_diff($removed, $currentTokens)) {
            throw ValidationException::withMessages(['remove_image_ids' => 'Only this listing\'s own photos can be removed ('.implode(', ', $unknown).').']);
        }

        $remaining = array_values(array_diff($currentTokens, $removed));
        for ($i = 0; $i < $uploadCount; $i++) {
            $remaining[] = "n:{$i}";
        }

        $order = $remaining;
        if (isset($input['photo_order'])) {
            $requested = array_values($input['photo_order']);
            $sortedRequested = $requested;
            $sortedRemaining = $remaining;
            sort($sortedRequested);
            sort($sortedRemaining);
            if ($sortedRequested !== $sortedRemaining) {
                throw ValidationException::withMessages(['photo_order' => 'The photo order must list every remaining photo exactly once.']);
            }
            $order = $requested;
        }

        $cover = $input['cover'] ?? null;
        if ($cover !== null && ! in_array($cover, $order, true)) {
            throw ValidationException::withMessages(['cover' => 'The cover must be one of the listing\'s photos.']);
        }
        if ($cover === null) {
            $cover = in_array($currentCover, $order, true) ? $currentCover : ($order[0] ?? null);
        }

        return ['order' => $order, 'cover' => $cover, 'removed' => $removed];
    }
}

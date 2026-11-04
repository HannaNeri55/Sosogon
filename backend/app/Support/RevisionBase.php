<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Protects published listings from stale revisions.
 *
 * When an owner starts a revision we keep a snapshot of the published values
 * it may change (fields + gallery). At approval, compare that snapshot with
 * the live listing:
 *  - fields/gallery the owner did NOT change take the current live value
 *    (so newer approved data isn't overwritten);
 *  - anything changed both by the owner and on the live listing since is a
 *    conflict: the revision is not applied.
 */
class RevisionBase
{
    /** Published values + gallery in the same shape the revision stores them. */
    public static function snapshot(Model $listing, array $fields): array
    {
        return ['data' => self::data($listing, $fields), 'gallery' => self::gallery($listing)];
    }

    /** Published gallery, ordered, as revision entries ({key: existing:<id>, paths, is_primary}). */
    public static function gallery(Model $listing): array
    {
        return $listing->images()->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($image) => [
                'key' => 'existing:'.$image->id,
                'image_path' => $image->image_path,
                'thumbnail_path' => $image->thumbnail_path,
                'is_primary' => (bool) $image->is_primary,
            ])->all();
    }

    /**
     * @return array{data: array, gallery: array, conflicts: string[]}
     */
    public static function rebase(?array $base, Model $listing, array $fields, array $proposedData, array $proposedGallery): array
    {
        if (! $base) {
            // Revisions created before snapshots existed: previous behaviour.
            return ['data' => $proposedData, 'gallery' => $proposedGallery, 'conflicts' => []];
        }

        $live = self::snapshot($listing, $fields);
        $data = $proposedData;
        $conflicts = [];

        foreach ($fields as $field) {
            $ownerChanged = self::same($base['data'][$field] ?? null, $proposedData[$field] ?? null) === false;
            $liveChanged = self::same($base['data'][$field] ?? null, $live['data'][$field] ?? null) === false;

            if ($liveChanged && ! $ownerChanged) {
                $data[$field] = $live['data'][$field] ?? null;
            } elseif ($liveChanged && $ownerChanged && ! self::same($proposedData[$field] ?? null, $live['data'][$field] ?? null)) {
                $conflicts[] = $field;
            }
        }

        $gallery = $proposedGallery;
        $ownerGallery = ! self::same(self::galleryShape($base['gallery'] ?? []), self::galleryShape($proposedGallery));
        $liveGallery = ! self::same(self::galleryShape($base['gallery'] ?? []), self::galleryShape($live['gallery']));
        if ($liveGallery && ! $ownerGallery) {
            $gallery = $live['gallery'];
        } elseif ($liveGallery && $ownerGallery) {
            $conflicts[] = 'photos';
        }

        return ['data' => $data, 'gallery' => $gallery, 'conflicts' => $conflicts];
    }

    /**
     * Rebases $revision against the live listing. Returns null when it can be
     * applied (data/gallery updated in place), or a 409 response after handing
     * the revision back to the owner when the same values changed on both sides.
     */
    public static function prepareForApproval(Model $revision, Model $listing, int $reviewerId, array &$data, array &$gallery): ?\Illuminate\Http\JsonResponse
    {
        $base = $revision->base_snapshot;
        $rebased = self::rebase($base, $listing, array_keys($base['data'] ?? []), $data, $gallery);

        if ($rebased['conflicts'] === []) {
            $data = $rebased['data'];
            $gallery = $rebased['gallery'];

            return null;
        }

        $fields = implode(', ', $rebased['conflicts']);
        $revision->forceFill([
            'status' => 'needs_revision',
            'admin_notes' => "The published listing changed after these edits were started ({$fields}). Please review those fields against the current listing and resubmit.",
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            // Keep the owner's own changes, adopt everything else from the live
            // listing, and re-base on it so the corrected resubmission can be approved.
            'proposed_data' => $rebased['data'],
            'proposed_gallery' => $rebased['gallery'],
            'base_snapshot' => self::snapshot($listing, array_keys($base['data'] ?? [])),
        ])->save();

        return response()->json([
            'message' => "Not applied: {$fields} changed on the published listing after the owner started these edits. The revision was returned to the owner to review.",
            'conflicts' => $rebased['conflicts'],
        ], 409);
    }

    private static function data(Model $listing, array $fields): array
    {
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $listing->getAttribute($field);
        }

        return $data;
    }

    private static function galleryShape(array $gallery): array
    {
        return array_map(fn ($e) => [$e['key'] ?? null, ! empty($e['is_primary'])], $gallery);
    }

    private static function same(mixed $a, mixed $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    private static function normalize(mixed $value): string
    {
        if (is_array($value)) {
            return json_encode(array_map(fn ($v) => is_array($v) ? $v : self::normalize($v), $value));
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_numeric($value)) {
            return (string) (0 + $value);
        }

        return trim((string) ($value ?? ''));
    }
}

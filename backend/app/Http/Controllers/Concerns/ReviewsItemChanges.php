<?php

namespace App\Http\Controllers\Concerns;

use App\Services\ImageOptimizationService;
use App\Services\ListingEditClassifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin review of proposed changes to an approved room/menu item/product.
 * Approval applies exactly the version the admin looked at (changes_hash) and
 * refuses if the live values changed since the owner proposed them.
 * Rejection keeps the approved version and records a reason for the owner.
 */
trait ReviewsItemChanges
{
    public static function changesHash(?array $pending): ?string
    {
        return $pending ? md5(json_encode($pending)) : null;
    }

    /**
     * @param  callable(Model $item, array $pending): array  $applyImages  applies staged images; returns [image_path, thumbnail_path] pairs to delete after commit
     */
    protected function approveItemChanges(Request $request, string $modelClass, int $id, callable $applyImages): JsonResponse
    {
        $request->validate(['changes_hash' => 'required|string', 'notes' => 'nullable|string|max:1000']);

        $deleteAfter = [];
        $result = DB::transaction(function () use ($request, $modelClass, $id, $applyImages, &$deleteAfter) {
            /** @var Model $item */
            $item = $modelClass::whereKey($id)->lockForUpdate()->firstOrFail();
            $pending = $item->pending_changes;

            if (($pending['status'] ?? null) !== 'pending') {
                return response()->json(['message' => 'There are no pending changes to approve.'], 409);
            }
            if (! hash_equals(self::changesHash($pending), (string) $request->input('changes_hash'))) {
                return response()->json(['message' => 'The owner updated these changes after you opened them. Reload and review the latest version.'], 409);
            }

            $classifier = app(ListingEditClassifier::class);
            $stale = [];
            foreach ($pending['base'] ?? [] as $field => $baseValue) {
                if ($classifier->changedFields([$field => $baseValue], [$field => $item->getAttribute($field)]) !== []) {
                    $stale[] = $field;
                }
            }
            if ($stale) {
                $fields = implode(', ', $stale);
                $item->forceFill(['pending_changes' => array_merge($pending, [
                    'status' => 'rejected',
                    'notes' => "The approved values changed after these edits were proposed ({$fields}). Please review and resubmit.",
                    'base' => array_merge($pending['base'], array_combine($stale, array_map(fn ($f) => $item->getAttribute($f), $stale))),
                ])])->save();

                return response()->json(['message' => "Not applied: {$fields} changed after the owner proposed these edits. Returned to the owner.", 'conflicts' => $stale], 409);
            }

            $item->update($pending['values'] ?? []);
            $deleteAfter = $applyImages($item, $pending);
            $item->forceFill([
                'pending_changes' => null,
                'approved_at' => now(),
                'approved_by' => $request->user()->id,
                'admin_notes' => $request->input('notes'),
            ])->save();

            return null;
        });

        if ($result) {
            return $result;
        }

        $images = app(ImageOptimizationService::class);
        foreach ($deleteAfter as [$path, $thumb]) {
            $images->delete($path, $thumb);
        }

        return response()->json(['message' => 'Changes approved and published.']);
    }

    protected function rejectItemChanges(Request $request, string $modelClass, int $id): JsonResponse
    {
        $validated = $request->validate(['notes' => 'required|string|min:5|max:1000']);

        return DB::transaction(function () use ($modelClass, $id, $validated) {
            $item = $modelClass::whereKey($id)->lockForUpdate()->firstOrFail();
            $pending = $item->pending_changes;

            if (($pending['status'] ?? null) !== 'pending') {
                return response()->json(['message' => 'There are no pending changes to reject.'], 409);
            }

            // The approved version stays as it is; the owner sees the reason and can correct and resubmit.
            $item->forceFill(['pending_changes' => array_merge($pending, ['status' => 'rejected', 'notes' => trim($validated['notes'])])])->save();

            return response()->json(['message' => 'Changes rejected. The approved version is unchanged.']);
        });
    }

    /** Admin list rows: expose the proposal and the hash the approve call must echo back. */
    protected function withPendingChanges($items)
    {
        foreach ($items as $item) {
            $item->makeVisible('pending_changes');
            $item->setAttribute('pending_changes_hash', self::changesHash($item->pending_changes));
        }

        return $items;
    }
}

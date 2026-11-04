<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ActivityLog;
use App\Services\ListingEditClassifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Publishes an owner's edit to an approved listing/item directly only when it is
 * a declared and verified minor correction (see ListingEditClassifier). Every
 * such edit is logged with its before/after values for admin audit.
 */
trait AppliesMinorEdits
{
    /**
     * @param  array  $proposed  the owner-editable values sent in this request
     * @return bool true when the edit was published directly
     */
    protected function applyMinorEdit(Model $model, array $proposed, Request $request, bool $blocked = false): bool
    {
        if ($blocked || ! $request->boolean('minor_edit')) {
            return false;
        }

        $classifier = app(ListingEditClassifier::class);
        $current = [];
        foreach (array_keys($proposed) as $field) {
            $current[$field] = $model->getAttribute($field);
        }

        if (! $classifier->isMinor($current, $proposed, true)) {
            return false;
        }

        $changed = $classifier->changedFields($current, $proposed);
        $before = array_intersect_key($current, array_flip($changed));
        $after = array_intersect_key($proposed, array_flip($changed));

        $model->update($after);

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => 'minor_listing_edit',
            'description' => json_encode(['before' => $before, 'after' => $after], JSON_UNESCAPED_UNICODE),
            'model_type' => $model::class,
            'model_id' => $model->getKey(),
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return true;
    }

    protected function minorEditRejectedNote(Request $request): string
    {
        return $request->boolean('minor_edit')
            ? ' These changes go beyond a spelling or formatting correction, so they need Tourism Office review.'
            : '';
    }
}

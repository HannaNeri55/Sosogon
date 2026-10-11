<?php

namespace Database\Seeders;

use App\Models\Activity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copies real activity photos onto activities that are still on the generated
 * placeholder, matched by activity name. Names carry their destination
 * ("Sunset Watching at Star Beach Resort"), so matching is done on the part
 * before " at / near / in / ..." — e.g. "sunset watching".
 *
 * When several activities share a name, sources are cycled so the copies
 * don't all show the same picture. Activities with no same-named photo
 * (e.g. "Heritage Walk", "Attend Mass") are left on the placeholder.
 */
class CopyActivityImageByNameSeeder extends Seeder
{
    public function run(): void
    {
        $activities = Activity::orderBy('id')->get();

        $sourcesByName = $activities
            ->filter(fn ($a) => $this->hasRealPhoto($a))
            ->groupBy(fn ($a) => $this->baseName($a->name));

        $nextIndex = [];
        $copied = 0;
        $skipped = 0;

        foreach ($activities as $activity) {
            if ($this->hasRealPhoto($activity)) {
                continue;
            }

            $name = $this->baseName($activity->name);
            $sources = $sourcesByName->get($name);
            if (! $sources) {
                $skipped++;
                continue;
            }

            $index = $nextIndex[$name] ?? 0;
            $nextIndex[$name] = $index + 1;

            if ($this->copyImage($sources[$index % $sources->count()], $activity)) {
                $copied++;
            } else {
                $skipped++;
            }
        }

        $this->command?->info("Copied {$copied} activity images, left {$skipped} on placeholder.");
    }

    private function baseName(string $name): string
    {
        return strtolower(trim(preg_replace('/\s+(at|near|in|on|to|around|of|along)\s+.*$/i', '', $name)));
    }

    private function hasRealPhoto(Activity $activity): bool
    {
        return $activity->image_path && ! str_contains($activity->image_path, 'placeholder-');
    }

    private function copyImage(Activity $source, Activity $target): bool
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($source->image_path)) {
            return false;
        }

        $filename = Str::uuid()->toString().'.webp';
        $destImagePath = "activities/{$target->id}/{$filename}";
        $disk->copy($source->image_path, $destImagePath);

        $destThumbPath = $destImagePath;
        if ($source->thumbnail_path && $disk->exists($source->thumbnail_path)) {
            $destThumbPath = "activities/{$target->id}/thumbnails/{$filename}";
            $disk->copy($source->thumbnail_path, $destThumbPath);
        }

        $target->update([
            'image_path' => $destImagePath,
            'thumbnail_path' => $destThumbPath,
        ]);

        return true;
    }
}

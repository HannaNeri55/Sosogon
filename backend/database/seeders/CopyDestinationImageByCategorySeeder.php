<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\DestinationImage;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * For each category, finds the first destination that already has a real photo
 * (not the generated category placeholder) and copies it onto every other
 * destination in that same category that's still on the placeholder.
 *
 * Categories with no real photo in any destination yet (Waterfall, Church, Island,
 * as of 2026-09-22) are left on the placeholder — there's nothing in-category to
 * copy from.
 */
class CopyDestinationImageByCategorySeeder extends Seeder
{
    public function run(): void
    {
        $destinations = Destination::with('images')->orderBy('category_id')->orderBy('id')->get();

        $sourcesByCategory = [];
        foreach ($destinations->groupBy('category_id') as $categoryId => $group) {
            $source = $group->first(fn ($d) => $this->hasRealPhoto($d));
            if ($source) {
                $sourcesByCategory[$categoryId] = $source;
            }
        }

        foreach ($destinations as $destination) {
            if ($this->hasRealPhoto($destination)) {
                continue;
            }

            $source = $sourcesByCategory[$destination->category_id] ?? null;
            if (! $source || $source->id === $destination->id) {
                continue;
            }

            $this->copyImage($source, $destination);
        }
    }

    private function hasRealPhoto(Destination $destination): bool
    {
        $image = $destination->images->first();

        return $image && ! str_contains($image->image_path, 'placeholder-');
    }

    private function copyImage(Destination $source, Destination $target): void
    {
        $sourceImage = $source->images->first();
        $disk = Storage::disk('public');

        if (! $sourceImage || ! $disk->exists($sourceImage->image_path)) {
            return;
        }

        $filename = Str::uuid()->toString().'.webp';
        $destImagePath = "destinations/{$target->id}/{$filename}";
        $disk->copy($sourceImage->image_path, $destImagePath);

        $destThumbPath = $destImagePath;
        if ($sourceImage->thumbnail_path && $disk->exists($sourceImage->thumbnail_path)) {
            $destThumbPath = "destinations/{$target->id}/thumbnails/{$filename}";
            $disk->copy($sourceImage->thumbnail_path, $destThumbPath);
        }

        // Replace the placeholder row instead of accumulating duplicates on reseed
        DestinationImage::where('destination_id', $target->id)->delete();

        DestinationImage::create([
            'destination_id' => $target->id,
            'image_path' => $destImagePath,
            'thumbnail_path' => $destThumbPath,
            'alt_text' => $target->name,
            'sort_order' => 0,
            'is_primary' => true,
        ]);
    }
}

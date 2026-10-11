<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class ImageOptimizationService
{
    private const FULL_MAX_WIDTH = 1600;

    private const FULL_QUALITY = 82;

    private const THUMB_WIDTH = 400;

    private const THUMB_QUALITY = 75;

    /**
     * Resize + re-encode an uploaded image as WebP, storing a full-size
     * version and a separate, genuinely smaller thumbnail.
     *
     * @return array{image_path: string, thumbnail_path: string}
     */
    public function store(UploadedFile $file, string $directory, string $disk = 'public'): array
    {
        $filename = Str::uuid()->toString().'.webp';

        $full = Image::read($file->getRealPath())
            ->scaleDown(width: self::FULL_MAX_WIDTH)
            ->toWebp(self::FULL_QUALITY);
        $imagePath = "{$directory}/{$filename}";
        Storage::disk($disk)->put($imagePath, (string) $full);

        $thumb = Image::read($file->getRealPath())
            ->scaleDown(width: self::THUMB_WIDTH)
            ->toWebp(self::THUMB_QUALITY);
        $thumbnailPath = "{$directory}/thumbnails/{$filename}";
        Storage::disk($disk)->put($thumbnailPath, (string) $thumb);

        return [
            'image_path' => $imagePath,
            'thumbnail_path' => $thumbnailPath,
        ];
    }

    /**
     * Same as store(), but reads from a plain file path instead of an
     * UploadedFile — for seeders, which have no UploadedFile to work with.
     *
     * Filename is derived from the source path, not a random UUID. store()
     * uses a UUID because uploads need collision-proof names; a seeder
     * re-running against the same source image should overwrite the same
     * output file, not leave the previous run's file orphaned on disk.
     *
     * @return array{image_path: string, thumbnail_path: string}
     */
    public function storeFromPath(string $sourcePath, string $directory, string $disk = 'public'): array
    {
        $filename = Str::slug(pathinfo($sourcePath, PATHINFO_FILENAME)).'.webp';

        $full = Image::read($sourcePath)
            ->scaleDown(width: self::FULL_MAX_WIDTH)
            ->toWebp(self::FULL_QUALITY);
        $imagePath = "{$directory}/{$filename}";
        Storage::disk($disk)->put($imagePath, (string) $full);

        $thumb = Image::read($sourcePath)
            ->scaleDown(width: self::THUMB_WIDTH)
            ->toWebp(self::THUMB_QUALITY);
        $thumbnailPath = "{$directory}/thumbnails/{$filename}";
        Storage::disk($disk)->put($thumbnailPath, (string) $thumb);

        return [
            'image_path' => $imagePath,
            'thumbnail_path' => $thumbnailPath,
        ];
    }

    /**
     * Delete a stored image and its thumbnail. Silently ignores external
     * URLs (e.g. seeded placeholder images), since those aren't ours to delete.
     */
    public function delete(?string $imagePath, ?string $thumbnailPath = null, string $disk = 'public'): void
    {
        $paths = array_values(array_filter(
            [$imagePath, $thumbnailPath],
            fn (?string $path) => $path && ! str_starts_with($path, 'http')
        ));

        if ($paths !== []) {
            Storage::disk($disk)->delete($paths);
        }
    }
}

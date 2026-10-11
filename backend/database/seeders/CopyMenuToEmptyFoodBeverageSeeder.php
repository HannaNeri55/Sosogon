<?php

namespace Database\Seeders;

use App\Models\FoodBeverage;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copies the menu of "Restaurant" (the restaurant owner account's Bulan food hub,
 * set up 2026-10-04 with 12 dishes) onto every food & beverage place that has no
 * menu items of its own yet. The original source, The Campuz Food Park, was lost
 * with the old database.
 */
class CopyMenuToEmptyFoodBeverageSeeder extends Seeder
{
    private const SOURCE_NAME = 'Restaurant';

    public function run(): void
    {
        $source = FoodBeverage::where('name', self::SOURCE_NAME)->first();

        if (! $source) {
            $this->command->error(self::SOURCE_NAME.' not found — nothing to copy.');

            return;
        }

        $sourceItems = MenuItem::where('food_beverage_id', $source->id)->orderBy('id')->get();

        if ($sourceItems->isEmpty()) {
            $this->command->error(self::SOURCE_NAME.' has no menu items to copy.');

            return;
        }

        $targets = FoodBeverage::where('id', '!=', $source->id)
            ->whereDoesntHave('menuItems')
            ->get();

        foreach ($targets as $target) {
            $trusted = $target->status === 'approved';

            foreach ($sourceItems as $item) {
                $newItem = MenuItem::create([
                    'food_beverage_id' => $target->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'price' => $item->price,
                    'status' => $trusted ? 'approved' : 'pending',
                    'approved_at' => $trusted ? now() : null,
                ]);

                $newItem->update($this->copyImages($item, $newItem->id));
            }
        }
    }

    /** @return array{image_path: ?string, thumbnail_path: ?string} */
    private function copyImages(MenuItem $item, int $newItemId): array
    {
        $disk = Storage::disk('public');
        $paths = [];

        foreach (['image_path' => $item->image_path, 'thumbnail_path' => $item->thumbnail_path] as $key => $sourcePath) {
            if (! $sourcePath || ! $disk->exists($sourcePath)) {
                $paths[$key] = null;

                continue;
            }

            $isThumb = $key === 'thumbnail_path';
            $filename = Str::uuid()->toString().'.webp';
            $destPath = $isThumb ? "menu-items/{$newItemId}/thumbnails/{$filename}" : "menu-items/{$newItemId}/{$filename}";

            $disk->copy($sourcePath, $destPath);
            $paths[$key] = $destPath;
        }

        return $paths;
    }
}

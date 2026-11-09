<?php

namespace App\Console\Commands;

use App\Models\FoodBeverage;
use App\Models\FoodBeverageImage;
use App\Services\ImageOptimizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttachFoodBeveragePhotos extends Command
{
    protected $signature = 'listings:attach-food-photos {listing : Food and beverage listing id} {photos* : Photo files; the first is the cover unless the listing already has one}';

    protected $description = "Add a business's own photos to its food and beverage gallery, stored the same way as owner uploads";

    public function handle(ImageOptimizationService $images): int
    {
        $listing = FoodBeverage::find($this->argument('listing'));
        if (! $listing) {
            $this->error('No food and beverage listing has that id.');

            return self::FAILURE;
        }

        $photos = $this->argument('photos');
        foreach ($photos as $path) {
            if (! is_file($path)) {
                $this->error("Not a file: {$path}");

                return self::FAILURE;
            }
        }

        $directory = "food-beverages/{$listing->id}";
        $added = 0;

        DB::transaction(function () use ($listing, $photos, $images, $directory, &$added) {
            $hasCover = $listing->images()->where('is_primary', true)->exists();
            $order = (int) $listing->images()->max('sort_order') + ($listing->images()->exists() ? 1 : 0);

            foreach ($photos as $path) {
                $target = "{$directory}/".Str::slug(pathinfo($path, PATHINFO_FILENAME)).'.webp';
                if ($listing->images()->where('image_path', $target)->exists()) {
                    $this->line("Already attached, skipped: {$path}");

                    continue;
                }

                $stored = $images->storeFromPath($path, $directory);
                FoodBeverageImage::create($stored + [
                    'food_beverage_id' => $listing->id,
                    'is_primary' => ! $hasCover,
                    'sort_order' => $order++,
                ]);
                $hasCover = true;
                $added++;
            }
        });

        $this->info("{$listing->name}: {$added} photos added, ".$listing->images()->count().' in the gallery.');

        return self::SUCCESS;
    }
}

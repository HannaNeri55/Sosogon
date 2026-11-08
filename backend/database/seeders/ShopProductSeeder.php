<?php

namespace Database\Seeders;

use App\Models\RetailProduct;
use App\Models\RetailProductImage;
use App\Models\RetailShop;
use App\Services\ImageOptimizationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Example products for every shop, from data/shop_products.php.
 *
 * Each product is left where an owner's "add product" leaves it: pending, so nothing is public
 * until an admin approves it. There are no prices, because the businesses have not given any, and
 * the photos are openly licensed and credited representative photos.
 * Not part of DatabaseSeeder; run it by name:
 *
 *   SHOP_PRODUCT_IMAGE_DIR=... php artisan db:seed --class=ShopProductSeeder
 *
 * Refuses production. Only creates rows, and skips a product the shop already has, so re-runs add nothing.
 * SHOP_PRODUCT_IMAGE_DIR holds the photos and a manifest.json with their authors and licences.
 */
class ShopProductSeeder extends Seeder
{
    public function run(ImageOptimizationService $images): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Example products cannot be seeded in production.');
        }

        $imageDir = getenv('SHOP_PRODUCT_IMAGE_DIR') ?: null;
        if (! $imageDir) {
            throw new RuntimeException('Set SHOP_PRODUCT_IMAGE_DIR before running this seeder.');
        }

        $photos = collect(json_decode(file_get_contents("{$imageDir}/manifest.json"), true))
            ->keyBy(fn ($photo) => pathinfo($photo['file'], PATHINFO_FILENAME));
        $created = 0;

        foreach (require database_path('seeders/data/shop_products.php') as $key => $list) {
            [$shopName, $town] = explode('|', $key);
            $townId = DB::table('municipalities')->where('name', $town)->value('id');
            $shop = RetailShop::where('name', $shopName)->where('municipality_id', $townId)->first()
                ?? throw new RuntimeException("Unknown shop \"{$key}\".");

            foreach ($list['products'] as $item) {
                if ($shop->products()->where('name', $item['name'])->exists()) {
                    continue;
                }

                $photo = $photos[$item['photo']] ?? throw new RuntimeException("Photo {$item['photo']} is not in the manifest.");
                $author = $photo['artist'] !== '' ? $photo['artist'] : 'see source page';

                $product = RetailProduct::create([
                    'user_id' => $shop->user_id,
                    'retail_shop_id' => $shop->id,
                    'name' => $item['name'],
                    'description' => $item['about'],
                    'product_type' => $item['type'],
                    'price' => null,
                    'municipality_id' => $shop->municipality_id,
                    'barangay_id' => $shop->barangay_id,
                    'address' => $shop->address,
                    'latitude' => $shop->latitude,
                    'longitude' => $shop->longitude,
                    'contact_number' => $shop->contact_number,
                    'status' => 'pending',
                    'admin_notes' => "Added at the project team's request, not verified with the business (basis: {$list['basis']}). No price was given and the photo is representative.",
                ]);

                RetailProductImage::create([
                    ...$images->storeFromPath("{$imageDir}/{$item['photo']}.jpg", "retail-products/{$product->id}"),
                    'retail_product_id' => $product->id,
                    'is_primary' => true,
                    'sort_order' => 0,
                    'image_credit' => Str::limit("Representative photo: {$author} / Wikimedia Commons, {$photo['lic']}", 250),
                    'image_source_url' => $photo['page'],
                ]);
                $created++;
            }
        }

        $this->command?->info("Shop products created: {$created}.");
    }
}

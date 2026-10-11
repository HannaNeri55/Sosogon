<?php

namespace Database\Seeders;

use App\Models\RetailProduct;
use App\Models\RetailProductImage;
use App\Models\RetailShop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copies the product catalog of "Souvenir Shop" (the shop owner account's Bulan
 * store, set up 2026-10-04 with 12 pasalubong, handicraft and souvenir items) onto
 * every souvenir shop that has no products of its own yet. The original source,
 * Maria's Pasalubong, was lost with the old database.
 */
class CopyProductsToEmptyRetailShopSeeder extends Seeder
{
    private const SOURCE_NAME = 'Souvenir Shop';

    public function run(): void
    {
        $source = RetailShop::where('name', self::SOURCE_NAME)->first();

        if (! $source) {
            $this->command->error(self::SOURCE_NAME.' not found — nothing to copy.');

            return;
        }

        $sourceProducts = RetailProduct::where('retail_shop_id', $source->id)->orderBy('id')->get();

        if ($sourceProducts->isEmpty()) {
            $this->command->error(self::SOURCE_NAME.' has no products to copy.');

            return;
        }

        $targets = RetailShop::where('id', '!=', $source->id)
            ->whereDoesntHave('products')
            ->get();

        foreach ($targets as $target) {
            $trusted = $target->status === 'approved';

            foreach ($sourceProducts as $product) {
                $newProduct = RetailProduct::create([
                    'user_id' => null,
                    'retail_shop_id' => $target->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'product_type' => $product->product_type,
                    'municipality_id' => $target->municipality_id,
                    'barangay_id' => $target->barangay_id,
                    'address' => $target->address,
                    'latitude' => $target->latitude,
                    'longitude' => $target->longitude,
                    'price' => $product->price,
                    'contact_number' => $target->contact_number,
                    'status' => $trusted ? 'approved' : 'pending',
                    'approved_at' => $trusted ? now() : null,
                ]);

                $this->copyImages($product, $newProduct->id);
            }
        }
    }

    private function copyImages(RetailProduct $product, int $newProductId): void
    {
        $disk = Storage::disk('public');

        foreach ($product->images as $image) {
            if (! $image->image_path || ! $disk->exists($image->image_path)) {
                continue;
            }

            $filename = Str::uuid()->toString().'.webp';
            $destImagePath = "retail-products/{$newProductId}/{$filename}";
            $disk->copy($image->image_path, $destImagePath);

            // image_path and thumbnail_path are both required columns on this table —
            // fall back to the full image if there's no separate thumbnail to copy.
            $destThumbPath = $destImagePath;
            if ($image->thumbnail_path && $disk->exists($image->thumbnail_path)) {
                $destThumbPath = "retail-products/{$newProductId}/thumbnails/{$filename}";
                $disk->copy($image->thumbnail_path, $destThumbPath);
            }

            RetailProductImage::create([
                'retail_product_id' => $newProductId,
                'image_path' => $destImagePath,
                'thumbnail_path' => $destThumbPath,
                'is_primary' => $image->is_primary,
                'sort_order' => $image->sort_order,
            ]);
        }
    }
}

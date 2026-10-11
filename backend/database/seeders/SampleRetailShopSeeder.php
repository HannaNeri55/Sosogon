<?php

namespace Database\Seeders;

use App\Services\ImageOptimizationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds souvenir/pasalubong shops from the Sorsogon-wide business batch (2026-09-22).
 * "retail/local product" businesses map to retail_shops (shown as "Souvenir Shops"
 * on the public site), not individual retail_products.
 * See SampleAccommodationSeeder for the coordinate-sourcing notes. None of these have
 * a photo of their own, so they all share the default souvenir shop photo the user
 * provided (2026-09-22) until real photos are uploaded through the admin panel.
 */
class SampleRetailShopSeeder extends Seeder
{
    public function __construct(private ImageOptimizationService $images) {}

    public function run(): void
    {
        $shops = [
            [
                'name' => 'Barcelona Church Souvenirs',
                'description' => 'Small local stalls near the church offering religious items and local Barcelona souvenirs.',
                'municipality' => 'Barcelona',
                'barangay' => 'Poblacion',
                'address' => 'Near St. Joseph Parish, Barcelona',
                'latitude' => 12.8694,
                'longitude' => 124.1419,
                'contact_number' => null,
                'operating_hours' => 'Daytime',
            ],
            [
                'name' => 'Bulan Public Market',
                'description' => 'The central hub for fresh seafood, produce, and local Sorsogon handicrafts.',
                'municipality' => 'Bulan',
                'barangay' => null,
                'address' => 'Bulan, Sorsogon',
                'latitude' => 12.6697,
                'longitude' => 123.8758,
                'contact_number' => null,
                'operating_hours' => 'Daytime',
            ],
            [
                'name' => 'Bulusan Lake Souvenir Stalls',
                'description' => 'Local vendors at the park entrance selling native crafts, woven items, and snacks.',
                'municipality' => 'Bulusan',
                'barangay' => 'San Roque',
                'address' => 'Bulusan Volcano National Park',
                'latitude' => 12.75389,
                'longitude' => 124.09306,
                'contact_number' => null,
                'operating_hours' => 'Daytime',
            ],
            [
                'name' => 'Whale Shark Center Souvenir Shop',
                'description' => 'Shops near the tourism center offering whale shark themed merchandise and Bicolano crafts.',
                'municipality' => 'Donsol',
                'barangay' => 'Dancalan',
                'address' => 'Donsol Tourism Center',
                'latitude' => 12.9058,
                'longitude' => 123.5814,
                'contact_number' => null,
                'operating_hours' => 'Daytime',
            ],
            [
                'name' => 'Juban Local Crafts',
                'description' => 'Highway-side shops selling abaca products, pili nuts, and local delicacies.',
                'municipality' => 'Juban',
                'barangay' => 'Poblacion',
                'address' => 'Juban, Sorsogon',
                'latitude' => 12.8478,
                'longitude' => 123.9894,
                'contact_number' => null,
                'operating_hours' => 'Daytime',
            ],
            [
                'name' => 'Matnog Port Public Market',
                'description' => 'Vendors selling fresh seafood (like crabs and squid) and local souvenirs prior to island hopping.',
                'municipality' => 'Matnog',
                'barangay' => null,
                'address' => 'Near Matnog Tourism Office',
                'latitude' => 12.5856,
                'longitude' => 124.0856,
                'contact_number' => null,
                'operating_hours' => 'Daytime',
            ],
        ];

        foreach ($shops as $item) {
            $municipality = DB::table('municipalities')->where('name', $item['municipality'])->first();
            $municipalityId = $municipality
                ? $municipality->id
                : DB::table('municipalities')->insertGetId(['name' => $item['municipality'], 'created_at' => now(), 'updated_at' => now()]);

            $row = [
                'user_id' => null,
                'name' => $item['name'],
                'description' => $item['description'],
                'municipality_id' => $municipalityId,
                'barangay_id' => $this->resolveBarangayId($municipalityId, $item['barangay']),
                'address' => $item['address'],
                'latitude' => $item['latitude'],
                'longitude' => $item['longitude'],
                'contact_number' => $item['contact_number'],
                'operating_hours' => $item['operating_hours'],
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('retail_shops')->updateOrInsert(['name' => $row['name']], $row);
            $id = DB::table('retail_shops')->where('name', $row['name'])->value('id');

            $existingImage = DB::table('retail_shop_images')->where('retail_shop_id', $id)->first();
            $needsDefaultPhoto = ! $existingImage || str_contains($existingImage->image_path, 'placeholder-');

            if ($needsDefaultPhoto) {
                $sourcePath = storage_path('app/seed-images/retail-shops/default.jpg');
                $optimized = $this->images->storeFromPath($sourcePath, "retail-shops/{$id}");

                DB::table('retail_shop_images')->where('retail_shop_id', $id)->delete();

                DB::table('retail_shop_images')->insert([
                    'retail_shop_id' => $id,
                    'image_path' => $optimized['image_path'],
                    'thumbnail_path' => $optimized['thumbnail_path'],
                    'is_primary' => true,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** Same normalize-then-exact-match approach as SampleDestinationSeeder. */
    private function resolveBarangayId(int $municipalityId, ?string $rawName): ?int
    {
        if (! $rawName || $rawName === 'N/A') {
            return null;
        }

        $name = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $rawName));
        if (str_contains($name, ',')) {
            $parts = explode(',', $name);
            $name = trim(end($parts));
        }
        $name = preg_replace('/\s+Island$/i', '', $name);
        $name = strtr($name, ['Sta.' => 'Santa', 'Sto.' => 'Santo']);

        $barangay = DB::table('barangays')
            ->where('municipality_id', $municipalityId)
            ->where('name', $name)
            ->first();

        return $barangay?->id;
    }
}

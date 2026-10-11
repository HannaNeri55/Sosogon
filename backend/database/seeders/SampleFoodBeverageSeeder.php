<?php

namespace Database\Seeders;

use App\Services\ImageOptimizationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds food & beverage places from the Sorsogon-wide business batch (2026-09-22).
 * See SampleAccommodationSeeder for the coordinate-sourcing notes. None of these have
 * a photo of their own, so they all share the default food park photo the user
 * provided (2026-09-22) until real photos are uploaded through the admin panel.
 */
class SampleFoodBeverageSeeder extends Seeder
{
    public function __construct(private ImageOptimizationService $images) {}

    public function run(): void
    {
        $places = [
            [
                'name' => 'Una Pizzeria',
                'description' => 'A highly-rated restaurant serving authentic brick-oven pizzas and European dishes.',
                'cuisine_type' => 'Italian',
                'municipality' => 'Sorsogon City',
                'barangay' => null,
                'address' => 'Sorsogon City, Sorsogon',
                'latitude' => 12.9742,
                'longitude' => 124.0058,
                'contact_number' => null,
                'operating_hours' => null,
            ],
            [
                'name' => 'Balay Buhay sa Uma',
                'description' => 'A local farm-to-table eco-retreat and restaurant serving authentic Bicolano cuisine.',
                'cuisine_type' => 'Filipino',
                'municipality' => 'Bulusan',
                'barangay' => 'San Roque',
                'address' => 'Bulusan, Sorsogon',
                'latitude' => 12.7450,
                'longitude' => 124.1200,
                'contact_number' => null,
                'operating_hours' => 'Daytime',
            ],
            [
                'name' => "Butanding Bar and Restaurant (Giddy's Place)",
                'description' => 'A local restaurant serving fresh seafood and drinks, located at a popular dive resort.',
                'cuisine_type' => 'Seafood',
                'municipality' => 'Donsol',
                'barangay' => 'Town Center',
                'address' => '54 Clemente Street, Donsol',
                'latitude' => 12.9083,
                'longitude' => 123.5981,
                'contact_number' => '+63-917-848-8881',
                'operating_hours' => '6:00 AM - 1:00 AM',
            ],
            [
                'name' => "Linda's",
                'description' => 'A local eatery famous for its halo-halo and authentic Bicol express.',
                'cuisine_type' => 'Filipino',
                'municipality' => 'Gubat',
                'barangay' => null,
                'address' => 'Gubat, Sorsogon',
                'latitude' => 12.9189,
                'longitude' => 124.1231,
                'contact_number' => null,
                'operating_hours' => null,
            ],
            [
                'name' => 'Casa Luna',
                'description' => 'A restaurant and events place offering local food and catering services.',
                'cuisine_type' => null,
                'municipality' => 'Irosin',
                'barangay' => 'Buenavista',
                'address' => 'Irosin, Sorsogon',
                'latitude' => 12.6950,
                'longitude' => 124.0100,
                'contact_number' => null,
                'operating_hours' => null,
            ],
            [
                'name' => 'Prieto Diaz Local Eateries',
                'description' => 'Local stalls near the eco-park offering fresh seafood and snacks.',
                'cuisine_type' => 'Seafood',
                'municipality' => 'Prieto Diaz',
                'barangay' => null,
                'address' => 'Prieto Diaz, Sorsogon',
                'latitude' => 13.0408,
                'longitude' => 124.1931,
                'contact_number' => null,
                'operating_hours' => 'Daytime',
            ],
            [
                'name' => 'Coco Bar',
                'description' => 'A beachfront bar inside G Seascape Resort offering refreshing drinks and a relaxing night view.',
                'cuisine_type' => null,
                'municipality' => 'Santa Magdalena',
                'barangay' => 'San Sebastian',
                'address' => 'Santa Magdalena, Sorsogon',
                'latitude' => 12.6350,
                'longitude' => 124.1100,
                'contact_number' => null,
                'operating_hours' => 'Evening',
            ],
        ];

        foreach ($places as $item) {
            $municipality = DB::table('municipalities')->where('name', $item['municipality'])->first();
            $municipalityId = $municipality
                ? $municipality->id
                : DB::table('municipalities')->insertGetId(['name' => $item['municipality'], 'created_at' => now(), 'updated_at' => now()]);

            $row = [
                'user_id' => null,
                'name' => $item['name'],
                'description' => $item['description'],
                'cuisine_type' => $item['cuisine_type'],
                'municipality_id' => $municipalityId,
                'barangay_id' => $this->resolveBarangayId($municipalityId, $item['barangay']),
                'address' => $item['address'],
                'latitude' => $item['latitude'],
                'longitude' => $item['longitude'],
                'price_range' => 'moderate',
                'contact_number' => $item['contact_number'],
                'operating_hours' => $item['operating_hours'],
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            DB::table('food_beverages')->updateOrInsert(['name' => $row['name']], $row);
            $id = DB::table('food_beverages')->where('name', $row['name'])->value('id');

            $existingImage = DB::table('food_beverage_images')->where('food_beverage_id', $id)->first();
            $needsDefaultPhoto = ! $existingImage || str_contains($existingImage->image_path, 'placeholder-');

            if ($needsDefaultPhoto) {
                $sourcePath = storage_path('app/seed-images/food-beverages/default.webp');
                $optimized = $this->images->storeFromPath($sourcePath, "food-beverages/{$id}");

                DB::table('food_beverage_images')->where('food_beverage_id', $id)->delete();

                DB::table('food_beverage_images')->insert([
                    'food_beverage_id' => $id,
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

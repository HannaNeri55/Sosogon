<?php

namespace Database\Seeders;

use App\Services\ImageOptimizationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds accommodations from the Sorsogon-wide business batch (2026-09-22).
 * None of these have a photo of their own, so they all share the default
 * hotel photo the user provided (2026-09-22) until real photos are uploaded
 * through the admin panel. Coordinates are researched best-effort (town/landmark
 * level for small businesses, not surveyed GPS) — see the accompanying
 * chat for sourcing notes.
 */
class SampleAccommodationSeeder extends Seeder
{
    public function __construct(private ImageOptimizationService $images) {}

    public function run(): void
    {
        $accommodations = [
            [
                'name' => 'Siama Hotel',
                'description' => 'A premium luxury resort offering a tranquil forest setting, modern design, and a renowned swimming pool.',
                'municipality' => 'Sorsogon City',
                'barangay' => 'Sitio San Lorenzo, Bibincahan',
                'address' => 'Sorsogon City, Sorsogon',
                'latitude' => 12.9580,
                'longitude' => 124.0020,
                'contact_number' => null,
                'operating_hours' => '24/7',
            ],
            [
                'name' => 'RedDoorz @ Caree Boutique Hotel',
                'description' => 'A highly-rated boutique accommodation providing comfortable and clean stays in the town center.',
                'municipality' => 'Bulan',
                'barangay' => null,
                'address' => 'Bulan, Sorsogon',
                'latitude' => 12.6697,
                'longitude' => 123.8758,
                'contact_number' => null,
                'operating_hours' => '24/7',
            ],
            [
                'name' => 'Recidencia Del Hamor',
                'description' => 'A relaxing resort away from the city offering villas, spa services, and nature reconnection.',
                'municipality' => 'Casiguran',
                'barangay' => null,
                'address' => 'Casiguran, Sorsogon',
                'latitude' => 12.8731,
                'longitude' => 124.0081,
                'contact_number' => null,
                'operating_hours' => '24/7',
            ],
            [
                'name' => 'Sibol Private Resort',
                'description' => 'A tranquil retreat amidst lush forests offering cozy cabins, villas, and swimming pools.',
                'municipality' => 'Castilla',
                'barangay' => 'Sogoy (Purok 1)',
                'address' => 'Castilla, Sorsogon',
                'latitude' => 12.9500,
                'longitude' => 123.8700,
                'contact_number' => null,
                'operating_hours' => '24/7',
            ],
            [
                'name' => 'DonPicaso Malawmawan Sands Resort',
                'description' => 'A beachfront resort with an infinity pool, bahay kubo, and an in-house restaurant.',
                'municipality' => 'Castilla',
                'barangay' => 'Macalaya (Sitio Tiguib)',
                'address' => 'Castilla, Sorsogon',
                'latitude' => 12.8450,
                'longitude' => 123.7700,
                'contact_number' => '0917-109-2827',
                'operating_hours' => '24/7',
            ],
            [
                'name' => 'Vitton Beach Resort',
                'description' => 'A popular beachfront resort providing comfortable rooms and tour arrangements right by the whale shark center.',
                'municipality' => 'Donsol',
                'barangay' => 'Dancalan',
                'address' => 'Donsol, Sorsogon',
                'latitude' => 12.9058,
                'longitude' => 123.5814,
                'contact_number' => null,
                'operating_hours' => '24/7',
            ],
            [
                'name' => "Dreamink farm og Myl's place",
                'description' => 'A highly-rated farm stay with an outdoor swimming pool and nature views.',
                'municipality' => 'Gubat',
                'barangay' => null,
                'address' => 'Gubat, Sorsogon',
                'latitude' => 12.9189,
                'longitude' => 124.1231,
                'contact_number' => null,
                'operating_hours' => '24/7',
            ],
            [
                'name' => 'Caliente Spring Resort',
                'description' => 'A sanctuary resort in the heart of Irosin where nature and relaxation go hand in hand.',
                'municipality' => 'Irosin',
                'barangay' => null,
                'address' => 'Irosin, Sorsogon',
                'latitude' => 12.7050,
                'longitude' => 124.0319,
                'contact_number' => null,
                'operating_hours' => '24/7',
            ],
            [
                'name' => 'Casa Roja (Juban Heritage House)',
                'description' => 'One of the iconic ancestral houses turned into a modest lodging and museum experience.',
                'municipality' => 'Juban',
                'barangay' => 'Poblacion',
                'address' => 'Juban, Sorsogon',
                'latitude' => 12.8478,
                'longitude' => 123.9894,
                'contact_number' => null,
                'operating_hours' => null,
            ],
            [
                'name' => 'Touch of Grace Re-Creation Center',
                'description' => 'A lodging and restaurant facility serving tourists exploring Magallanes.',
                'municipality' => 'Magallanes',
                'barangay' => 'Aguada Sur',
                'address' => 'Magallanes, Sorsogon',
                'latitude' => 12.8250,
                'longitude' => 123.8300,
                'contact_number' => null,
                'operating_hours' => '24/7',
            ],
            [
                'name' => "Annabelle's Bed & Breakfast",
                'description' => 'A convenient bed and breakfast located in central Magallanes.',
                'municipality' => 'Magallanes',
                'barangay' => 'Poblacion',
                'address' => 'Magallanes, Sorsogon',
                'latitude' => 12.8283,
                'longitude' => 123.8344,
                'contact_number' => null,
                'operating_hours' => '24/7',
            ],
            [
                'name' => 'Chestnut Hill Hotel',
                'description' => 'A local hotel catering to travelers visiting the port and islands.',
                'municipality' => 'Matnog',
                'barangay' => null,
                'address' => 'Matnog, Sorsogon',
                'latitude' => 12.5856,
                'longitude' => 124.0856,
                'contact_number' => null,
                'operating_hours' => '24/7',
            ],
            [
                'name' => 'Christian John Ecotourism Resort and Hotel',
                'description' => "A resort offering lodging and access to Pilar's eco-tourism sites.",
                'municipality' => 'Pilar',
                'barangay' => 'Malbog',
                'address' => 'Pilar, Sorsogon',
                'latitude' => 12.9350,
                'longitude' => 123.6600,
                'contact_number' => null,
                'operating_hours' => '24/7',
            ],
            [
                'name' => 'G Seascape Resort',
                'description' => 'A highly-rated beachfront resort featuring a swimming pool, family rooms, and an in-house bar.',
                'municipality' => 'Santa Magdalena',
                'barangay' => 'San Sebastian',
                'address' => 'Santa Magdalena, Sorsogon',
                'latitude' => 12.6350,
                'longitude' => 124.1100,
                'contact_number' => '+63-920-655-0552',
                'operating_hours' => '24/7',
            ],
        ];

        foreach ($accommodations as $item) {
            $municipality = DB::table('municipalities')->where('name', $item['municipality'])->first();
            $municipalityId = $municipality
                ? $municipality->id
                : DB::table('municipalities')->insertGetId(['name' => $item['municipality'], 'created_at' => now(), 'updated_at' => now()]);

            $row = [
                'user_id' => null,
                'name' => $item['name'],
                'description' => $item['description'],
                'type' => $this->guessType($item['name'], $item['description']),
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

            DB::table('accommodations')->updateOrInsert(['name' => $row['name']], $row);
            $id = DB::table('accommodations')->where('name', $row['name'])->value('id');

            $existingImage = DB::table('accommodation_images')->where('accommodation_id', $id)->first();
            $needsDefaultPhoto = ! $existingImage || str_contains($existingImage->image_path, 'placeholder-');

            if ($needsDefaultPhoto) {
                $sourcePath = storage_path('app/seed-images/accommodations/default.jpg');
                $optimized = $this->images->storeFromPath($sourcePath, "accommodations/{$id}");

                DB::table('accommodation_images')->where('accommodation_id', $id)->delete();

                DB::table('accommodation_images')->insert([
                    'accommodation_id' => $id,
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

    private function guessType(string $name, string $description): string
    {
        $haystack = $name.' '.$description;

        return match (true) {
            (bool) preg_match('/resort/i', $haystack) => 'Resort',
            (bool) preg_match('/hotel/i', $haystack) => 'Hotel',
            (bool) preg_match('/inn\b/i', $haystack) => 'Inn',
            (bool) preg_match('/(bed\s*&?\s*breakfast|homestay|farm)/i', $haystack) => 'Homestay',
            default => 'Hotel',
        };
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

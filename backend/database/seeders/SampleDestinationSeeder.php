<?php

namespace Database\Seeders;

use App\Services\ImageOptimizationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SampleDestinationSeeder extends Seeder
{
    public function __construct(private ImageOptimizationService $images) {}

    public function run(): void
    {
        $destinations = [
            [
                'name' => 'Ilawod Beach',
                'description' => 'A quiet coastal nature destination offering relaxing sea views, popular among locals for swimming and simple beachside activities.',
                'category_id' => 1,
                'municipality' => 'Bulan',
                'barangay' => 'Otavi',
                'address' => 'Purok 1, Barangay Otavi, Bulan, Sorsogon',
                'latitude' => 12.6685,
                'longitude' => 123.8741,
                'operating_hours' => '6:00 AM - 6:00 PM',
                'entrance_fee' => 0.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'ilawod-beach.jpg',
            ],
            [
                'name' => 'Camp Hiraya',
                'description' => 'A serene mountain view and camping destination offering panoramic landscapes, cool breezes, and an escape into nature.',
                'category_id' => 2,
                'municipality' => 'Bulan',
                'barangay' => 'Butag',
                'address' => 'Barangay Butag, Bulan, Sorsogon',
                'latitude' => 12.6321,
                'longitude' => 123.8542,
                'operating_hours' => '8:00 AM - 5:00 PM',
                'entrance_fee' => 50.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'camp-hiraya.jpg',
            ],
            [
                'name' => 'Buntay Hills',
                'description' => 'A scenic nature spot featuring rolling terrain and refreshing breezes, often visited by locals for trekking and sunrise viewing.',
                'category_id' => 2,
                'municipality' => 'Santa Magdalena',
                'barangay' => 'San Roque',
                'address' => 'Barangay San Roque, Santa Magdalena, Sorsogon',
                'latitude' => 12.6475,
                'longitude' => 124.1080,
                'operating_hours' => '5:00 AM - 6:00 PM',
                'entrance_fee' => 0.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'buntay-hills.jpg',
            ],
            [
                'name' => 'Suhoton Cave',
                'description' => 'An exciting spelunking destination featuring fascinating rock formations and natural pools, attracting adventurous eco-tourists.',
                'category_id' => 7,
                'municipality' => 'Santa Magdalena',
                'barangay' => 'San Rafael',
                'address' => 'Barangay San Rafael, Santa Magdalena, Sorsogon',
                'latitude' => 12.6390,
                'longitude' => 124.1155,
                'operating_hours' => '8:00 AM - 4:00 PM',
                'entrance_fee' => 50.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'suhoton-cave.jpg',
            ],
            [
                'name' => 'Camp Toril Farm & Resort',
                'description' => 'A relaxing hybrid destination combining the rustic charm of a farm stay with the comforts of a local resort.',
                'category_id' => 7,
                'municipality' => 'Santa Magdalena',
                'barangay' => 'San Roque',
                'address' => 'Barangay San Roque, Santa Magdalena, Sorsogon',
                'latitude' => 12.6480,
                'longitude' => 124.1050,
                'operating_hours' => '8:00 AM - 8:00 PM',
                'entrance_fee' => 50.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'camp-toril.jpg',
            ],
            [
                'name' => 'Judith Rose Beach Resort',
                'description' => 'A tranquil beach resort providing comfortable lodging and a peaceful ambiance for weekend vacationers.',
                'category_id' => 1,
                'municipality' => 'Santa Magdalena',
                'barangay' => 'San Bartolome (Talaongan)',
                'address' => 'Barangay San Bartolome (Talaongan), Santa Magdalena, Sorsogon',
                'latitude' => 12.6430,
                'longitude' => 124.1160,
                'operating_hours' => '24 hours',
                'entrance_fee' => 50.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'judith-rose-resort.jpg',
            ],
            [
                'name' => 'Odella Beach Resort',
                'description' => 'A charming coastal resort with well-maintained facilities, ideal for group excursions and special events.',
                'category_id' => 1,
                'municipality' => 'Santa Magdalena',
                'barangay' => 'San Antonio',
                'address' => 'Sitio Busaingan, Barangay San Antonio, Santa Magdalena, Sorsogon',
                'latitude' => 12.6515,
                'longitude' => 124.1090,
                'operating_hours' => '7:00 AM - 10:00 PM',
                'entrance_fee' => 50.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'odella-beach-resort.jpg',
            ],
            [
                'name' => 'Frilles Beach Resort',
                'description' => 'An accessible and budget-friendly beach resort offering traditional cottages and beautiful sunrise views.',
                'category_id' => 1,
                'municipality' => 'Santa Magdalena',
                'barangay' => 'San Isidro',
                'address' => 'Barangay San Isidro, Santa Magdalena, Sorsogon',
                'latitude' => 12.6540,
                'longitude' => 124.1075,
                'operating_hours' => '6:00 AM - 8:00 PM',
                'entrance_fee' => 30.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'frilles-beach-resort.jpg',
            ],
            [
                'name' => 'Marahlee Beach Resort',
                'description' => 'A quiet and relaxing resort tucked away on the coastline, providing a private feel for vacationing guests.',
                'category_id' => 1,
                'municipality' => 'Santa Magdalena',
                'barangay' => 'San Roque',
                'address' => 'Barangay San Roque, Santa Magdalena, Sorsogon',
                'latitude' => 12.6460,
                'longitude' => 124.1130,
                'operating_hours' => '24 hours',
                'entrance_fee' => 50.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'marahlee-beach-resort.jpg',
            ],
            [
                'name' => 'Sorsogon Capitol Park',
                'description' => 'A leafy public park surrounding the Sorsogon Provincial Capitol, a neoclassical American colonial-era government building restored with National Historical Commission involvement. Home to the Rizal Monument and a popular downtown gathering space.',
                'category_id' => 6,
                'municipality' => 'Sorsogon City',
                'barangay' => 'Sirangan (Pob.)',
                'address' => 'Rizal Street, Sorsogon City, Sorsogon',
                'latitude' => 12.9742,
                'longitude' => 124.0058,
                'operating_hours' => '6:00 AM - 9:00 PM',
                'entrance_fee' => 0.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'sorsogon-capitol-park.jpg',
            ],
            [
                'name' => 'Sabang Park',
                'description' => 'A public park and historical waterfront area in Bulan offering a relaxing open space for strolls along the coast. Located in the coastal area near Ilawod Beach.',
                'category_id' => 6,
                'municipality' => 'Bulan',
                'barangay' => 'Otavi',
                'address' => 'Purok 1, Barangay Otavi, Bulan, Sorsogon',
                'latitude' => 12.6685,
                'longitude' => 123.8741,
                'operating_hours' => '6:00 AM - 6:00 PM',
                'entrance_fee' => 0.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'sabang-park.jpg',
            ],
            [
                'name' => 'Bulan Heritage Museum',
                'description' => 'A cultural institution showcasing the history, local artifacts, and heritage of the municipality of Bulan. Located in the town proper near Camp Hiraya.',
                'category_id' => 4,
                'municipality' => 'Bulan',
                'barangay' => 'Butag',
                'address' => 'Barangay Butag, Bulan, Sorsogon',
                'latitude' => 12.6321,
                'longitude' => 123.8542,
                'operating_hours' => '8:00 AM - 5:00 PM',
                'entrance_fee' => 50.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'bulan-heritage-museum.jpg',
            ],
            [
                'name' => 'Bulan Eco Park',
                'description' => 'A nature and recreational park designed for outdoor activities, family picnics, and ecological appreciation. Located near the town center, close to Camp Hiraya.',
                'category_id' => 7,
                'municipality' => 'Bulan',
                'barangay' => 'Butag',
                'address' => 'Barangay Butag, Bulan, Sorsogon',
                'latitude' => 12.6321,
                'longitude' => 123.8542,
                'operating_hours' => '8:00 AM - 5:00 PM',
                'entrance_fee' => 50.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'bulan-eco-park.jpg',
            ],
            [
                'name' => 'Museo Sorsogon',
                'description' => 'Housed in a restored historic building, this museum showcases Sorsogon\'s history, culture, and heritage. Located within the Capitol Compound near Sorsogon Capitol Park.',
                'category_id' => 4,
                'municipality' => 'Sorsogon City',
                'barangay' => 'Sirangan (Pob.)',
                'address' => 'Rizal Street, Sorsogon City, Sorsogon',
                'latitude' => 12.9742,
                'longitude' => 124.0058,
                'operating_hours' => '6:00 AM - 9:00 PM',
                'entrance_fee' => 0.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'museo-sorsogon.jpg',
            ],
            [
                'name' => 'Rompeolas',
                'description' => 'The city\'s baywalk area, in the periphery of Barangay Talisay — popular for evening strolls, street food, and sunsets over Sorsogon Bay. Located along the city waterfront near Sorsogon Capitol Park.',
                'category_id' => 6,
                'municipality' => 'Sorsogon City',
                'barangay' => 'Sirangan (Pob.)',
                'address' => 'Rizal Street, Sorsogon City, Sorsogon',
                'latitude' => 12.9742,
                'longitude' => 124.0058,
                'operating_hours' => '6:00 AM - 9:00 PM',
                'entrance_fee' => 0.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'rompeolas.jpg',
            ],
            [
                'name' => 'City Coastal Road',
                'description' => 'A scenic coastal bypass road offering views of Sorsogon Bay, popular among joggers and cyclists. Located in the coastal area near Sorsogon Capitol Park.',
                'category_id' => 6,
                'municipality' => 'Sorsogon City',
                'barangay' => 'Sirangan (Pob.)',
                'address' => 'Rizal Street, Sorsogon City, Sorsogon',
                'latitude' => 12.9742,
                'longitude' => 124.0058,
                'operating_hours' => '6:00 AM - 9:00 PM',
                'entrance_fee' => 0.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'city-coastal-road.jpg',
            ],
            [
                'name' => 'Pepita Park',
                'description' => 'A shaded roadside rest park in Barangay Bucalbucalan, home to a 100-year-old acacia tree, offering views of the bay. Located near Sorsogon Capitol Park.',
                'category_id' => 6,
                'municipality' => 'Sorsogon City',
                'barangay' => 'Sirangan (Pob.)',
                'address' => 'Rizal Street, Sorsogon City, Sorsogon',
                'latitude' => 12.9742,
                'longitude' => 124.0058,
                'operating_hours' => '6:00 AM - 9:00 PM',
                'entrance_fee' => 0.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'pepita-park.png',
            ],
            [
                'name' => 'Rizal Monument',
                'description' => 'A monument dedicated to the national hero, standing within Capitol Park as a prominent city landmark.',
                'category_id' => 4,
                'municipality' => 'Sorsogon City',
                'barangay' => 'Sirangan (Pob.)',
                'address' => 'Rizal Street, Sorsogon City, Sorsogon',
                'latitude' => 12.9742,
                'longitude' => 124.0058,
                'operating_hours' => '6:00 AM - 9:00 PM',
                'entrance_fee' => 0.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'rizal-monument.jpg',
            ],

            // --- Sorsogon-wide batch (2026-09-22): destinations from the province's
            // municipalities, Bulan included in full, others capped per MAX_PER_MUNICIPALITY
            // below. None of these have a dedicated seed photo yet, so they render with
            // the category placeholder graphic (see resolveImage()) until a real one is
            // dropped into storage/app/seed-images/destinations/.
            [
                'name' => 'Buhatan River Eco Adventure',
                'description' => 'An eco-tourism river cruise offering a relaxing ride along a pristine waterway, complete with local dining on floating cabanas.',
                'category_id' => 7,
                'municipality' => 'Sorsogon City',
                'barangay' => 'Buhatan',
                'address' => 'Barangay Buhatan, Sorsogon City, Sorsogon',
                'latitude' => 12.9459,
                'longitude' => 123.9928,
                'operating_hours' => 'Daytime',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'St. Joseph Parish (Barcelona Church)',
                'description' => 'A historical coral rock church built in the 1800s by Franciscan friars facing the Pacific Ocean.',
                'category_id' => 5,
                'municipality' => 'Barcelona',
                'barangay' => 'Poblacion',
                'address' => 'Barcelona, Sorsogon',
                'latitude' => 12.8694,
                'longitude' => 124.1419,
                'operating_hours' => 'Daytime',
                'entrance_fee' => 0.00,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'church-default.jpg',
            ],
            [
                'name' => 'Barcelona Ruins Park',
                'description' => 'The 138-year-old ruins of "La Presidencia," which served as a fortress, watchtower, and Spanish government seat.',
                'category_id' => 4,
                'municipality' => 'Barcelona',
                'barangay' => 'Poblacion',
                'address' => 'Barcelona, Sorsogon',
                'latitude' => 12.8697,
                'longitude' => 124.1423,
                'operating_hours' => '24/7',
                'entrance_fee' => 0.00,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'World War II Japanese Tunnels',
                'description' => 'Hidden tunnels believed to be used as hideouts by Japanese soldiers during WWII.',
                'category_id' => 4,
                'municipality' => 'Bulan',
                'barangay' => 'Sta. Remedios',
                'address' => 'Barangay Santa Remedios, Bulan, Sorsogon',
                'latitude' => 12.6750,
                'longitude' => 123.8690,
                'operating_hours' => null,
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'Ubo Falls',
                'description' => 'A refreshing waterfall and secret picnic ground frequented by locals.',
                'category_id' => 3,
                'municipality' => 'Bulan',
                'barangay' => 'San Francisco (Sitio Liman)',
                'address' => 'Sitio Liman, Barangay San Francisco, Bulan, Sorsogon',
                'latitude' => 12.6450,
                'longitude' => 123.8300,
                'operating_hours' => null,
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'waterfall-default.jpg',
            ],
            [
                'name' => 'Bulusan Lake',
                'description' => 'An emerald-green lake nestled at the heart of Bulusan Volcano National Park, perfect for kayaking and jungle trekking.',
                'category_id' => 6,
                'municipality' => 'Bulusan',
                'barangay' => 'San Roque',
                'address' => 'Bulusan Volcano National Park, Bulusan, Sorsogon',
                'latitude' => 12.75389,
                'longitude' => 124.09306,
                'operating_hours' => 'Daytime',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'Bayugin Falls',
                'description' => 'A charming waterfall surrounded by verdant bushland, ideal for swimming and picnics.',
                'category_id' => 3,
                'municipality' => 'Bulusan',
                'barangay' => null,
                'address' => 'Bulusan, Sorsogon',
                'latitude' => 12.7550,
                'longitude' => 124.0700,
                'operating_hours' => 'Daytime',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'waterfall-default.jpg',
            ],
            [
                'name' => 'Nagsipit Falls',
                'description' => 'A towering waterfall clad by a lush green forest.',
                'category_id' => 3,
                'municipality' => 'Casiguran',
                'barangay' => null,
                'address' => 'Casiguran, Sorsogon',
                'latitude' => 12.8850,
                'longitude' => 123.9950,
                'operating_hours' => 'Daytime',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'waterfall-default.jpg',
            ],
            [
                'name' => 'Namuat Falls',
                'description' => 'A scenic waterfall fringed by multi-colored flowers.',
                'category_id' => 3,
                'municipality' => 'Casiguran',
                'barangay' => null,
                'address' => 'Casiguran, Sorsogon',
                'latitude' => 12.8900,
                'longitude' => 123.9900,
                'operating_hours' => 'Daytime',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'waterfall-default.jpg',
            ],
            [
                'name' => 'Malawmawan Island',
                'description' => 'A tadpole-shaped island rich in colorful coral formations and a long stretch of sand bar.',
                'category_id' => 8,
                'municipality' => 'Castilla',
                'barangay' => 'Macalaya',
                'address' => 'Barangay Macalaya, Castilla, Sorsogon',
                'latitude' => 12.8428,
                'longitude' => 123.7667,
                'operating_hours' => 'Daytime',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
                'image' => 'island-default.jpg',
            ],
            [
                'name' => 'Donsol Whale Shark Interaction Center',
                'description' => 'The prime eco-tourism site in the Philippines for ethical snorkeling and swimming with whale sharks. Entrance fee: P100 (locals) / P300 (foreigners), plus a P5,000 boat fee.',
                'category_id' => 7,
                'municipality' => 'Donsol',
                'barangay' => 'Dancalan',
                'address' => 'Barangay Dancalan, Donsol, Sorsogon',
                'latitude' => 12.9058,
                'longitude' => 123.5814,
                'operating_hours' => '7:00 AM - 2:00 PM',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'Donsol River Firefly Watching',
                'description' => 'Evening boat cruises along the Donsol river to observe thousands of fireflies lighting up the mangrove trees.',
                'category_id' => 7,
                'municipality' => 'Donsol',
                'barangay' => 'Dancalan',
                'address' => 'Donsol River, Donsol, Sorsogon',
                'latitude' => 12.8917,
                'longitude' => 123.6167,
                'operating_hours' => 'Evening',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'Lola Sayong Eco-Surfing Camp',
                'description' => 'A hipster, chill surfing haven at Buenavista beach offering surfing lessons and overnight stays.',
                'category_id' => 1,
                'municipality' => 'Gubat',
                'barangay' => 'Buenavista',
                'address' => 'Buenavista, Gubat, Sorsogon',
                'latitude' => 12.8925,
                'longitude' => 124.1430,
                'operating_hours' => 'Daytime',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'Oroc Cold Spring',
                'description' => 'A refreshing cold spring and hidden waterfalls accessed via a short 40-minute trek.',
                'category_id' => 7,
                'municipality' => 'Gubat',
                'barangay' => null,
                'address' => 'Gubat, Sorsogon',
                'latitude' => 12.9250,
                'longitude' => 124.1000,
                'operating_hours' => 'Daytime',
                'entrance_fee' => 20.00,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'Irosin Hot Springs (San Mateo)',
                'description' => 'Natural geothermal hot springs nestled in the valley, perfect for relaxation.',
                'category_id' => 7,
                'municipality' => 'Irosin',
                'barangay' => 'San Mateo',
                'address' => 'San Mateo, Irosin, Sorsogon',
                'latitude' => 12.6772,
                'longitude' => 124.0206,
                'operating_hours' => 'Daytime',
                'entrance_fee' => 20.00,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'The Old Houses of Juban',
                'description' => 'A collection of beautifully preserved Spanish-era ancestral houses lining the highway.',
                'category_id' => 4,
                'municipality' => 'Juban',
                'barangay' => 'Poblacion',
                'address' => 'Maharlika Highway, Juban, Sorsogon',
                'latitude' => 12.8478,
                'longitude' => 123.9894,
                'operating_hours' => '24/7',
                'entrance_fee' => 0.00,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'Parola Beach & Lighthouse Station',
                'description' => 'A beautiful beach on Bagatao Island featuring a 29-ft century-old white cylindrical iron lighthouse built in 1903.',
                'category_id' => 1,
                'municipality' => 'Magallanes',
                'barangay' => 'Bagatao Island',
                'address' => 'Bagatao Island, Magallanes, Sorsogon',
                'latitude' => 12.83225,
                'longitude' => 123.791722,
                'operating_hours' => 'Daytime',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'Bucal-Bucalan Spring Resort',
                'description' => 'A natural spring resort with large pools utilized by locals and visitors for swimming.',
                'category_id' => 7,
                'municipality' => 'Magallanes',
                'barangay' => 'Aguada Sur',
                'address' => 'Barangay Aguada Sur, Provincial Road, Magallanes, Sorsogon',
                'latitude' => 12.8341,
                'longitude' => 123.8398,
                'operating_hours' => 'Daytime',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'Subic Beach',
                'description' => 'A stunning beach known worldwide for its rare pinkish-white sand and crystal clear waters.',
                'category_id' => 1,
                'municipality' => 'Matnog',
                'barangay' => 'Calintaan Island',
                'address' => 'Calintaan Island, Matnog, Sorsogon',
                'latitude' => 12.5412,
                'longitude' => 124.1044,
                'operating_hours' => 'Daytime',
                'entrance_fee' => 70.00,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'Juag Lagoon Marine Sanctuary',
                'description' => 'A marine sanctuary where visitors can swim and feed various species of fish.',
                'category_id' => 7,
                'municipality' => 'Matnog',
                'barangay' => 'Juag',
                'address' => 'Barangay Juag, Matnog, Sorsogon',
                'latitude' => 12.5527,
                'longitude' => 124.1109,
                'operating_hours' => 'Daytime',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'Panumbagan Marine Sanctuary',
                'description' => 'An emerging eco-tourism site ideal for diving, boating, and bird watching.',
                'category_id' => 7,
                'municipality' => 'Pilar',
                'barangay' => null,
                'address' => 'Pilar, Sorsogon',
                'latitude' => 12.9200,
                'longitude' => 123.6500,
                'operating_hours' => 'Daytime',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'Prieto Diaz Mangrove Eco-Park',
                'description' => 'A coastal environmental project site featuring expansive mangrove forests and caves to explore.',
                'category_id' => 7,
                'municipality' => 'Prieto Diaz',
                'barangay' => null,
                'address' => 'Prieto Diaz, Sorsogon',
                'latitude' => 13.0350,
                'longitude' => 124.1900,
                'operating_hours' => 'Daytime',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
            ],
            [
                'name' => 'Balading Beach',
                'description' => 'A picturesque, powdery sand beach that remains largely undiscovered and serene.',
                'category_id' => 1,
                'municipality' => 'Santa Magdalena',
                'barangay' => 'Balading',
                'address' => 'Sitio Balading, Santa Magdalena, Sorsogon',
                'latitude' => 12.6420,
                'longitude' => 124.1050,
                'operating_hours' => 'Daytime',
                'entrance_fee' => null,
                'status' => 'published',
                'created_by' => 1,
            ],
        ];

        // Bulan gets every destination given for it; every other municipality is capped
        // at this many per category, in case future additions to the array above exceed it.
        $maxPerMunicipalityCategory = 9;
        $destinations = $this->capByMunicipality($destinations, 'Bulan', $maxPerMunicipalityCategory);

        foreach ($destinations as $dest) {
            $imageBasename = $dest['image'] ?? null;
            $municipalityName = $dest['municipality'];
            $barangayName = $dest['barangay'];
            unset($dest['image'], $dest['municipality'], $dest['barangay']);

            $municipality = DB::table('municipalities')->where('name', $municipalityName)->first();
            $municipalityId = $municipality
                ? $municipality->id
                : DB::table('municipalities')->insertGetId(['name' => $municipalityName, 'created_at' => now(), 'updated_at' => now()]);

            $dest['municipality_id'] = $municipalityId;
            $dest['barangay_id'] = $this->resolveBarangayId($municipalityId, $barangayName);
            $dest['created_at'] = now();
            $dest['updated_at'] = now();

            DB::table('destinations')->updateOrInsert(['name' => $dest['name']], $dest);
            $id = DB::table('destinations')->where('name', $dest['name'])->value('id');

            $sourcePath = $imageBasename ? storage_path("app/seed-images/destinations/{$imageBasename}") : null;

            if ($sourcePath && ! file_exists($sourcePath)) {
                throw new \RuntimeException(
                    "SampleDestinationSeeder: missing source image at \"{$sourcePath}\" for \"{$dest['name']}\". ".
                    'Place the photo there before reseeding.'
                );
            }

            $existingImage = DB::table('destination_images')->where('destination_id', $id)->first();
            $hasRealPhoto = $existingImage && ! str_contains($existingImage->image_path, 'placeholder-');

            // Don't stomp a real photo (hand-curated or copied in by category) just because
            // this entry has no explicit 'image' key — only (re)generate when there's a
            // curated source, or the destination has no photo at all yet.
            if (! $sourcePath && $hasRealPhoto) {
                continue;
            }

            $optimized = $sourcePath
                ? $this->images->storeFromPath($sourcePath, "destinations/{$id}")
                : $this->placeholderImage("destinations/{$id}", $this->categoryLabel($dest['category_id']));

            // Replace this destination's image instead of accumulating duplicates on reseed
            DB::table('destination_images')->where('destination_id', $id)->delete();

            DB::table('destination_images')->insert([
                'destination_id' => $id,
                'image_path' => $optimized['image_path'],
                'thumbnail_path' => $optimized['thumbnail_path'],
                'alt_text' => $dest['name'],
                'sort_order' => 0,
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Keeps every entry for $exemptMunicipality, and caps every other municipality's
     * entries per category_id at $limit (keeping array order / first-come).
     */
    private function capByMunicipality(array $destinations, string $exemptMunicipality, int $limit): array
    {
        $counts = [];
        $kept = [];

        foreach ($destinations as $dest) {
            if (($dest['municipality'] ?? null) === $exemptMunicipality) {
                $kept[] = $dest;

                continue;
            }

            $key = ($dest['municipality'] ?? '').'|'.($dest['category_id'] ?? '');
            $counts[$key] = ($counts[$key] ?? 0) + 1;

            if ($counts[$key] <= $limit) {
                $kept[] = $dest;
            }
        }

        return $kept;
    }

    /** Strips sitio/purok detail and "Island" suffixes, expands Sta./Sto., and looks up the
     * barangay by exact name. Returns null (rather than guessing) when nothing matches. */
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

    private function categoryLabel(int $categoryId): string
    {
        return match ($categoryId) {
            1 => 'Beach',
            2 => 'Mountain',
            3 => 'Waterfall',
            4 => 'Historical',
            5 => 'Church',
            6 => 'Park',
            7 => 'Eco-Tourism',
            8 => 'Island',
            9 => 'Resort',
            default => 'Sorsogon',
        };
    }

    /** @return array{image_path: string, thumbnail_path: string} */
    private function placeholderImage(string $directory, string $label): array
    {
        $path = "{$directory}/placeholder-".Str::slug($label).'.svg';

        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, $this->buildPlaceholderSvg($label));
        }

        // Same file for both — it's a tiny vector graphic, no separate thumbnail needed.
        return ['image_path' => $path, 'thumbnail_path' => $path];
    }

    private function buildPlaceholderSvg(string $label): string
    {
        $palette = ['#0E6B4F', '#2B7FBF', '#E8A838', '#094A37', '#1B557F'];
        $color = $palette[crc32($label) % count($palette)];
        $safeLabel = htmlspecialchars($label, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 450">
  <rect width="800" height="450" fill="{$color}"/>
  <circle cx="700" cy="60" r="120" fill="#fff" opacity="0.08"/>
  <circle cx="80" cy="400" r="160" fill="#fff" opacity="0.08"/>
  <text x="400" y="235" font-family="Arial, sans-serif" font-size="32" font-weight="700" fill="#fff" text-anchor="middle">{$safeLabel}</text>
  <text x="400" y="275" font-family="Arial, sans-serif" font-size="16" fill="#fff" opacity="0.8" text-anchor="middle">Sorsogon Province</text>
</svg>
SVG;
    }
}

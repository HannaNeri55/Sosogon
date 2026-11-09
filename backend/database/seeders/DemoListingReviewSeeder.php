<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local/demo only. Seeds five sample reviews for each destination, food &
 * beverage establishment and shop that existed when demo content was added;
 * listings created later are deliberately not included.
 *
 * Every seeded comment starts with "Demo Review:" so sample data stays
 * identifiable (the public UI strips the prefix; the admin review overview
 * tags it as a sample). Reviews come from a pool of demo Tourist accounts so
 * names vary between listings, and comments are general impressions that
 * avoid unverified claims about facilities, prices or hours.
 *
 * Idempotent: accounts are matched by email and reviews by (user, listing),
 * which the ratings unique index also enforces. Existing rows are never
 * modified or deleted.
 */
class DemoListingReviewSeeder extends Seeder
{
    private const PREFIX = 'Demo Review: ';

    /** Snapshot of destination ID => [expected name, category]. */
    private const DESTINATIONS = [
        1 => ['Ilawod Beach', 'Beach'],
        2 => ['Camp Hiraya', 'Mountain'],
        3 => ['Buntay Hills', 'Mountain'],
        6 => ['Judith Rose Beach Resort', 'Beach'],
        7 => ['Odella Beach Resort', 'Resort'],
        9 => ['Marahlee Beach Resort', 'Beach'],
        10 => ['Sorsogon Capitol Park', 'Park'],
        11 => ['Sabang Park & Beach Resort', 'Park'],
        12 => ['Bulan Heritage Museum', 'Historical'],
        13 => ['Bulan Eco Park', 'Eco-Tourism'],
        14 => ['Museo Sorsogon', 'Historical'],
        15 => ['Rompeolas', 'Park'],
        16 => ['City Coastal Road', 'Park'],
        17 => ['Pepita Park', 'Park'],
        18 => ['Rizal Monument', 'Historical'],
        19 => ['Buhatan River Eco Adventure', 'Eco-Tourism'],
        20 => ['St. Joseph Parish (Barcelona Church)', 'Church'],
        21 => ['Barcelona Ruins Park', 'Historical'],
        24 => ['Bulusan Lake', 'Eco-Tourism'],
        25 => ['Bayugin Falls', 'Waterfall'],
        26 => ['Nagsipit Falls', 'Waterfall'],
        27 => ['Namuat Falls', 'Waterfall'],
        28 => ['Malawmawan Island', 'Island'],
        29 => ['Donsol Whale Shark Interaction Center', 'Eco-Tourism'],
        30 => ['Donsol River Firefly Watching', 'Eco-Tourism'],
        31 => ['Lola Sayong Eco-Surfing Camp', 'Beach'],
        32 => ['Oroc Cold Spring', 'Eco-Tourism'],
        33 => ['Irosin Hot Springs (San Mateo)', 'Eco-Tourism'],
        34 => ['The Old Houses of Juban', 'Historical'],
        35 => ['Parola Beach & Lighthouse Station', 'Beach'],
        36 => ['Bucal-Bucalan Spring Resort', 'Eco-Tourism'],
        37 => ['Subic Beach', 'Beach'],
        38 => ['Juag Lagoon Marine Sanctuary', 'Eco-Tourism'],
        39 => ['Panumbagan Marine Sanctuary', 'Eco-Tourism'],
        40 => ['Prieto Diaz Mangrove Eco-Park', 'Eco-Tourism'],
        41 => ['Balading Beach', 'Beach'],
        42 => ['Apin\'s Place', 'Resort'],
        43 => ['Cañezal Resort', 'Resort'],
        44 => ['Isabel\'s Resort', 'Resort'],
        45 => ['Kapehan sa Bulod', 'Mountain'],
        46 => ['Biyong Spring', 'Eco-Tourism'],
        48 => ['Bukid View Deck', 'Mountain'],
        49 => ['Camp Burabod', 'Eco-Tourism'],
        50 => ['Colegio Digital Barcelona', 'Historical'],
        51 => ['Camp Toril Farm & Resort', 'Resort'],
        52 => ['Balading Beach - Balading Resort', 'Resort'],
        53 => ['Vintage Bridge', 'Historical'],
        54 => ['Balay Buhay Bee Farm', 'Eco-Tourism'],
    ];

    /** Snapshot of food & beverage ID => expected name. */
    private const FOOD_BEVERAGES = [
        1 => 'Una Pizzeria',
        2 => 'Balay Buhay sa Uma',
        3 => 'Butanding Bar and Restaurant (Giddy\'s Place)',
        4 => 'Linda\'s',
        5 => 'Casa Luna',
        6 => 'Prieto Diaz Local Eateries',
        7 => 'Coco Bar',
        8 => 'Test Restaurant - Revision 2',
    ];

    /** Snapshot of retail shop ID => expected name. */
    private const SHOPS = [
        1 => 'Barcelona Church Souvenirs',
        2 => 'Bulan Public Market',
        3 => 'Bulusan Lake Souvenir Stalls',
        4 => 'Whale Shark Center Souvenir Shop',
        5 => 'Juban Local Crafts',
        6 => 'Matnog Port Public Market',
        7 => 'PiliNuts Pasalubong Center',
    ];

    /** Demo reviewer pool (sosogon.demo.visitor001-120@example.com). */
    private const REVIEWERS = [
        'Imelda Yap', 'Karen Ordoñez', 'Elaine Alcantara', 'Andrea Umali',
        'Zaldy Macaraeg', 'Ursula Jacinto', 'Nathaniel Pangilinan', 'Nathaniel Zamora',
        'Nestor Ordoñez', 'Gemma Hipolito', 'Cristina Regalado', 'Imelda Pacheco',
        'Nathaniel Ignacio', 'Bernard Jacinto', 'Dexter Vergara', 'Fritz Pangilinan',
        'Xander Mercado', 'Winona Santiago', 'Ferdinand Jacinto', 'Herbert Javier',
        'Cristina Nepomuceno', 'Winona Katigbak', 'Kathleen Calderon', 'Andrea Santiago',
        'Dominic Alcantara', 'Homer Galang', 'Queenie Francisco', 'Andrea Alcantara',
        'Nestor Dacanay', 'Karen Macaraeg', 'Imelda Galang', 'Iris Ordoñez',
        'Ofelia Sarmiento', 'Yvonne Galang', 'Gemma Lualhati', 'Carmela Tuazon',
        'Virgilio Estrada', 'Evangeline Yap', 'Herbert Pangilinan', 'Ofelia Tuazon',
        'Lorenzo Francisco', 'Queenie Rosales', 'Ofelia Katigbak', 'Evangeline Macaraeg',
        'Ursula Tuazon', 'Rogelio Escobar', 'Rogelio Dimaano', 'Benjie Gallardo',
        'Bernard Bañez', 'Mika Dacanay', 'Joel Santiago', 'Gemma Abella',
        'Benjie Abella', 'Virgilio Olivares', 'Philip Olivares', 'Elaine Dimaano',
        'Fritz Nepomuceno', 'Jun Florentino', 'Giselle Calderon', 'Bernard Mercado',
        'Cristina Hidalgo', 'Analyn Regalado', 'Benjie Quizon', 'Rogelio Umali',
        'Queenie Gallardo', 'Mika Bañez', 'Elaine Ignacio', 'Dexter Olivares',
        'Homer Zamora', 'Zaldy Rosales', 'Leonardo Nepomuceno', 'Dominic Briones',
        'Joel Katigbak', 'Kathleen Vergara', 'Ferdinand Francisco', 'Homer Estrada',
        'Dominic Sarmiento', 'Kathleen Abella', 'Teodoro Bañez', 'Marites Hipolito',
        'Virgilio Hidalgo', 'Dexter Dimaano', 'Evangeline Rosales', 'Leonardo Ignacio',
        'Carmela Vergara', 'Teodoro Lazaro', 'Marites Quizon', 'Winona Lualhati',
        'Philip Javier', 'Yvonne Javier', 'Zaldy Hipolito', 'Sarah Briones',
        'Joel Mercado', 'Ferdinand Florentino', 'Carmela Dacanay', 'Analyn Quizon',
        'Xander Calderon', 'Fritz Lazaro', 'Leonardo Umali', 'Sarah Hidalgo',
        'Marites Florentino', 'Sarah Sarmiento', 'Philip Yap', 'Ursula Gallardo',
        'Iris Lualhati', 'Lorenzo Zamora', 'Giselle Regalado', 'Lorenzo Pacheco',
        'Teodoro Escobar', 'Jun Pacheco', 'Xander Carpio', 'Analyn Lazaro',
        'Mika Escobar', 'Iris Estrada', 'Giselle Briones', 'Nestor Nolasco',
        'Yvonne Nolasco', 'Karen Nolasco', 'Jun Carpio', 'Herbert Carpio',
    ];

    /** First sentence of a destination review, by category. */
    private const DESTINATION_OPENERS = [
        'Beach' => [
            'A lovely beach to unwind and enjoy the sea breeze.',
            'We spent a relaxing afternoon by the shore here.',
            'The view of the sea was beautiful, especially later in the day.',
            'A nice beach for a laid-back day trip.',
            'Peaceful shoreline and a calm atmosphere.',
            'We enjoyed walking along the beach and taking photos.',
            'A good place to slow down and listen to the waves.',
        ],
        'Mountain' => [
            'The scenery from up here was worth the trip.',
            'Fresh air and a wide view of the surroundings.',
            'A great spot if you enjoy the outdoors and cooler weather.',
            'We loved the view and the quiet surroundings.',
            'The trip up was part of the adventure, and the view rewarded us.',
            'A refreshing escape from the lowland heat.',
        ],
        'Waterfall' => [
            'The falls were refreshing to see in person.',
            'A beautiful waterfall surrounded by greenery.',
            'The sound of the falling water was so relaxing.',
            'Seeing the falls up close was a highlight of our trip.',
            'A scenic spot that nature lovers will appreciate.',
            'The walk to the falls made the arrival even more rewarding.',
        ],
        'Historical' => [
            'A meaningful place to learn about local history.',
            'It was interesting to see a piece of Sorsogon\'s past.',
            'A great stop for anyone curious about the province\'s heritage.',
            'We appreciated how this place preserves local history.',
            'An educational visit that the whole family enjoyed.',
            'A good reminder of the rich history of the area.',
        ],
        'Church' => [
            'A peaceful church with a solemn atmosphere.',
            'We stopped by to pray and admire the old architecture.',
            'A beautiful place for quiet reflection.',
            'The church has a calm and welcoming feel.',
            'A meaningful stop during our visit to the province.',
            'Lovely to see such a well-loved place of worship.',
        ],
        'Park' => [
            'A pleasant park for an afternoon stroll.',
            'A nice open space to relax and people-watch.',
            'We enjoyed spending time here in the late afternoon.',
            'A good place to take a break while exploring the area.',
            'Clean and pleasant surroundings for a short visit.',
            'A favorite stop for a relaxing walk.',
            'Nice spot to sit, chat, and enjoy the breeze.',
        ],
        'Eco-Tourism' => [
            'A great way to enjoy nature in Sorsogon.',
            'We loved being surrounded by so much greenery.',
            'A memorable nature experience for the whole group.',
            'It was refreshing to see how this place is cared for.',
            'A must-visit for anyone who loves the outdoors.',
            'The natural surroundings here are truly beautiful.',
            'A calm and refreshing escape into nature.',
            'An experience that made us appreciate the local environment more.',
        ],
        'Island' => [
            'The island trip was a highlight of our visit.',
            'Beautiful views all around the island.',
            'Getting there was an adventure in itself.',
            'A scenic place for a day of exploring.',
            'We enjoyed the peaceful island atmosphere.',
            'A lovely escape from the busy town.',
        ],
        'Resort' => [
            'A relaxing place for a day out with family.',
            'We had a pleasant time unwinding here.',
            'A nice spot to rest and enjoy the surroundings.',
            'Good place for a weekend getaway with friends.',
            'A calm and enjoyable place to spend the day.',
            'We enjoyed our visit and the relaxing atmosphere.',
        ],
        'default' => [
            'A pleasant place to visit while in Sorsogon.',
            'We enjoyed our time here.',
            'A nice stop during our trip around the province.',
            'Worth a visit if you are in the area.',
            'A memorable part of our Sorsogon trip.',
        ],
    ];

    private const DESTINATION_CLOSERS = [
        'We visited with family and everyone enjoyed it.',
        'Best to go early in the day.',
        'Would definitely visit again.',
        'Worth including in your Sorsogon itinerary.',
        'Highly recommended for first-time visitors.',
        'The locals we met were friendly and helpful.',
        'Great for taking photos.',
        'Please help keep the place clean when you visit.',
        'We left with great memories.',
        'Perfect for a short break from the city.',
        'A good stop for groups and barkadas.',
        'We stayed longer than we planned.',
        'Bring water and wear comfortable footwear.',
        'It can get busy on weekends, so plan ahead.',
        'One of our favorite stops on this trip.',
        'Simple, beautiful, and relaxing.',
        'Our kids enjoyed it a lot.',
        'A nice experience overall.',
        'Thank you to the locals who look after this place.',
        'We are already planning to come back.',
        'Recommended for travelers who enjoy the outdoors.',
        'A good place to recharge.',
        'Glad we made time for this.',
        'Check the weather before heading out.',
        'Salamat po for the warm welcome!',
        'Definitely worth the visit.',
        'We would recommend it to friends.',
        'It made our visit to Sorsogon extra special.',
        'Bring a camera.',
        'A great addition to any Bicol trip.',
    ];

    private const FOOD_OPENERS = [
        'The food was tasty and served while still hot.',
        'We enjoyed our meal here.',
        'Good food and a relaxed place to eat.',
        'Generous servings for the price.',
        'A satisfying meal after a long day of touring.',
        'The dishes we ordered were full of flavor.',
        'A nice place to try local flavors.',
        'Our group left full and happy.',
        'Everything we ordered came out well.',
        'A cozy spot for a casual meal.',
    ];

    private const FOOD_CLOSERS = [
        'The staff were friendly and attentive.',
        'Good spot for a group meal.',
        'We would come back to try more of the menu.',
        'Service was quick even when it was busy.',
        'Recommended if you are in the area.',
        'The prices felt fair.',
        'A good stop for travelers passing through.',
        'Thank you for the warm service!',
        'Perfect for a relaxed lunch.',
        'We enjoyed the laid-back atmosphere.',
        'Great place for family meals.',
        'It can get busy during peak hours.',
        'We left satisfied.',
        'Worth a visit when you are hungry after exploring.',
        'Salamat po!',
    ];

    private const SHOP_OPENERS = [
        'A good place to buy pasalubong for family back home.',
        'We found nice local products to bring home.',
        'A great stop for souvenirs.',
        'It was fun browsing the local goods here.',
        'A nice place to support local makers.',
        'We picked up a few gifts for friends here.',
        'Plenty of choices for pasalubong shopping.',
        'A convenient stop for last-minute souvenirs.',
    ];

    private const SHOP_CLOSERS = [
        'The vendors were friendly and accommodating.',
        'Prices were reasonable.',
        'We will be back on our next trip.',
        'Recommended for travelers looking for local finds.',
        'The staff patiently answered our questions.',
        'Good for buying gifts in bulk.',
        'Easy to find during our trip.',
        'Thank you for the friendly service!',
        'Glad to support local businesses.',
        'It can be busy on weekends.',
        'A must-stop before heading home.',
        'Salamat po!',
        'We left with more than we planned to buy.',
        'Nice experience overall.',
    ];

    /** Score pattern cycled across reviews (mostly 4-5, some 3s). */
    private const SCORES = [5, 4, 5, 3, 4, 5, 4, 4, 5, 3, 5, 4];

    /** Comment pairs already used in this run, so no two reviews read the same. */
    private array $used = [];

    public function run(): void
    {
        // Demo data must never be seeded in production.
        if (app()->environment('production')) {
            throw new RuntimeException('Demo reviews cannot be seeded in production.');
        }

        $destinations = $this->verified(Destination::class, array_map(fn ($row) => $row[0], self::DESTINATIONS));
        $foodBeverages = $this->verified(FoodBeverage::class, self::FOOD_BEVERAGES);
        $shops = $this->verified(RetailShop::class, self::SHOPS);

        $touristRole = Role::where('name', 'tourist')->firstOrFail();

        DB::transaction(function () use ($destinations, $foodBeverages, $shops, $touristRole) {
            $reviewers = $this->reviewers($touristRole);
            $listing = 0;
            $created = 0;

            foreach ($destinations as $id => $destination) {
                $category = self::DESTINATIONS[$id][1];
                $openers = self::DESTINATION_OPENERS[$category] ?? self::DESTINATION_OPENERS['default'];
                $created += $this->seedListing($destination, $listing++, $reviewers, $openers, self::DESTINATION_CLOSERS);
            }

            foreach ($foodBeverages as $item) {
                $created += $this->seedListing($item, $listing++, $reviewers, self::FOOD_OPENERS, self::FOOD_CLOSERS);
            }

            foreach ($shops as $shop) {
                $created += $this->seedListing($shop, $listing++, $reviewers, self::SHOP_OPENERS, self::SHOP_CLOSERS);
            }

            $this->command?->info("Demo listing reviews: {$created} created.");
        });
    }

    /**
     * Loads the snapshot listings and confirms each still has the expected name.
     *
     * @param  class-string<Model>  $class
     * @param  array<int, string>  $expected
     */
    private function verified(string $class, array $expected): array
    {
        $models = $class::whereIn('id', array_keys($expected))->get()->keyBy('id');
        $result = [];

        foreach ($expected as $id => $name) {
            if ($models->get($id)?->name !== $name) {
                throw new RuntimeException(class_basename($class)." ID {$id} does not match the expected listing \"{$name}\".");
            }
            $result[$id] = $models->get($id);
        }

        return $result;
    }

    /** @return list<User> */
    private function reviewers(Role $touristRole): array
    {
        $users = [];

        foreach (self::REVIEWERS as $index => $name) {
            $email = sprintf('sosogon.demo.visitor%03d@example.com', $index + 1);
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'role_id' => $touristRole->id,
                    'name' => $name,
                    'password' => Hash::make(Str::random(48)),
                    'status' => 'active',
                ]
            );

            if ($user->role_id !== $touristRole->id || $user->name !== $name) {
                throw new RuntimeException("Demo email {$email} belongs to an unexpected user.");
            }

            $users[] = $user;
        }

        return $users;
    }

    /** Seeds up to five reviews on one listing; returns how many were created. */
    private function seedListing(Model $listing, int $position, array $reviewers, array $openers, array $closers): int
    {
        $created = 0;
        $count = count($reviewers);

        for ($j = 0; $j < 5; $j++) {
            // Stride through the pool so each listing gets five different people
            // and neighbouring listings do not share the same group.
            $user = $reviewers[($position * 5 + $j * 29) % $count];
            $n = $position * 5 + $j;

            $rating = Rating::firstOrCreate(
                [
                    'user_id' => $user->id,
                    'rateable_type' => $listing::class,
                    'rateable_id' => $listing->id,
                ],
                [
                    'score' => self::SCORES[$n % count(self::SCORES)],
                    'comment' => self::PREFIX.$this->comment($openers, $closers, $position, $j),
                ]
            );

            if ($rating->wasRecentlyCreated) {
                // Spread sample dates over roughly the past ten months.
                $date = now()->subDays(5 + ($n * 41) % 300)->subMinutes(($n * 97) % 600);
                $rating->timestamps = false;
                $rating->forceFill(['is_demo' => true, 'created_at' => $date, 'updated_at' => $date])->save();
                $created++;
            }
        }

        $this->refreshAggregates($listing);

        return $created;
    }

    /** Picks an opener/closer pair not yet used in this run. */
    private function comment(array $openers, array $closers, int $position, int $j): string
    {
        $opener = $openers[($position * 5 + $j) % count($openers)];

        for ($step = 0; $step < count($closers); $step++) {
            $closer = $closers[($position * 3 + $j * 7 + $step) % count($closers)];
            $key = $opener.'|'.$closer;

            if (! isset($this->used[$key])) {
                $this->used[$key] = true;

                return $opener.' '.$closer;
            }
        }

        throw new RuntimeException('Ran out of unique demo comments.');
    }

    /** Mirrors RatingController: stored aggregates include every rating, demo and genuine. */
    private function refreshAggregates(Model $listing): void
    {
        if (method_exists($listing, 'recalculateRating')) {
            $listing->recalculateRating();

            return;
        }

        // Retail shops have no stored aggregate columns; they are computed on read.
        if (! Schema::hasColumn($listing->getTable(), 'average_rating')) {
            return;
        }

        $listing->average_rating = $listing->ratings()->avg('score') ?? 0;
        $listing->total_reviews = $listing->ratings()->count();
        $listing->save();
    }
}

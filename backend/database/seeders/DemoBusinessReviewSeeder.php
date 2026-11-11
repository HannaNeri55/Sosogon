<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Local/demo only. Tops up every approved accommodation, food & beverage
 * establishment and shop to between 10 and 20 reviews, with a mix of 1-5 star
 * scores that differs from one business to the next. Pending and rejected
 * listings are not public, so they are skipped; run this again after approving
 * them.
 *
 * Reviews follow DemoListingReviewSeeder: they come from the same demo Tourist
 * accounts (sosogon.demo.visitor001-120@example.com), start with "Demo Review:"
 * and are flagged is_demo, so they are hidden in production (config/reviews.php)
 * and tagged "Sample" wherever they are shown.
 *
 * Idempotent: a listing that already has its target number of reviews (demo or
 * genuine) gets none, reviewers never repeat on a listing (the ratings unique
 * index also enforces this), and no comment is used twice anywhere. Existing
 * rows are never modified or deleted.
 */
class DemoBusinessReviewSeeder extends Seeder
{
    private const PREFIX = 'Demo Review: ';

    /**
     * Score mixes, as weights for 5, 4, 3, 2 and 1 stars. Each business gets one,
     * so averages range from about 4.4 down to about 3.0.
     */
    private const PROFILES = [
        'strong' => [5 => 55, 4 => 30, 3 => 10, 2 => 4, 1 => 1],
        'good' => [5 => 35, 4 => 38, 3 => 17, 2 => 7, 1 => 3],
        'mixed' => [5 => 22, 4 => 33, 3 => 25, 2 => 13, 1 => 7],
        'weak' => [5 => 12, 4 => 25, 3 => 30, 2 => 20, 1 => 13],
    ];

    /** How often each profile is picked, out of 100. */
    private const PROFILE_SHARE = ['strong' => 30, 'good' => 35, 'mixed' => 25, 'weak' => 10];

    /**
     * Comment parts by business type and score: [openers, details, closers].
     * A comment is an opener, one or two details and sometimes a closer.
     * Complaints stay about the visit itself (waiting, cleanliness, service)
     * rather than accusations such as overcharging.
     */
    private const PARTS = [
        'stay' => [
            5 => [
                [
                    'Our stay was excellent from check-in to check-out.',
                    'One of the best places we have stayed in Sorsogon.',
                    'We booked two nights and wished we had stayed longer.',
                    'Spotless room and a bed that was hard to leave in the morning.',
                    'Really happy we picked this place for our family trip.',
                    'Everything about our stay went smoothly.',
                    'Five stars without hesitation.',
                    'We arrived tired after a long bus ride and this place felt like a relief.',
                    'The room was even nicer than the photos.',
                    'Came here for our anniversary and it was perfect.',
                    'Booked at the last minute and it turned out to be a highlight of the trip.',
                    'My second time here and it was just as good as the first.',
                ],
                [
                    'The front desk staff remembered our names and kept checking if we needed anything.',
                    'Housekeeping kept the room tidy every day without us asking.',
                    'The bathroom was clean, the water was hot and the towels smelled fresh.',
                    'It was quiet at night, so we slept really well.',
                    'The staff gave us good tips on where to eat and how to get around.',
                    'Our kids loved the place and did not want to leave.',
                    'Check-in took only a few minutes even though we came early.',
                    'The surroundings were peaceful and green, perfect for resting.',
                    'They helped us arrange a tricycle to the town proper.',
                    'The aircon worked well and the room stayed cool all night.',
                    'The room was bigger than we expected and very well kept.',
                    'It was easy to reach the nearby tourist spots from here.',
                ],
                [
                    'We will definitely be back.',
                    'Highly recommended for families.',
                    'Salamat po sa mainit na pagtanggap!',
                    'Worth every peso.',
                    'Already telling our friends about it.',
                    'Thank you to the whole team!',
                    'A perfect base for exploring the province.',
                    'Cannot wait to come back next summer.',
                ],
            ],
            4 => [
                [
                    'A comfortable stay overall.',
                    'Good place to rest after a day of touring.',
                    'We had a pleasant two-night stay.',
                    'Clean and comfortable, which is all we needed.',
                    'Nice place with friendly staff.',
                    'A solid choice for travelers passing through Sorsogon.',
                    'We enjoyed our stay here.',
                    'Good value for what we paid.',
                    'The place was cozy and well kept.',
                    'No major complaints about our stay.',
                    'A relaxing stay with just a few small issues.',
                ],
                [
                    'The room was clean, though a bit smaller than we expected.',
                    'The Wi-Fi was slow in the evening but fine in the morning.',
                    'The staff were helpful whenever we asked for something.',
                    'Check-in took a while because several guests arrived at the same time.',
                    'The bed was comfortable and the room was quiet.',
                    'The hot water took a minute to run but worked fine after that.',
                    'The early morning view from our room was lovely.',
                    'Parking was a bit tight but manageable.',
                    'The bathroom could use some updating, but it was clean.',
                    'It was easy to find using the map.',
                    'There was some noise from the road during the day.',
                    'The staff were polite, though not always at the front desk.',
                ],
                [
                    'Would stay again.',
                    'Recommended for short stays.',
                    'Good for couples and small families.',
                    'We would come back if we are in the area.',
                    'Overall a good experience.',
                    'Thanks to the staff for the help.',
                ],
            ],
            3 => [
                [
                    'It was an okay stay.',
                    'Average experience overall.',
                    'Fine for one night, but I would not stay longer.',
                    'The place is decent, but there is room for improvement.',
                    'Mixed feelings about our stay.',
                    'Not bad, not great.',
                    'It served its purpose as a place to sleep.',
                    'We expected a bit more based on the photos.',
                ],
                [
                    'The room was clean enough, but some fixtures looked worn out.',
                    'The staff were polite, but it was hard to find anyone at the front desk at night.',
                    'The aircon was noisy and kept waking us up.',
                    'Check-in took almost half an hour.',
                    'The rate felt a little high for what we got.',
                    'Towels were only replaced when we asked.',
                    'The Wi-Fi barely worked in our room.',
                    'There were ants near the sink, which the staff cleared when we told them.',
                    'The shower pressure was weak.',
                    'Some of the lights in the room were not working.',
                ],
                [
                    'Hopefully they fix these soon.',
                    'Okay if you just need a place to stay overnight.',
                    'With a bit of maintenance this could be a nice place.',
                    'The staff did try their best.',
                ],
            ],
            2 => [
                [
                    'Disappointing stay.',
                    'Not what we expected.',
                    'I would not recommend this place yet.',
                    'Our stay had too many problems.',
                    'Below average for the price.',
                    'We had a hard time enjoying our stay here.',
                ],
                [
                    'The room smelled damp and the sheets did not feel fresh.',
                    'We waited a long time for our room even after the check-in time.',
                    'Our reservation details were mixed up when we arrived.',
                    'The bathroom was not cleaned properly before we checked in.',
                    'The staff seemed uninterested when we raised our concerns.',
                    'The hot water did not work for most of our stay.',
                    'It was very noisy until late at night.',
                    'The room was in poor condition for the rate.',
                ],
                [
                    'Management should look into housekeeping.',
                    'They need to improve before peak season.',
                    'Hope they listen to feedback.',
                ],
            ],
            1 => [
                [
                    'Very poor experience.',
                    'The worst part of our trip.',
                    'We checked out early.',
                    'Really unhappy with this stay.',
                    'I cannot recommend this place.',
                    'Our stay was a letdown from the start.',
                ],
                [
                    'The room was dirty and nobody came to clean it even after we complained.',
                    'Our booking could not be found and we had to look for another place late at night.',
                    'Both the aircon and the shower were broken and no one came to help.',
                    'We asked for help three times and were ignored each time.',
                    'There were insects in the room and the bedsheets were stained.',
                    'The staff were rude when we asked for a different room.',
                ],
                [
                    'Would not stay again.',
                    'Please improve your customer service.',
                    'Sadly we cannot recommend it.',
                ],
            ],
        ],
        'food' => [
            5 => [
                [
                    'Delicious food and generous servings.',
                    'Easily one of the best meals we had in Sorsogon.',
                    'We ate here twice during our trip because it was that good.',
                    'Everything we ordered was full of flavor.',
                    'The food came out hot and tasted homemade.',
                    'Great find, we will be back.',
                    'Our group of six left full and happy.',
                    'Loved the food and the friendly vibe.',
                    'Came here on a friend\'s recommendation and it did not disappoint.',
                    'The best meal of our Bicol trip.',
                ],
                [
                    'The local dishes we tried had just the right amount of spice.',
                    'The servers were quick and checked on us often.',
                    'Portions were big enough to share.',
                    'The seafood we ordered was fresh and well seasoned.',
                    'The place was clean and tables were wiped right away.',
                    'Our food arrived in about fifteen minutes even though it was busy.',
                    'The staff patiently explained the menu to us.',
                    'The house specialty is a must-try.',
                    'The drinks were refreshing after a hot day of touring.',
                    'Prices were very fair for the quality.',
                    'The rice was hot and the viands were cooked just right.',
                    'Even the simple dishes were done really well.',
                ],
                [
                    'Highly recommended!',
                    'Salamat po!',
                    'Will definitely come back.',
                    'A must when in Sorsogon.',
                    'Worth the trip.',
                    'Thank you to the kitchen staff!',
                ],
            ],
            4 => [
                [
                    'Good food and a relaxed atmosphere.',
                    'We enjoyed our lunch here.',
                    'Tasty meals at reasonable prices.',
                    'A nice place for a casual dinner.',
                    'The food was good overall.',
                    'A satisfying meal after a long drive.',
                    'A solid choice if you are in the area.',
                    'Nice spot for a family meal.',
                    'Good food and friendly people.',
                    'We liked most of what we ordered.',
                ],
                [
                    'We waited about twenty minutes for our orders, but the food was worth it.',
                    'One dish was a bit salty, but the rest were great.',
                    'Servings were just right for one person.',
                    'It gets crowded at lunch, so come early.',
                    'The staff were friendly, though a little slow when it got busy.',
                    'Prices are a bit higher than nearby eateries, but so is the quality.',
                    'Seating was limited, but we found a table.',
                    'The menu had a good variety.',
                    'The drinks took longer to arrive than the food.',
                    'The dining area was clean and well lit.',
                ],
                [
                    'Would eat here again.',
                    'Recommended for groups.',
                    'A good stop for travelers.',
                    'We will be back to try the rest of the menu.',
                ],
            ],
            3 => [
                [
                    'The food was okay.',
                    'Average meal, nothing special.',
                    'Some dishes were good, others not so much.',
                    'Fine for a quick meal.',
                    'Decent, but it could be better.',
                    'We expected more given what we had heard.',
                ],
                [
                    'Our orders took almost forty minutes to arrive.',
                    'The food was lukewarm when it was served.',
                    'Servings were small for the price.',
                    'The staff mixed up two of our orders.',
                    'The tables could have been cleaner.',
                    'A few items on the menu were not available.',
                    'The place was hot and stuffy at lunchtime.',
                    'The flavors were a bit bland for our taste.',
                ],
                [
                    'Might try again on a less busy day.',
                    'Okay if you are nearby.',
                    'Hope the service improves.',
                ],
            ],
            2 => [
                [
                    'Disappointing meal.',
                    'Not worth the wait.',
                    'We will not be back soon.',
                    'Below our expectations.',
                    'Not a good experience this time.',
                ],
                [
                    'We waited almost an hour and one order never arrived.',
                    'The food was cold and too oily.',
                    'The staff ignored us for a long time before taking our order.',
                    'Portions were tiny for what we paid.',
                    'The dining area was not clean and there were flies.',
                    'Half of what we wanted to order was not available.',
                ],
                [
                    'They need better kitchen management.',
                    'Service needs a lot of improvement.',
                ],
            ],
            1 => [
                [
                    'Very bad experience.',
                    'Really disappointed.',
                    'Not recommended.',
                    'The worst meal of our trip.',
                ],
                [
                    'We waited over an hour and had to cancel our order.',
                    'The food tasted stale and the staff did not seem to care.',
                    'We found a hair in our food and the staff just shrugged.',
                    'The server was rude when we asked about our order.',
                    'Our food came out cold and the replacement never came.',
                ],
                [
                    'Please train your staff.',
                    'We left hungry and annoyed.',
                    'Will not be coming back.',
                ],
            ],
        ],
        'shop' => [
            5 => [
                [
                    'A great place for pasalubong!',
                    'We found everything we wanted to bring home.',
                    'Loved shopping here.',
                    'My go-to stop for gifts every time I visit Sorsogon.',
                    'A wonderful selection of local products.',
                    'Such a nice shop for supporting local makers.',
                    'We spent longer here than planned because there was so much to see.',
                    'The best pasalubong stop on our trip.',
                ],
                [
                    'The local delicacies we bought were fresh and nicely packed.',
                    'The staff let us sample a few items before buying.',
                    'Prices were fair, especially for bulk orders.',
                    'The handicrafts looked well made.',
                    'The owner told us the story behind some of the products.',
                    'Everything was neatly arranged and easy to find.',
                    'They wrapped our items carefully for the long trip home.',
                    'The staff were cheerful and patient with all our questions.',
                    'There were plenty of choices for every budget.',
                    'The products were good quality for the price.',
                ],
                [
                    'Highly recommended!',
                    'Salamat po!',
                    'We will be back on our next trip.',
                    'A must-stop before heading home.',
                    'Our family loved the gifts.',
                    'Glad to support local businesses.',
                ],
            ],
            4 => [
                [
                    'A good selection of souvenirs.',
                    'Nice shop with friendly staff.',
                    'A convenient stop for pasalubong.',
                    'We bought a few gifts here and were happy with them.',
                    'Decent prices and good products.',
                    'Worth a quick stop.',
                    'A pleasant shopping experience.',
                    'Good place to pick up local snacks.',
                ],
                [
                    'Some items were a bit pricey, but the quality was good.',
                    'The shop is small, so it got crowded quickly.',
                    'A few products we wanted were out of stock.',
                    'The line at the counter was slow, but the staff were polite.',
                    'Parking nearby is limited.',
                    'Most items had clear price tags.',
                    'The packaging could be sturdier for travel.',
                    'The staff were helpful in recommending what to buy.',
                ],
                [
                    'Would shop here again.',
                    'Good for last-minute gifts.',
                    'Recommended for travelers.',
                    'Nice experience overall.',
                ],
            ],
            3 => [
                [
                    'It is an okay shop.',
                    'Average selection.',
                    'Fine for a quick stop.',
                    'Nothing special, but they have the basics.',
                    'Some good finds, but also a few letdowns.',
                    'It was alright.',
                ],
                [
                    'Prices seemed higher than in other shops nearby.',
                    'Many shelves were half empty when we came.',
                    'The staff were hard to approach.',
                    'Some items had no price tags.',
                    'A few of the products looked dusty.',
                    'There were fewer choices than we expected.',
                    'It took a while before anyone assisted us.',
                ],
                [
                    'Might be better on a weekday.',
                    'Okay if you are already nearby.',
                    'Hope they restock soon.',
                ],
            ],
            2 => [
                [
                    'A disappointing visit.',
                    'We would not shop here again.',
                    'Not worth the stop.',
                    'Below our expectations.',
                ],
                [
                    'Several items we bought were close to their expiry date.',
                    'The staff were not helpful when we asked about the products.',
                    'Most of the items we wanted were out of stock.',
                    'One of the items we bought broke within a day.',
                    'The place felt cramped and disorganized.',
                ],
                [
                    'They should check their stock more often.',
                    'Customer service needs improvement.',
                ],
            ],
            1 => [
                [
                    'Very poor experience.',
                    'Not recommended.',
                    'Really disappointed with this shop.',
                    'We regret stopping here.',
                ],
                [
                    'The staff were rude when we asked questions.',
                    'The delicacies we bought had already gone stale.',
                    'We were ignored at the counter for a long time.',
                    'The products we got were damaged and they refused to replace them.',
                ],
                [
                    'Please do better.',
                    'Will not come back.',
                    'Look elsewhere for pasalubong.',
                ],
            ],
        ],
    ];

    /** Comments already in the database or used in this run, so none repeat. */
    private array $used = [];

    private array $counts = ['checked' => 0, 'skipped_not_public' => 0, 'already_full' => 0, 'populated' => 0, 'created' => 0, 'scores' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0]];

    public function run(): void
    {
        // Demo data must never be seeded in production.
        if (app()->environment('production')) {
            throw new RuntimeException('Demo reviews cannot be seeded in production.');
        }

        $this->used = Rating::where('comment', 'like', self::PREFIX.'%')->pluck('comment')
            ->mapWithKeys(fn ($comment) => [Str::after($comment, self::PREFIX) => true])
            ->all();

        $touristRole = Role::where('name', 'tourist')->firstOrFail();

        DB::transaction(function () use ($touristRole) {
            $reviewers = $this->reviewers($touristRole);

            foreach ([Accommodation::class => 'stay', FoodBeverage::class => 'food', RetailShop::class => 'shop'] as $class => $kind) {
                foreach ($class::orderBy('id')->get() as $listing) {
                    $this->counts['checked']++;

                    if ($listing->status !== 'approved') {
                        $this->counts['skipped_not_public']++;

                        continue;
                    }

                    $this->seedListing($listing, $kind, $reviewers);
                }
            }
        });

        $c = $this->counts;
        $this->command?->info("Demo business reviews: {$c['checked']} businesses checked, {$c['skipped_not_public']} skipped (pending or rejected), "
            ."{$c['already_full']} already had enough reviews, {$c['populated']} topped up with {$c['created']} reviews.");
        $this->command?->info('New reviews by score: '.collect($c['scores'])->map(fn ($n, $score) => "{$score}★ {$n}")->implode(', ').'.');
    }

    /** @return list<User> */
    private function reviewers(Role $touristRole): array
    {
        $users = [];

        foreach (DemoListingReviewSeeder::REVIEWERS as $index => $name) {
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

    /** Adds reviews until the listing reaches its target count. */
    private function seedListing(Model $listing, string $kind, array $reviewers): void
    {
        // Seeded from the listing, so a rerun picks the same target, mix and people.
        $seed = crc32($listing::class.'#'.$listing->id);
        $random = new Randomizer(new Mt19937($seed));
        $target = 10 + $seed % 11;

        $existing = $listing->ratings()->pluck('user_id')->all();
        $needed = $target - count($existing);

        if ($needed <= 0) {
            $this->counts['already_full']++;

            return;
        }

        $scores = $this->scores($this->profile($seed), $needed, $random);
        $pool = array_values(array_filter($reviewers, fn (User $user) => ! in_array($user->id, $existing)));
        $pool = $random->shuffleArray($pool);

        foreach ($scores as $i => $score) {
            $rating = new Rating([
                'user_id' => $pool[$i]->id,
                'rateable_type' => $listing::class,
                'rateable_id' => $listing->id,
                'score' => $score,
                'comment' => self::PREFIX.$this->comment($kind, $score, $random),
            ]);

            // Spread sample dates over roughly the past eleven months.
            $date = now()->subDays($random->getInt(2, 330))->subMinutes($random->getInt(0, 900));
            $rating->timestamps = false;
            $rating->forceFill(['is_demo' => true, 'created_at' => $date, 'updated_at' => $date])->save();

            $this->counts['scores'][$score]++;
        }

        $this->counts['populated']++;
        $this->counts['created'] += count($scores);

        // Mirrors ratings:recalculate. Retail shops have no stored aggregates; they are computed on read.
        if ($listing instanceof Accommodation || $listing instanceof FoodBeverage) {
            $listing->forceFill([
                'average_rating' => round((float) ($listing->ratings()->visible()->avg('score') ?? 0), 2),
                'total_reviews' => $listing->ratings()->visible()->count(),
            ])->save();
        }
    }

    private function profile(int $seed): string
    {
        $roll = intdiv($seed, 11) % 100;

        foreach (self::PROFILE_SHARE as $profile => $share) {
            if ($roll < $share) {
                return $profile;
            }
            $roll -= $share;
        }

        return 'good';
    }

    /** Draws scores from the profile, making sure a batch of several has both praise and criticism. */
    private function scores(string $profile, int $count, Randomizer $random): array
    {
        $weights = self::PROFILES[$profile];
        $scores = [];

        for ($i = 0; $i < $count; $i++) {
            $roll = $random->getInt(1, array_sum($weights));
            foreach ($weights as $score => $weight) {
                if (($roll -= $weight) <= 0) {
                    $scores[] = $score;
                    break;
                }
            }
        }

        if ($count >= 5 && min($scores) >= 4) {
            $scores[$count - 1] = 3;
        }
        if ($count >= 5 && max($scores) <= 3) {
            $scores[0] = 4;
        }

        return $scores;
    }

    /** Builds a comment not used before: an opener, one or two details and sometimes a closer. */
    private function comment(string $kind, int $score, Randomizer $random): string
    {
        [$openers, $details, $closers] = self::PARTS[$kind][$score];

        for ($attempt = 0; $attempt < 200; $attempt++) {
            $picked = $random->pickArrayKeys($details, $random->getInt(1, 10) <= 3 ? 2 : 1);
            $sentences = [$openers[$random->getInt(0, count($openers) - 1)]];

            foreach ($random->shuffleArray($picked) as $key) {
                $sentences[] = $details[$key];
            }

            if ($random->getInt(1, 10) <= 6) {
                $sentences[] = $closers[$random->getInt(0, count($closers) - 1)];
            }

            $comment = implode(' ', $sentences);

            if (! isset($this->used[$comment])) {
                $this->used[$comment] = true;

                return $comment;
            }
        }

        throw new RuntimeException("Ran out of unique {$kind} comments for {$score}-star reviews.");
    }
}

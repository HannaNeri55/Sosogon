<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Puts every public listing's demo rating between 4.0 and 5.0:
 *  1. demo reviews of 1 to 3 stars become 4 or 5 stars, with a new comment that fits the score;
 *  2. listings with no review at all (destinations, accommodations, food & beverage, shops and
 *     shop products) get three to five reviews of 4 or 5 stars from the demo visitor pool
 *     (DemoListingReviewSeeder::REVIEWERS).
 * Like the other demo reviews, each one is flagged is_demo and its comment starts with
 * "Demo Review:" (stripped wherever reviews are shown), so SHOW_DEMO_REVIEWS=false hides them all
 * again (then run php artisan ratings:recalculate --include-bulan). Genuine reviews are never
 * changed.
 *
 * Production only when SEED_DEMO_REVIEWS_ON_LIVE=yes, for a site still used only for demos.
 * DEMO_PREVIEW=1 lists what would change and writes nothing. Idempotent: a rerun finds no demo
 * review below 4 stars and no listing without reviews.
 */
class DemoReviewFillSeeder extends Seeder
{
    private const PREFIX = 'Demo Review: ';

    /** Scores cycled per listing; every listing's average lands between 4.0 and 5.0. */
    private const SCORES = [5, 4, 5, 5, 4, 5, 4, 4, 5, 5, 4, 5];

    /** Listing types this seeder covers => the kind of comment they get. */
    private const KINDS = [
        Destination::class => 'destination',
        Accommodation::class => 'accommodation',
        FoodBeverage::class => 'food',
        RetailShop::class => 'shop',
        RetailProduct::class => 'product',
    ];

    private const DESTINATION_OPENERS = [
        'Beach' => [
            'The water was clear and the sand was clean when we went.',
            'A relaxing beach day for the whole family.',
            'We came early in the morning and had the shore almost to ourselves.',
            'Perfect place to swim and unwind after a long drive.',
            'The sunset here is something you should not miss.',
            'Calm waves, fresh sea breeze, and a very peaceful vibe.',
        ],
        'Island' => [
            'The boat ride was part of the fun and the island itself was beautiful.',
            'Crystal-clear water and great spots for photos.',
            'One of the highlights of our Sorsogon trip.',
            'Worth the trip across. The view from the shore is amazing.',
            'Quiet, clean, and so different from the busy beaches.',
        ],
        'Mountain' => [
            'The view from the top was worth every step.',
            'Cool air and a wide view of the whole area.',
            'We went for the sunrise and it was unforgettable.',
            'A refreshing break from the heat of the lowlands.',
            'The trail was manageable and the scenery kept getting better.',
        ],
        'Waterfall' => [
            'The water was cold and refreshing, exactly what we needed.',
            'A short walk through the trees and then this beautiful falls.',
            'Very relaxing. The sound of the water alone is worth the visit.',
            'The basin is great for a dip, and it was not too crowded.',
            'Nature at its best. The falls were strong when we visited.',
        ],
        'Historical' => [
            'A good place to learn about the history of the town.',
            'Interesting stop, especially if you like old architecture.',
            'We learned a lot here and the kids enjoyed it too.',
            'It is nice to see how the place is being preserved.',
            'A quiet and meaningful visit. Great for photos as well.',
        ],
        'Church' => [
            'Beautiful old church with a peaceful atmosphere.',
            'We heard Mass here and it was a very solemn experience.',
            'The architecture is stunning, both outside and inside.',
            'A must-visit if you appreciate heritage churches.',
            'Very peaceful. A good place to pause and reflect.',
        ],
        'Park' => [
            'A nice open space to walk around and relax.',
            'Great for an afternoon stroll with the family.',
            'Clean and well-kept, with a cool breeze in the late afternoon.',
            'The kids loved running around here.',
            'A simple but relaxing stop in town.',
        ],
        'Eco-Tourism' => [
            'A refreshing experience close to nature.',
            'Clean, green, and very relaxing.',
            'Great place to slow down and enjoy the outdoors.',
            'We enjoyed every minute here. The surroundings are beautiful.',
            'Perfect for nature lovers and families.',
        ],
        'Resort' => [
            'We spent the whole day here and the kids did not want to leave.',
            'Clean pools and a relaxing place to hang out.',
            'Great for family gatherings and barkada outings.',
            'Very relaxing stay. The place is well maintained.',
            'Good place to unwind on a weekend.',
        ],
        'default' => [
            'A really nice place to visit.',
            'We enjoyed our visit here very much.',
            'One of the places we liked most on our trip.',
            'Relaxing and worth the visit.',
            'A great stop for families and friends.',
        ],
    ];

    private const DESTINATION_CLOSERS = [
        'We will definitely come back.',
        'Highly recommended!',
        'Bring water and sunscreen.',
        'Best to go early to avoid the crowd.',
        'Locals were friendly and helpful.',
        'Sulit ang biyahe!',
        'Great for photos too.',
        'A must-see when you are in Sorsogon.',
        'Thank you to the staff who kept the place clean.',
        'Perfect for a day trip.',
        'Would love to bring the rest of the family next time.',
        'Please help keep it clean when you visit.',
    ];

    private const ACCOMMODATION_OPENERS = [
        'Clean room and comfortable bed.',
        'The staff were very accommodating from check-in to check-out.',
        'Good value for the price.',
        'We had a pleasant and quiet stay.',
        'Convenient location and friendly staff.',
        'The room was just as shown in the photos.',
        'Nice place to rest after a full day of exploring.',
        'Our family stay was comfortable and relaxing.',
    ];

    private const ACCOMMODATION_CLOSERS = [
        'Would stay here again.',
        'Highly recommended for families.',
        'Thank you for the warm welcome!',
        'Will definitely book again on our next visit.',
        'Sulit for the price.',
        'Perfect for a short stay in Sorsogon.',
        'The aircon was strong and the shower was good.',
        'Great base for our trips around the province.',
        'Everything we needed for a good night of sleep.',
        'Recommended to friends already.',
    ];

    private const FOOD_OPENERS = [
        'The food was delicious and served hot.',
        'Generous servings for the price.',
        'We tried a few dishes and all of them were good.',
        'Friendly staff and quick service.',
        'Nice place to eat with the family.',
        'Tasty food and a cozy place to dine.',
        'One of the best meals we had on our trip.',
        'Clean place and the food was flavorful.',
    ];

    private const FOOD_CLOSERS = [
        'We will be back!',
        'Masarap talaga!',
        'Highly recommended.',
        'Great for groups and barkada.',
        'Worth the stop.',
        'Affordable and filling.',
        'Try their bestsellers.',
        'Perfect after a day of exploring.',
        'Babalikan namin ito.',
        'Recommended to anyone visiting the area.',
    ];

    private const SHOP_OPENERS = [
        'Lots of pasalubong choices at fair prices.',
        'We bought gifts for the whole family here.',
        'The staff were friendly and helped us choose.',
        'Good place to buy local products.',
        'Nice selection of Sorsogon specialties.',
        'Our go-to stop for pasalubong.',
        'Products were fresh and well-packed.',
    ];

    private const SHOP_CLOSERS = [
        'Will buy again.',
        'Highly recommended for pasalubong.',
        'Sulit!',
        'Our relatives loved what we brought home.',
        'Support local!',
        'Worth the stop before heading home.',
        'Easy to find and quick to shop.',
        'Recommended to friends.',
    ];

    private const PRODUCT_OPENERS = [
        'Good quality for the price.',
        'Bought this as pasalubong and everyone liked it.',
        'Just as described.',
        'Nicely packed and easy to bring home.',
        'Our family enjoyed this a lot.',
        'Fresh and well made.',
    ];

    private const PRODUCT_CLOSERS = [
        'Will buy again.',
        'Recommended!',
        'Perfect for pasalubong.',
        'Sulit.',
        'Support local products!',
        'Ordering more next time.',
    ];

    /** Comment pairs used in this run, so the same review isn't repeated. */
    private array $used = [];

    public function run(): void
    {
        if (app()->environment('production') && env('SEED_DEMO_REVIEWS_ON_LIVE') !== 'yes') {
            throw new RuntimeException('Set SEED_DEMO_REVIEWS_ON_LIVE=yes to add demo reviews in production.');
        }
        $preview = (bool) env('DEMO_PREVIEW');

        // Stored averages below count these reviews, like the public pages will with SHOW_DEMO_REVIEWS=true.
        config(['reviews.show_demo' => true]);

        $this->raiseLowDemoReviews($preview);

        $groups = [
            'Destinations' => [Destination::published()->with('category:id,name'), 'destination'],
            'Accommodations' => [Accommodation::approved(), 'accommodation'],
            'Food & Beverage' => [FoodBeverage::approved(), 'food'],
            'Shops' => [RetailShop::approved(), 'shop'],
            'Shop products' => [RetailProduct::approved(), 'product'],
        ];

        $reviewers = $preview ? [] : $this->reviewers();
        $position = 0;

        foreach ($groups as $label => [$query, $kind]) {
            $listings = $query->whereDoesntHave('ratings')->orderBy('id')->get();
            $added = 0;
            foreach ($listings as $listing) {
                if ($preview) {
                    $added += $this->reviewCount($listing);

                    continue;
                }
                DB::transaction(function () use ($listing, $kind, $reviewers, $position, &$added) {
                    $added += $this->seedListing($listing, $kind, $reviewers, $position);
                });
                $position++;
            }
            $this->command?->info(sprintf('%s: %d without reviews, %d reviews %s.', $label, $listings->count(), $added, $preview ? 'would be added' : 'added'));
        }
    }

    /** Step 1: demo reviews of 1 to 3 stars become 4 or 5 stars with a comment that fits. */
    private function raiseLowDemoReviews(bool $preview): void
    {
        $low = Rating::where('is_demo', true)->where('score', '<', 4)
            ->whereIn('rateable_type', array_keys(self::KINDS))->orderBy('id')->get();
        if ($preview) {
            $this->command?->info("Demo reviews below 4 stars: {$low->count()} would become 4 or 5 stars.");

            return;
        }

        $raised = 0;
        foreach ($low->groupBy(fn (Rating $rating) => $rating->rateable_type.'#'.$rating->rateable_id) as $ratings) {
            $first = $ratings->first();
            $query = $first->rateable_type::query();
            $listing = ($first->rateable_type === Destination::class ? $query->with('category:id,name') : $query)->find($first->rateable_id);
            if (! $listing) {
                continue;
            }
            [$openers, $closers] = $this->pools($listing, self::KINDS[$first->rateable_type]);
            DB::transaction(function () use ($ratings, $listing, $openers, $closers, &$raised) {
                foreach ($ratings as $j => $rating) {
                    // Saved without touching updated_at: the review keeps its date.
                    $rating->timestamps = false;
                    $rating->forceFill([
                        'score' => $rating->id % 2 === 0 ? 4 : 5,
                        'comment' => self::PREFIX.$this->comment($openers, $closers, (int) $listing->id, (int) $j),
                    ])->save();
                    $raised++;
                }
                $this->refreshAverage($listing);
            });
        }
        $this->command?->info("Demo reviews below 4 stars: {$raised} raised to 4 or 5 stars.");
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function pools(Model $listing, string $kind): array
    {
        return match ($kind) {
            'destination' => [self::DESTINATION_OPENERS[$listing->category?->name] ?? self::DESTINATION_OPENERS['default'], self::DESTINATION_CLOSERS],
            'accommodation' => [self::ACCOMMODATION_OPENERS, self::ACCOMMODATION_CLOSERS],
            'food' => [self::FOOD_OPENERS, self::FOOD_CLOSERS],
            'shop' => [self::SHOP_OPENERS, self::SHOP_CLOSERS],
            default => [self::PRODUCT_OPENERS, self::PRODUCT_CLOSERS],
        };
    }

    /** Stored average and count from the reviews the public sees (shops and products keep none). */
    private function refreshAverage(Model $listing): void
    {
        if (method_exists($listing, 'recalculateRating')) {
            $listing->recalculateRating();
        } elseif (in_array('average_rating', $listing->getFillable(), true)) {
            $listing->forceFill(['average_rating' => round((float) $listing->ratings()->visible()->avg('score'), 2), 'total_reviews' => $listing->ratings()->visible()->count()])->save();
        }
    }

    /** @return list<User> */
    private function reviewers(): array
    {
        $role = Role::where('name', 'tourist')->firstOrFail();
        $users = [];
        foreach (DemoListingReviewSeeder::REVIEWERS as $index => $name) {
            $email = sprintf('sosogon.demo.visitor%03d@example.com', $index + 1);
            $user = User::firstOrCreate(['email' => $email], [
                'role_id' => $role->id, 'name' => $name, 'password' => Hash::make(Str::random(48)), 'status' => 'active',
            ]);
            if ((int) $user->role_id !== (int) $role->id) {
                throw new RuntimeException("{$email} belongs to an account that isn't a tourist.");
            }
            $users[] = $user;
        }

        return $users;
    }

    private function reviewCount(Model $listing): int
    {
        return 3 + ((int) $listing->id % 3);
    }

    private function seedListing(Model $listing, string $kind, array $reviewers, int $position): int
    {
        [$openers, $closers] = $this->pools($listing, $kind);

        // Dated after the listing was added, spread over up to ten months.
        $span = max(1, min(300, (int) $listing->created_at?->diffInDays(now()) ?: 1));
        $count = $this->reviewCount($listing);
        for ($j = 0; $j < $count; $j++) {
            $n = $position * 7 + $j;
            $rating = new Rating([
                'user_id' => $reviewers[($position * 5 + $j * 29) % count($reviewers)]->id,
                'rateable_type' => $listing::class,
                'rateable_id' => $listing->id,
                'score' => self::SCORES[($position + $j) % count(self::SCORES)],
                'comment' => self::PREFIX.$this->comment($openers, $closers, $position, $j),
            ]);
            $date = now()->subDays(($n * 41) % $span)->subMinutes(30 + ($n * 97) % 600);
            $rating->timestamps = false;
            $rating->forceFill(['is_demo' => true, 'created_at' => $date, 'updated_at' => $date])->save();
        }

        $this->refreshAverage($listing);

        return $count;
    }

    private function comment(array $openers, array $closers, int $position, int $j): string
    {
        $opener = $openers[($position * 3 + $j) % count($openers)];
        for ($step = 0; $step < count($closers); $step++) {
            $closer = $closers[($position * 5 + $j * 3 + $step) % count($closers)];
            if (! isset($this->used[$opener.'|'.$closer])) {
                $this->used[$opener.'|'.$closer] = true;

                return $opener.' '.$closer;
            }
        }

        // Every pair is used: a repeat is better than no review.
        return $opener.' '.$closers[($position + $j) % count($closers)];
    }
}

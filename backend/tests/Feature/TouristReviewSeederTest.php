<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\TouristReviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TouristReviewSeeder: a new round of mostly positive reviews on every public listing, from
 * ordinary-looking Tourist accounts, without touching existing reviews.
 */
class TouristReviewSeederTest extends TestCase
{
    use RefreshDatabase;

    private array $listings = [];

    private Rating $genuine;

    protected function setUp(): void
    {
        parent::setUp();

        $tourist = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        $town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['Beach' => 1, 'Historical' => 4] as $name => $id) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }
        $place = fn (string $name) => ['name' => $name, 'description' => "{$name} for testing.", 'municipality_id' => $town, 'address' => 'Test St.', 'latitude' => 12.67, 'longitude' => 123.87];
        $creator = User::create(['role_id' => $tourist, 'name' => 'Real Visitor', 'email' => 'real@example.com', 'password' => bcrypt('password')]);

        $this->listings = [
            'beach' => Destination::create($place('Test Beach') + ['category_id' => 1, 'created_by' => $creator->id, 'status' => 'published']),
            'church' => Destination::create($place('Old Church') + ['category_id' => 4, 'created_by' => $creator->id, 'status' => 'published', 'is_featured' => true]),
            'inn' => Accommodation::create($place('Test Inn') + ['type' => 'Inn', 'price_range' => 'moderate', 'status' => 'approved']),
            'cafe' => FoodBeverage::create($place('Kape Corner') + ['cuisine_type' => 'Cafe', 'price_range' => 'budget', 'status' => 'approved']),
            'shop' => RetailShop::create($place('Pili Pasalubong Shop') + ['status' => 'approved']),
        ];
        // Not public: never reviewed.
        $this->listings['draft'] = Destination::create($place('Draft Falls') + ['category_id' => 1, 'created_by' => $creator->id, 'status' => 'draft']);
        $this->listings['pending'] = FoodBeverage::create($place('Pending Grill') + ['cuisine_type' => 'Filipino', 'price_range' => 'budget', 'status' => 'pending']);

        // A genuine review the seeder must leave as it is.
        $this->genuine = Rating::create(['user_id' => $creator->id, 'rateable_type' => Destination::class, 'rateable_id' => $this->listings['beach']->id, 'score' => 4, 'comment' => 'Clean beach, we loved it.']);
    }

    private function runSeeder(): void
    {
        (new TouristReviewSeeder)->run();
    }

    private function reviews(string $key): int
    {
        $listing = $this->listings[$key];

        return Rating::where('rateable_type', $listing::class)->where('rateable_id', $listing->id)->count();
    }

    public function test_every_public_listing_gets_mostly_positive_reviews_from_ordinary_names(): void
    {
        $this->runSeeder();

        $this->assertGreaterThanOrEqual(1 + 4, $this->reviews('beach'), 'A listing with reviews gets 4 to 8 more.');
        $this->assertLessThanOrEqual(1 + 8, $this->reviews('beach'));
        foreach (['church', 'inn', 'cafe', 'shop'] as $key) {
            $this->assertGreaterThanOrEqual(8, $this->reviews($key), $key);
            $this->assertLessThanOrEqual(14, $this->reviews($key), $key);
        }
        $this->assertSame(0, $this->reviews('draft'));
        $this->assertSame(0, $this->reviews('pending'));

        $seeded = Rating::with('user')->where('id', '!=', $this->genuine->id)->get();
        $this->assertTrue($seeded->every(fn ($r) => $r->is_demo), 'Flagged, so SHOW_DEMO_REVIEWS=false hides them.');
        $this->assertTrue($seeded->every(fn ($r) => ! preg_match('/demo|sample|test|visitor/i', $r->user->name.' '.$r->user->email)));
        $this->assertTrue($seeded->every(fn ($r) => $r->user->role->name === 'tourist'));
        $this->assertGreaterThanOrEqual(0.7, $seeded->where('score', '>=', 4)->count() / $seeded->count(), 'Mostly 4 and 5 stars.');
        $comments = $seeded->pluck('comment')->filter();
        $this->assertSame($comments->count(), $comments->unique()->count(), 'No comment is used twice.');
        $this->assertTrue($comments->every(fn ($c) => str_starts_with($c, 'Demo Review: ') && ! str_contains($c, '{name}')));

        // The genuine review is untouched, and stored averages include the new ones.
        $this->assertSame('Clean beach, we loved it.', $this->genuine->fresh()->comment);
        $beach = $this->listings['beach']->fresh();
        $this->assertSame($this->reviews('beach'), (int) $beach->total_reviews);
        $this->assertEqualsWithDelta(Rating::where('rateable_type', Destination::class)->where('rateable_id', $beach->id)->avg('score'), (float) $beach->average_rating, 0.01);
    }

    public function test_running_it_again_adds_nothing_and_remove_takes_back_only_its_own(): void
    {
        $this->runSeeder();
        $total = Rating::count();
        $this->runSeeder();
        $this->assertSame($total, Rating::count());

        putenv('TOURIST_REVIEWS=remove');
        try {
            $this->runSeeder();
        } finally {
            putenv('TOURIST_REVIEWS');
        }

        $this->assertSame([$this->genuine->id], Rating::pluck('id')->all());
        $this->assertSame(1, User::count(), 'Only the real visitor is left.');
        $this->assertSame(1, (int) $this->listings['beach']->fresh()->total_reviews);
    }
}

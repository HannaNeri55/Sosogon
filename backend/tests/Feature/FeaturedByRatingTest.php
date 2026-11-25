<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Home's Featured accommodations, restaurants and shops (sort=top_rated): the best rated first. */
class FeaturedByRatingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $town;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::create(['role_id' => Role::create(['name' => 'business_owner', 'description' => 'Owner'])->id, 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function listing(string $class, string $name, array $extra = [])
    {
        return $class::create($extra + ['name' => $name, 'municipality_id' => $this->town, 'address' => 'Test St.', 'description' => 'A test listing.',
            'user_id' => $this->owner->id, 'status' => 'approved', 'latitude' => 12.7, 'longitude' => 124.0]);
    }

    /** Reviews with these scores; stays and restaurants keep the stored average the app keeps. */
    private function reviews($listing, array $scores, bool $demo = false): void
    {
        foreach ($scores as $score) {
            $user = User::create(['role_id' => $this->owner->role_id, 'name' => 'U'.uniqid(), 'email' => uniqid().'@example.com', 'password' => 'x']);
            $rating = Rating::create(['user_id' => $user->id, 'rateable_type' => $listing::class, 'rateable_id' => $listing->id, 'score' => $score, 'comment' => 'Nice.']);
            $rating->forceFill(['is_demo' => $demo])->save();
        }
        if (! $listing instanceof RetailShop) {
            $listing->forceFill(['average_rating' => $listing->ratings()->visible()->avg('score') ?? 0, 'total_reviews' => $listing->ratings()->visible()->count()])->save();
        }
    }

    private function names(string $url): array
    {
        return array_column($this->getJson($url)->assertOk()->json('data'), 'name');
    }

    public function test_the_best_rated_come_first_and_unrated_last(): void
    {
        foreach ([[Accommodation::class, '/api/v1/accommodations', ['type' => 'Hotel', 'price_range' => 'moderate']],
            [FoodBeverage::class, '/api/v1/food-beverages', ['price_range' => 'budget']],
            [RetailShop::class, '/api/v1/retail-shops', []]] as [$class, $url, $extra]) {
            $this->reviews($this->listing($class, 'Alpha '.$class, $extra), [4, 4]);
            $this->listing($class, 'Bravo '.$class, $extra);
            $this->reviews($this->listing($class, 'Charlie '.$class, $extra), [5, 5, 4]);
            $this->reviews($this->listing($class, 'Delta '.$class, $extra), [4, 4, 4, 4]);

            $this->assertSame(["Charlie {$class}", "Delta {$class}", "Alpha {$class}", "Bravo {$class}"], $this->names("{$url}?sort=top_rated"), $class);
            $this->assertSame(["Alpha {$class}", "Bravo {$class}", "Charlie {$class}", "Delta {$class}"], $this->names($url), "{$class}: other lists stay A to Z.");
        }
    }

    public function test_generated_reviews_do_not_rank_a_shop_where_they_are_hidden(): void
    {
        config(['reviews.show_demo' => false]);
        $this->reviews($this->listing(RetailShop::class, 'Alpha Shop'), [4]);
        $this->reviews($this->listing(RetailShop::class, 'Bravo Shop'), [5, 5], demo: true);

        $shops = $this->getJson('/api/v1/retail-shops?sort=top_rated')->assertOk()->json('data');
        $this->assertSame(['Alpha Shop', 'Bravo Shop'], array_column($shops, 'name'));
        $this->assertSame(0, $shops[1]['total_reviews']);
    }
}

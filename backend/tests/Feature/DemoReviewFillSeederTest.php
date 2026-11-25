<?php

namespace Tests\Feature;

use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoReviewFillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** DemoReviewFillSeeder: every public listing's demo rating ends between 4.0 and 5.0. */
class DemoReviewFillSeederTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $town;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'tourist', 'description' => 'Tourist']);
        $this->owner = User::create(['role_id' => Role::create(['name' => 'business_owner', 'description' => 'Owner'])->id, 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function restaurant(string $name): FoodBeverage
    {
        return FoodBeverage::create(['name' => $name, 'municipality_id' => $this->town, 'address' => 'Test St.', 'description' => 'A test listing.',
            'user_id' => $this->owner->id, 'status' => 'approved', 'price_range' => 'budget', 'latitude' => 12.7, 'longitude' => 124.0]);
    }

    private function review(FoodBeverage $place, int $score, string $comment, bool $demo): Rating
    {
        $user = User::create(['role_id' => $this->owner->role_id, 'name' => 'U'.uniqid(), 'email' => uniqid().'@example.com', 'password' => 'x']);
        $rating = Rating::create(['user_id' => $user->id, 'rateable_type' => FoodBeverage::class, 'rateable_id' => $place->id, 'score' => $score, 'comment' => $comment]);
        $rating->forceFill(['is_demo' => $demo])->save();

        return $rating;
    }

    public function test_low_demo_reviews_are_raised_and_unreviewed_listings_filled_while_genuine_ones_stay(): void
    {
        $reviewed = $this->restaurant('Reviewed Grill');
        $low = $this->review($reviewed, 2, 'Demo Review: Slow service and cold food.', true);
        $genuine = $this->review($reviewed, 3, 'It was okay.', false);
        $empty = $this->restaurant('New Cafe');

        $this->seed(DemoReviewFillSeeder::class);

        $low->refresh();
        $this->assertGreaterThanOrEqual(4, $low->score);
        $this->assertStringStartsWith('Demo Review: ', $low->comment);
        $this->assertStringNotContainsString('Slow service', $low->comment, 'The comment fits the new score.');
        $this->assertSame([3, 'It was okay.', false], [$genuine->fresh()->score, $genuine->fresh()->comment, $genuine->fresh()->is_demo]);

        $added = $empty->ratings()->get();
        $this->assertGreaterThanOrEqual(3, $added->count());
        $this->assertTrue($added->every(fn ($r) => $r->is_demo && $r->score >= 4 && str_starts_with($r->comment, 'Demo Review: ')));
        $this->assertGreaterThanOrEqual(4.0, (float) $empty->fresh()->average_rating);
        $this->assertLessThanOrEqual(5.0, (float) $empty->fresh()->average_rating);

        // Nothing left to do on a rerun.
        $count = Rating::count();
        $this->seed(DemoReviewFillSeeder::class);
        $this->assertSame($count, Rating::count());
    }

    public function test_production_needs_an_explicit_yes(): void
    {
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        app(DemoReviewFillSeeder::class)->run();
    }
}

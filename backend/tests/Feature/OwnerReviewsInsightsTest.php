<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Itinerary;
use App\Models\PageView;
use App\Models\Rating;
use App\Models\RatingReply;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The owner portal's Guest Reviews (summary, star filter, sorting, search) and the overview's
 * insights (page views, reviews, itineraries), for the owner's own listing only.
 */
class OwnerReviewsInsightsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Accommodation $inn;

    private Accommodation $otherInn;

    protected function setUp(): void
    {
        parent::setUp();

        $touristRole = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        $ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Business Owner'])->id;
        $town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $barangay = DB::table('barangays')->insertGetId(['municipality_id' => $town, 'name' => 'Zone 1', 'created_at' => now(), 'updated_at' => now()]);

        $this->owner = User::create(['role_id' => $ownerRole, 'name' => 'Inn Owner', 'email' => 'owner@example.com', 'password' => bcrypt('password'), 'business_type' => 'accommodation', 'status' => 'active']);
        $other = User::create(['role_id' => $ownerRole, 'name' => 'Other Owner', 'email' => 'other@example.com', 'password' => bcrypt('password'), 'business_type' => 'accommodation', 'status' => 'active']);
        $listing = fn (User $owner, string $name) => Accommodation::create([
            'user_id' => $owner->id, 'name' => $name, 'description' => "{$name} for testing.", 'type' => 'Inn',
            'municipality_id' => $town, 'barangay_id' => $barangay, 'address' => 'Test St.', 'latitude' => 12.67, 'longitude' => 123.87,
            'price_range' => 'moderate', 'status' => 'approved', 'view_count' => 40,
        ]);
        $this->inn = $listing($this->owner, 'Test Inn');
        $this->otherInn = $listing($other, 'Other Inn');

        // Five reviews on the owner's inn (5, 5, 4, 2, 1 stars), one answered, and one on someone else's.
        foreach ([[5, 'Lovely garden and friendly staff.'], [5, 'Spotless rooms.'], [4, 'Good value.'], [2, 'Noisy road at night.'], [1, 'Hot water never worked.']] as $n => [$score, $comment]) {
            $guest = User::create(['role_id' => $touristRole, 'name' => "Guest {$n}", 'email' => "guest{$n}@example.com", 'password' => bcrypt('password')]);
            $rating = Rating::create(['user_id' => $guest->id, 'rateable_type' => Accommodation::class, 'rateable_id' => $this->inn->id, 'score' => $score, 'comment' => $comment]);
            $rating->forceFill(['created_at' => now()->subDays($n * 20)])->save();
            if ($n === 0) {
                RatingReply::create(['rating_id' => $rating->id, 'user_id' => $this->owner->id, 'body' => 'Thank you!']);
            }
        }
        Rating::create(['user_id' => $guest->id, 'rateable_type' => Accommodation::class, 'rateable_id' => $this->otherInn->id, 'score' => 3, 'comment' => 'Not mine.']);
    }

    public function test_reviews_come_with_a_star_breakdown_and_response_rate(): void
    {
        $response = $this->actingAs($this->owner)->getJson('/api/v1/business/reviews')->assertOk();

        $response->assertJsonPath('summary.total', 5)
            ->assertJsonPath('summary.average', 3.4)
            ->assertJsonPath('summary.response_rate', 20)
            ->assertJsonPath('summary.by_rating.5', 2)
            ->assertJsonPath('summary.by_rating.3', 0)
            ->assertJsonPath('summary.by_rating.1', 1)
            ->assertJsonPath('summary.last_30_days', 2);
        $this->assertCount(5, $response->json('data'), "Another owner's review isn't listed.");
    }

    public function test_reviews_filter_by_stars_search_and_sort(): void
    {
        $get = fn (array $query) => $this->actingAs($this->owner)->getJson('/api/v1/business/reviews?'.http_build_query($query))->assertOk();

        $five = $get(['rating' => 5]);
        $this->assertSame([5, 5], array_column($five->json('data'), 'score'));
        $five->assertJsonPath('summary.total', 5, 'The summary still covers every review.');

        $this->assertSame('Noisy road at night.', $get(['search' => 'noisy'])->json('data.0.comment'));
        $this->assertSame([1, 2, 4, 5, 5], array_column($get(['sort' => 'lowest'])->json('data'), 'score'));
        $this->assertSame([5, 5, 4, 2, 1], array_column($get(['sort' => 'highest'])->json('data'), 'score'));
        $this->assertSame('Hot water never worked.', $get(['sort' => 'oldest'])->json('data.0.comment'));
        $this->assertSame(4, $get(['filter' => 'unreplied'])->json('total'));
        $this->actingAs($this->owner)->getJson('/api/v1/business/reviews?rating=9')->assertStatus(422);
    }

    public function test_insights_count_views_reviews_and_itineraries_for_the_owners_listing(): void
    {
        PageView::create(['date' => PageView::today(), 'path' => "/accommodations/{$this->inn->id}", 'views' => 7]);
        PageView::create(['date' => now('Asia/Manila')->subDays(3)->toDateString(), 'path' => "/accommodations/{$this->inn->id}", 'views' => 5]);
        PageView::create(['date' => now('Asia/Manila')->subDays(40)->toDateString(), 'path' => "/accommodations/{$this->inn->id}", 'views' => 4]);
        PageView::create(['date' => PageView::today(), 'path' => "/accommodations/{$this->otherInn->id}", 'views' => 99]);
        $saved = Itinerary::create(['user_id' => $this->owner->id, 'municipality_id' => $this->inn->municipality_id, 'title' => 'Trip', 'num_days' => 1, 'interests' => ['nature'], 'travel_pace' => 'moderate', 'budget' => 'moderate']);
        $saved->items()->create(['item_type' => 'accommodation', 'accommodation_id' => $this->inn->id, 'day_number' => 1, 'sequence' => 1]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/business/insights/accommodation')->assertOk();

        $response->assertJsonPath('views.last_30_days', 12)
            ->assertJsonPath('views.previous_30_days', 4)
            ->assertJsonPath('views.all_time', 40)
            ->assertJsonPath('reviews.total', 5)
            ->assertJsonPath('reviews.average', 3.4)
            ->assertJsonPath('itineraries.total', 1);
        $this->assertCount(30, $response->json('views.daily'));
        $this->assertSame(7, collect($response->json('views.daily'))->last()['views']);
        $this->assertCount(3, $response->json('reviews.recent'));

        $this->actingAs($this->owner)->getJson('/api/v1/business/insights/hotel')->assertNotFound();
    }
}

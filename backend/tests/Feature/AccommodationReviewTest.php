<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Rating;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccommodationReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private Accommodation $accommodation;

    private User $owner;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $touristRole = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        Role::create(['name' => 'business_owner', 'description' => 'Business Establishment Owner']);

        $municipalityId = DB::table('municipalities')->insertGetId([
            'name' => 'Bulan', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $barangayId = DB::table('barangays')->insertGetId([
            'municipality_id' => $municipalityId, 'name' => 'Zone 1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->tourist = User::create([
            'role_id' => $touristRole,
            'name' => 'Real Tourist',
            'email' => 'tourist@example.com',
            'password' => bcrypt('password'),
        ]);

        $ownerRole = Role::where('name', 'business_owner')->value('id');
        $this->owner = User::create([
            'role_id' => $ownerRole, 'name' => 'Inn Owner', 'email' => 'inn.owner@example.com',
            'password' => bcrypt('password'), 'business_type' => 'accommodation',
        ]);
        $this->otherOwner = User::create([
            'role_id' => $ownerRole, 'name' => 'Other Owner', 'email' => 'other.owner@example.com',
            'password' => bcrypt('password'), 'business_type' => 'accommodation',
        ]);

        $this->accommodation = Accommodation::create([
            'user_id' => $this->owner->id,
            'name' => 'Test Inn',
            'description' => 'A cozy inn used for automated testing purposes only.',
            'type' => 'Inn',
            'municipality_id' => $municipalityId,
            'barangay_id' => $barangayId,
            'address' => '123 Test St.',
            'latitude' => 12.6744,
            'longitude' => 123.8756,
            'price_range' => 'moderate',
            'status' => 'approved',
        ]);
    }

    private function seedDemoReviews(): void
    {
        foreach (range(1, 5) as $i) {
            $user = User::create([
                'role_id' => $this->tourist->role_id,
                'name' => "Demo Guest {$i}",
                'email' => "demo{$i}@example.com",
                'password' => bcrypt('password'),
            ]);
            Rating::create([
                'user_id' => $user->id,
                'rateable_type' => Accommodation::class,
                'rateable_id' => $this->accommodation->id,
                'score' => 4,
                'comment' => 'Demo Review: Sample stay.',
            ]);
        }
    }

    public function test_tourist_review_is_saved_and_shown_alongside_demo_reviews(): void
    {
        $this->seedDemoReviews();

        $this->actingAs($this->tourist)
            ->postJson('/api/v1/ratings', [
                'rateable_type' => 'accommodation',
                'rateable_id' => $this->accommodation->id,
                'score' => 5,
                'comment' => 'Loved the view.',
            ])
            ->assertCreated()
            ->assertJsonPath('rating.comment', 'Loved the view.');

        $this->assertDatabaseHas('ratings', [
            'user_id' => $this->tourist->id,
            'rateable_type' => Accommodation::class,
            'rateable_id' => $this->accommodation->id,
            'score' => 5,
            'comment' => 'Loved the view.',
        ]);

        $response = $this->getJson("/api/v1/accommodations/{$this->accommodation->id}")->assertOk();

        $ratings = collect($response->json('ratings'));
        $this->assertCount(6, $ratings);
        $this->assertSame(5, $ratings->filter(fn ($r) => str_starts_with($r['comment'], 'Demo Review:'))->count());
        // Newest first: the genuine review leads, unprefixed, with its reviewer.
        $this->assertSame('Loved the view.', $ratings->first()['comment']);
        $this->assertSame('Real Tourist', $ratings->first()['user']['name']);
        $this->assertEquals(6, $response->json('total_reviews'));
    }

    public function test_tourist_cannot_review_the_same_accommodation_twice(): void
    {
        $payload = ['rateable_type' => 'accommodation', 'rateable_id' => $this->accommodation->id, 'score' => 4];

        $this->actingAs($this->tourist)->postJson('/api/v1/ratings', $payload)->assertCreated();
        $this->actingAs($this->tourist)->postJson('/api/v1/ratings', $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'You have already rated this item.');
    }

    public function test_invalid_review_input_is_rejected(): void
    {
        $this->actingAs($this->tourist)
            ->postJson('/api/v1/ratings', ['rateable_type' => 'accommodation', 'rateable_id' => $this->accommodation->id, 'score' => 6])
            ->assertStatus(422)
            ->assertJsonValidationErrors('score');

        $this->actingAs($this->tourist)
            ->postJson('/api/v1/ratings', ['rateable_type' => 'accommodation', 'rateable_id' => $this->accommodation->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('score');

        $this->assertDatabaseCount('ratings', 0);
    }

    public function test_guests_and_non_tourists_cannot_submit_reviews(): void
    {
        $payload = ['rateable_type' => 'accommodation', 'rateable_id' => $this->accommodation->id, 'score' => 5];

        $this->postJson('/api/v1/ratings', $payload)->assertUnauthorized();

        $owner = User::create([
            'role_id' => Role::where('name', 'business_owner')->value('id'),
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
            'business_type' => 'accommodation',
        ]);

        $this->actingAs($owner)->postJson('/api/v1/ratings', $payload)->assertForbidden();

        $this->assertDatabaseCount('ratings', 0);
    }

    private function touristReview(): Rating
    {
        return Rating::create([
            'user_id' => $this->tourist->id,
            'rateable_type' => Accommodation::class,
            'rateable_id' => $this->accommodation->id,
            'score' => 4,
            'comment' => 'Nice stay.',
        ]);
    }

    public function test_owner_can_reply_and_edit_without_touching_the_review(): void
    {
        $rating = $this->touristReview();

        $this->actingAs($this->owner)
            ->postJson("/api/v1/business/reviews/{$rating->id}/reply", ['body' => 'Thank you for staying with us!'])
            ->assertCreated();

        $this->actingAs($this->owner)
            ->postJson("/api/v1/business/reviews/{$rating->id}/reply", ['body' => 'Second reply'])
            ->assertStatus(422);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/business/reviews/{$rating->id}/reply", ['body' => 'Thank you, see you again!'])
            ->assertOk();

        $this->assertDatabaseCount('rating_replies', 1);
        $this->assertDatabaseHas('rating_replies', [
            'rating_id' => $rating->id, 'user_id' => $this->owner->id, 'body' => 'Thank you, see you again!',
        ]);

        // The Tourist's original review is untouched.
        $this->assertDatabaseHas('ratings', ['id' => $rating->id, 'score' => 4, 'comment' => 'Nice stay.']);

        // The reply is returned under its review on the public page, without reviewer emails.
        $review = $this->getJson("/api/v1/accommodations/{$this->accommodation->id}")->json('ratings.0');
        $this->assertSame($rating->id, $review['id']);
        $this->assertSame('Thank you, see you again!', $review['reply']['body']);
        $this->assertSame(['id', 'name'], array_keys($review['user']));
    }

    public function test_only_the_listing_owner_can_reply(): void
    {
        $rating = $this->touristReview();
        $url = "/api/v1/business/reviews/{$rating->id}/reply";

        $this->postJson($url, ['body' => 'Hi'])->assertUnauthorized();
        $this->actingAs($this->tourist)->postJson($url, ['body' => 'Hi'])->assertForbidden();
        $this->actingAs($this->otherOwner)->postJson($url, ['body' => 'Hi'])->assertForbidden();

        $this->assertDatabaseCount('rating_replies', 0);

        $this->actingAs($this->owner)->postJson($url, ['body' => 'Thanks!'])->assertCreated();
        $this->actingAs($this->otherOwner)->putJson($url, ['body' => 'Hijack'])->assertForbidden();
        $this->assertDatabaseHas('rating_replies', ['rating_id' => $rating->id, 'body' => 'Thanks!']);
    }

    public function test_reply_requires_text(): void
    {
        $rating = $this->touristReview();

        $this->actingAs($this->owner)
            ->postJson("/api/v1/business/reviews/{$rating->id}/reply", ['body' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->actingAs($this->owner)
            ->putJson("/api/v1/business/reviews/{$rating->id}/reply", ['body' => 'No reply yet'])
            ->assertNotFound();
    }

    public function test_owner_dashboard_lists_only_their_own_reviews_with_filters(): void
    {
        $this->getJson('/api/v1/business/reviews')->assertUnauthorized();

        $answered = $this->touristReview();
        $this->seedDemoReviews();
        $this->actingAs($this->owner)
            ->postJson("/api/v1/business/reviews/{$answered->id}/reply", ['body' => 'Thanks!'])
            ->assertCreated();

        // A review on someone else's listing must not appear.
        $other = Accommodation::create([
            'user_id' => $this->otherOwner->id,
            'name' => 'Other Inn',
            'description' => 'Another inn used for automated testing purposes only.',
            'type' => 'Inn',
            'municipality_id' => $this->accommodation->municipality_id,
            'barangay_id' => $this->accommodation->barangay_id,
            'address' => '1 Other St.',
            'latitude' => 12.6,
            'longitude' => 123.8,
            'price_range' => 'budget',
            'status' => 'approved',
        ]);
        Rating::create(['user_id' => $this->tourist->id, 'rateable_type' => Accommodation::class, 'rateable_id' => $other->id, 'score' => 1, 'comment' => 'Not mine']);

        $all = $this->actingAs($this->owner)->getJson('/api/v1/business/reviews')->assertOk();
        $all->assertJsonPath('summary.total', 6)
            ->assertJsonPath('summary.replied', 1)
            ->assertJsonPath('summary.unreplied', 5)
            ->assertJsonCount(6, 'data');
        $this->assertNotContains('Not mine', array_column($all->json('data'), 'comment'));
        $this->assertSame(['id', 'name'], array_keys($all->json('data.0.user')));

        $this->actingAs($this->owner)->getJson('/api/v1/business/reviews?filter=unreplied')->assertJsonCount(5, 'data');
        $replied = $this->actingAs($this->owner)->getJson('/api/v1/business/reviews?filter=replied')->assertJsonCount(1, 'data');
        $this->assertSame('Thanks!', $replied->json('data.0.reply.body'));

        $this->actingAs($this->otherOwner)->getJson('/api/v1/business/reviews')->assertJsonPath('summary.total', 1);
        $this->actingAs($this->tourist)->getJson('/api/v1/business/reviews')->assertForbidden();
    }
}

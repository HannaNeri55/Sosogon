<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\ReviewReport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The admin review list (filters and summary) and the report workflow for reviews. */
class ReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private User $otherOwner;

    private User $ana;

    private User $ben;

    private FoodBeverage $grill;

    private Destination $beach;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $roles = collect(['admin', 'tourist', 'business_owner'])
            ->mapWithKeys(fn ($name) => [$name => Role::create(['name' => $name, 'description' => $name])->id]);
        $user = fn (string $role, string $name, array $extra = []) => User::create(['role_id' => $roles[$role], 'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com', 'password' => bcrypt('password'), 'status' => 'active'] + $extra);
        $this->admin = $user('admin', 'Admin One');
        $this->owner = $user('business_owner', 'Olivia Owner', ['business_type' => 'food_beverage']);
        $this->otherOwner = $user('business_owner', 'Other Owner', ['business_type' => 'food_beverage']);
        $this->ana = $user('tourist', 'Ana Reyes');
        $this->ben = $user('tourist', 'Ben Cruz');

        $town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $place = ['description' => 'A place for tests.', 'municipality_id' => $town, 'address' => 'Bulan', 'latitude' => 12.67, 'longitude' => 123.87];
        $this->grill = FoodBeverage::create($place + ['name' => 'Sea Grill', 'user_id' => $this->owner->id, 'price_range' => 'moderate', 'status' => 'approved']);
        $this->beach = Destination::create($place + ['name' => 'Otavi Beach', 'category_id' => $category, 'created_by' => $this->admin->id, 'status' => 'published']);
    }

    private function review(User $user, $listing, int $score, string $comment, ?string $date = null): Rating
    {
        $rating = Rating::create(['user_id' => $user->id, 'rateable_type' => $listing::class, 'rateable_id' => $listing->id, 'score' => $score, 'comment' => $comment]);
        if ($date) {
            $rating->forceFill(['created_at' => $date])->save();
        }

        return $rating;
    }

    public function test_filters_work_together_and_the_summary_counts_real_records(): void
    {
        $this->review($this->ana, $this->grill, 5, 'Great grilled squid.', '2026-11-01 10:00');
        $low = $this->review($this->ben, $this->grill, 1, 'Cold food and slow service.', '2026-11-05 10:00');
        $this->review($this->ana, $this->beach, 2, 'Too crowded on Sunday.', '2026-11-10 10:00');
        $this->actingAs($this->owner)->postJson("/api/v1/business/reviews/{$low->id}/reply", ['body' => 'Sorry, we have added staff.'])->assertCreated();

        $all = $this->actingAs($this->admin)->getJson('/api/v1/admin/guest-reviews')->assertOk();
        $all->assertJsonPath('summary.total', 3)->assertJsonPath('summary.average', 2.7)
            ->assertJsonPath('summary.negative', 2)->assertJsonPath('summary.unreplied', 2)
            ->assertJsonPath('summary.by_rating.1', 1)->assertJsonPath('summary.by_rating.5', 1);

        $get = fn (string $query) => $this->actingAs($this->admin)->getJson("/api/v1/admin/guest-reviews?{$query}")->assertOk();
        $this->assertSame(['Ben Cruz'], collect($get('rating=1')->json('data'))->pluck('user.name')->all());
        $this->assertSame(1, $get('search=squid')->json('total'), 'Searches review text.');
        $this->assertSame(2, $get('search=sea grill')->json('total'), 'Searches the listing name.');
        $this->assertSame(1, $get('search=ben')->json('total'), 'Searches the reviewer name.');
        $this->assertSame(1, $get('from=2026-11-04&to=2026-11-06')->json('total'));
        $this->assertSame(1, $get('type=food_beverage&filter=unreplied')->json('total'));
        $this->assertSame(0, $get('type=food_beverage&filter=unreplied&rating=1')->json('total'));
        $get('type=food_beverage')->assertJsonPath('summary.total', 2)->assertJsonPath('summary.average', 3);
        $get('rating=5')->assertJsonPath('summary.total', 3); // The summary describes the selection before rating/response.
        $get('search=Sea%20Grill&filter=replied')->assertJsonPath('data.0.reply.body', 'Sorry, we have added staff.');

        $this->actingAs($this->admin)->getJson('/api/v1/admin/guest-reviews?from=2026-11-10&to=2026-11-01')->assertUnprocessable();
    }

    public function test_an_owner_reports_a_review_with_evidence_and_only_on_their_own_listing(): void
    {
        $rating = $this->review($this->ana, $this->grill, 1, 'The owner is a thief, call him at 0917 123 4567.');
        $other = $this->review($this->ana, $this->beach, 1, 'Dirty.');
        $url = "/api/v1/business/reviews/{$rating->id}/report";

        $this->actingAs($this->otherOwner)->postJson($url, ['reason' => 'offensive', 'details' => 'Not my listing at all.'])->assertForbidden();
        $this->actingAs($this->owner)->postJson("/api/v1/business/reviews/{$other->id}/report", ['reason' => 'offensive', 'details' => 'Not my listing.'])->assertForbidden();
        $this->actingAs($this->owner)->postJson($url, ['reason' => 'made_up', 'details' => 'Short'])->assertUnprocessable();

        $this->actingAs($this->owner)->post($url, ['reason' => 'personal_information', 'details' => 'It names the owner and posts a phone number.',
            'evidence' => UploadedFile::fake()->create('screenshot.png', 200, 'image/png')], ['Accept' => 'application/json'])->assertCreated();
        $this->actingAs($this->owner)->postJson($url, ['reason' => 'offensive', 'details' => 'Reporting it again today.'])->assertUnprocessable();

        $report = ReviewReport::firstOrFail();
        $this->assertSame('pending', $report->status);
        Storage::disk('local')->assertExists($report->evidence_path);
        $this->assertNull($rating->fresh()->hidden_at, 'A report alone does not hide the review.');
        $this->assertSame(['Review reported'], $this->admin->notifications()->pluck('data')->pluck('title')->all());

        $this->actingAs($this->owner)->getJson('/api/v1/business/reviews')->assertOk()->assertJsonPath('data.0.latest_report.status', 'pending');
    }

    public function test_taking_action_hides_the_review_from_the_public_and_dismissing_restores_it(): void
    {
        $this->review($this->ben, $this->grill, 5, 'Lovely sunset dinner.');
        $rating = $this->review($this->ana, $this->grill, 1, 'Buy cheap phones at example.com!!!');
        $this->actingAs($this->owner)->postJson("/api/v1/business/reviews/{$rating->id}/report", ['reason' => 'spam', 'details' => 'It is an advertisement, not a review.'])->assertCreated();
        $report = ReviewReport::firstOrFail();
        $publicReviews = fn () => collect($this->getJson("/api/v1/food-beverages/{$this->grill->id}")->assertOk()->json('ratings'))->pluck('comment')->all();

        $this->actingAs($this->owner)->putJson("/api/v1/admin/review-reports/{$report->id}", ['status' => 'action_taken', 'decision_note' => 'x'])->assertForbidden();
        $this->actingAs($this->admin)->getJson('/api/v1/admin/review-reports')->assertOk()->assertJsonPath('counts.pending', 1)->assertJsonPath('data.0.rating.comment', $rating->comment);
        $this->actingAs($this->admin)->putJson("/api/v1/admin/review-reports/{$report->id}", ['status' => 'reviewed'])->assertOk();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/review-reports/{$report->id}", ['status' => 'action_taken'])->assertUnprocessable();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/review-reports/{$report->id}", ['status' => 'action_taken', 'decision_note' => 'Advertising is not allowed in reviews.'])->assertOk();

        $this->assertNotNull($rating->fresh()->hidden_at);
        $this->assertDatabaseHas('ratings', ['id' => $rating->id]); // Hidden, never deleted.
        $this->assertSame(['Lovely sunset dinner.'], $publicReviews());
        $this->assertEquals(5, $this->grill->fresh()->average_rating);
        $this->assertSame(1, (int) $this->grill->fresh()->total_reviews);
        $this->assertSame('Reported review hidden', $this->owner->notifications()->first()->data['title']);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/guest-reviews')->assertJsonPath('summary.total', 2); // Admins still see it.

        $this->actingAs($this->admin)->putJson("/api/v1/admin/review-reports/{$report->id}", ['status' => 'dismissed', 'decision_note' => 'On second look the link was removed.'])->assertOk();
        $this->assertNull($rating->fresh()->hidden_at);
        $this->assertCount(2, $publicReviews());
        $this->assertEquals(3, $this->grill->fresh()->average_rating);
    }

    public function test_evidence_is_only_for_admins(): void
    {
        $rating = $this->review($this->ana, $this->grill, 2, 'Rude staff.');
        $this->actingAs($this->owner)->post("/api/v1/business/reviews/{$rating->id}/report", ['reason' => 'false_information', 'details' => 'Our CCTV shows a different visit.',
            'evidence' => UploadedFile::fake()->create('cctv.pdf', 300, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        $id = ReviewReport::value('id');

        $this->actingAs($this->owner)->get("/api/v1/admin/review-reports/{$id}/evidence")->assertForbidden();
        $this->actingAs($this->admin)->get("/api/v1/admin/review-reports/{$id}/evidence")->assertOk();
    }
}

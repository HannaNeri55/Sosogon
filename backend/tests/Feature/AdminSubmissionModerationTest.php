<?php

namespace Tests\Feature;

use App\Mail\ListingWarningMail;
use App\Mail\SubmissionStatusMail;
use App\Models\BusinessSubmission;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminSubmissionModerationTest extends TestCase
{
    use RefreshDatabase;

    private int $municipalityId;

    private User $admin;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $adminRole = Role::create(['name' => 'admin', 'description' => 'Administrator']);
        $ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Business Establishment Owner']);
        Role::create(['name' => 'tourist', 'description' => 'Tourist']);

        $this->municipalityId = DB::table('municipalities')->insertGetId([
            'name' => 'Bulan', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->admin = User::create([
            'role_id' => $adminRole->id, 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
        ]);
        $this->owner = User::create([
            'role_id' => $ownerRole->id, 'name' => 'Owner', 'email' => 'owner@example.com',
            'password' => bcrypt('password'), 'business_type' => 'food_beverage',
        ]);
    }

    private function makeApprovedSubmission(): BusinessSubmission
    {
        $listing = FoodBeverage::create([
            'user_id' => $this->owner->id,
            'name' => 'Test Eatery',
            'description' => 'A test establishment.',
            'municipality_id' => $this->municipalityId,
            'address' => '123 Test St.',
            'latitude' => 12.6744,
            'longitude' => 123.8756,
            'price_range' => 'moderate',
            'status' => 'approved',
        ]);

        return BusinessSubmission::create([
            'user_id' => $this->owner->id,
            'business_name' => 'Test Eatery',
            'type' => 'food_beverage',
            'description' => 'A test establishment.',
            'address' => '123 Test St.',
            'municipality_id' => $this->municipalityId,
            'latitude' => 12.6744,
            'longitude' => 123.8756,
            'contact_number' => '09170000000',
            'business_permit_path' => 'business-permits/test.pdf',
            'listing_id' => $listing->id,
            'status' => 'approved',
        ]);
    }

    private function rate(int $listingId, int $score): void
    {
        $tourist = User::create([
            'role_id' => Role::where('name', 'tourist')->value('id'),
            'name' => 'Tourist', 'email' => uniqid().'@example.com', 'password' => bcrypt('password'),
        ]);

        Rating::create([
            'user_id' => $tourist->id,
            'rateable_type' => FoodBeverage::class,
            'rateable_id' => $listingId,
            'score' => $score,
        ]);
    }

    public function test_index_reports_negative_reviews_for_approved_listings(): void
    {
        $submission = $this->makeApprovedSubmission();
        $this->rate($submission->listing_id, 1);
        $this->rate($submission->listing_id, 2);
        $this->rate($submission->listing_id, 5);

        $item = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/submissions')
            ->assertOk()
            ->json('data.0');

        $this->assertSame(3, $item['reviews_count']);
        $this->assertSame(2, $item['negative_reviews_count']);
    }

    public function test_admin_can_warn_an_approved_business(): void
    {
        $submission = $this->makeApprovedSubmission();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/submissions/{$submission->id}/warn", ['notes' => 'Please respond to guests.'])
            ->assertOk();

        $submission->refresh();
        $this->assertNotNull($submission->warned_at);
        $this->assertSame('Please respond to guests.', $submission->warning_notes);
        $this->assertTrue($submission->warning_deadline->isSameDay(now()->addDays(BusinessSubmission::WARNING_GRACE_DAYS)));
        Mail::assertQueued(ListingWarningMail::class, fn ($mail) => $mail->hasTo('owner@example.com'));

        $this->actingAs($this->owner)
            ->getJson('/api/v1/business/dashboard')
            ->assertOk()
            ->assertJsonPath('warnings.0.id', $submission->id);
    }

    public function test_pending_submissions_cannot_be_warned_or_removed(): void
    {
        $submission = $this->makeApprovedSubmission();
        $submission->update(['status' => 'pending']);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/submissions/{$submission->id}/warn")
            ->assertStatus(409);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/submissions/{$submission->id}/remove", ['notes' => 'Reason'])
            ->assertStatus(409);
    }

    public function test_admin_can_clear_a_warning(): void
    {
        $submission = $this->makeApprovedSubmission();
        $submission->update(['warned_at' => now(), 'warning_notes' => 'Note']);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/submissions/{$submission->id}/clear-warning")
            ->assertOk();

        $submission->refresh();
        $this->assertNull($submission->warned_at);
        $this->assertNull($submission->warning_notes);
    }

    public function test_removing_requires_a_reason(): void
    {
        $submission = $this->makeApprovedSubmission();

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/submissions/{$submission->id}/remove")
            ->assertStatus(422);
    }

    public function test_admin_can_remove_an_approved_business(): void
    {
        $submission = $this->makeApprovedSubmission();
        $listingId = $submission->listing_id;
        $this->rate($listingId, 1);
        $submission->update(['warned_at' => now()->subDays(8)]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/admin/submissions/{$submission->id}/remove", ['notes' => 'Unresolved negative reviews.'])
            ->assertOk();

        $submission->refresh();
        $this->assertSame('removed', $submission->status);
        $this->assertNull($submission->listing_id);
        $this->assertNull($submission->warned_at);
        $this->assertSame('Unresolved negative reviews.', $submission->admin_notes);
        $this->assertNull(FoodBeverage::find($listingId));
        $this->assertSame(0, Rating::where('rateable_type', FoodBeverage::class)->where('rateable_id', $listingId)->count());
        Mail::assertQueued(SubmissionStatusMail::class, fn ($mail) => $mail->hasTo('owner@example.com'));

        $this->actingAs($this->owner)
            ->getJson('/api/v1/business/dashboard')
            ->assertOk()
            ->assertJsonPath('has_food_beverage', false)
            ->assertJsonPath('stats.removed', 1);
    }
}

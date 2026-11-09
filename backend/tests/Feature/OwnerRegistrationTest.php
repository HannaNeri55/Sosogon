<?php

namespace Tests\Feature;

use App\Models\BusinessOwnerApplication;
use App\Models\BusinessSubmission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Business Owner registration with a business permit, admin review, and owner gating. */
class OwnerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private int $town;

    private int $barangay;

    private User $admin;

    private int $ownerRole;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');

        $this->admin = User::create(['role_id' => Role::create(['name' => 'admin', 'description' => 'Admin'])->id,
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'), 'status' => 'active']);
        $this->ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Business Owner'])->id;
        Role::create(['name' => 'tourist', 'description' => 'Tourist']);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Irosin', 'created_at' => now(), 'updated_at' => now()]);
        $this->barangay = DB::table('barangays')->insertGetId(['municipality_id' => $this->town, 'name' => 'San Benon', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ana Reyes', 'email' => 'ana@example.com', 'phone' => '09170000000',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => 'business_owner', 'business_type' => 'accommodation',
            'business_name' => 'Hot Spring Inn', 'business_address' => 'Purok 1, San Benon, Irosin',
            'municipality_id' => $this->town, 'barangay_id' => $this->barangay,
            'business_permit_number' => 'BP-2026-0042',
            'business_permit_file' => UploadedFile::fake()->createWithContent('permit.pdf', '%PDF-1.4 permit of '.($overrides['email'] ?? 'ana@example.com')),
        ], $overrides);
    }

    private function register(array $overrides = []): BusinessOwnerApplication
    {
        $this->post('/api/v1/register', $this->registration($overrides), ['Accept' => 'application/json'])->assertCreated();

        return BusinessOwnerApplication::latest('id')->firstOrFail();
    }

    public function test_registration_requires_a_valid_business_permit(): void
    {
        $this->post('/api/v1/register', $this->registration(['business_permit_file' => null]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('business_permit_file');
        $this->post('/api/v1/register', $this->registration(['business_permit_file' => UploadedFile::fake()->create('permit.exe', 10, 'application/x-msdownload')]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('business_permit_file');
        $this->assertSame(0, User::where('email', 'ana@example.com')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_registration_stores_the_permit_privately_and_the_account_starts_pending(): void
    {
        $response = $this->post('/api/v1/register', $this->registration(['owner_id_number' => 'SECRET-ID-123']), ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonMissingPath('token');
        $application = BusinessOwnerApplication::firstOrFail();
        $this->assertSame('pending', $application->user->status);
        $this->assertSame('pending', $application->status);
        $this->assertSame('Hot Spring Inn', $application->business_name);
        Storage::disk('local')->assertExists($application->business_permit_path);
        $this->assertSame([], Storage::disk('public')->allFiles(), 'Permits must never be on the public disk.');
        // The owner ID fields were removed and are never stored.
        $this->assertStringNotContainsString('SECRET-ID-123', json_encode(DB::table('business_owner_applications')->get()).json_encode(DB::table('users')->get()));
        // The storage path is not exposed in API responses.
        $this->assertArrayNotHasKey('business_permit_path', $application->toArray());
    }

    public function test_pending_owners_cannot_sign_in_or_use_business_actions(): void
    {
        $application = $this->register();

        $this->postJson('/api/v1/login', ['email' => 'ana@example.com', 'password' => 'password123'])->assertForbidden();
        $this->actingAs($application->user)->getJson('/api/v1/business/dashboard')->assertForbidden()->assertJson(['code' => 'owner_not_approved']);
        $this->actingAs($application->user)->postJson('/api/v1/business/submissions', [])->assertForbidden();
        // Their own application stays reachable.
        $this->actingAs($application->user)->getJson('/api/v1/business/application')->assertOk()->assertJsonPath('application.status', 'pending');
    }

    public function test_only_admins_can_view_any_owners_permit(): void
    {
        $first = $this->register();
        $second = $this->register(['email' => 'ben@example.com', 'business_name' => 'Lake View Inn']);

        $admin = $this->actingAs($this->admin)->get("/api/v1/admin/owner-applications/{$first->id}/permit");
        $admin->assertOk();
        $this->assertStringStartsWith('inline', $admin->headers->get('content-disposition'));
        $this->assertSame('application/pdf', $admin->headers->get('content-type'));
        $this->assertSame(Storage::disk('local')->get($first->business_permit_path), $admin->streamedContent());

        // A permit file missing from the private disk is a clear 404, not a server error.
        Storage::disk('local')->move($second->business_permit_path, 'moved-away.pdf');
        $this->actingAs($this->admin)->get("/api/v1/admin/owner-applications/{$second->id}/permit")->assertNotFound();
        Storage::disk('local')->move('moved-away.pdf', $second->business_permit_path);

        $this->actingAs($second->user)->get("/api/v1/admin/owner-applications/{$first->id}/permit")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/admin/owner-applications/{$first->id}/permit")->assertUnauthorized();

        // An owner's own-permit route has no ID: it can only ever return their own file.
        $own = $this->actingAs($second->user)->get('/api/v1/business/application/permit');
        $own->assertOk();
        $this->assertSame(Storage::disk('local')->get($second->business_permit_path), $own->streamedContent());
        $this->assertStringNotContainsString('ana@example.com', $own->streamedContent(), 'Never another owner\'s permit.');

        // The admin listing shows applications but never the private path.
        $list = $this->actingAs($this->admin)->getJson('/api/v1/admin/owner-applications')->assertOk()->json();
        $this->assertCount(2, $list);
        $this->assertStringNotContainsString('business-permits', json_encode($list));
    }

    public function test_admin_approval_activates_the_account_once(): void
    {
        $application = $this->register();

        $this->actingAs($this->admin)->putJson("/api/v1/admin/owner-applications/{$application->id}/approve")->assertOk();
        $this->assertSame('active', $application->user->fresh()->status);
        $this->actingAs($this->admin)->putJson("/api/v1/admin/owner-applications/{$application->id}/approve")->assertStatus(409);

        $this->app['auth']->shouldUse('web'); // a real login request starts unauthenticated
        $this->postJson('/api/v1/login', ['email' => 'ana@example.com', 'password' => 'password123'])->assertOk();
        $this->actingAs($application->user->fresh(), 'sanctum')->getJson('/api/v1/business/dashboard')->assertOk();
    }

    public function test_rejection_requires_a_reason_and_the_owner_can_correct_and_resubmit(): void
    {
        $application = $this->register();

        $this->actingAs($this->admin)->putJson("/api/v1/admin/owner-applications/{$application->id}/reject", [])->assertUnprocessable();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/owner-applications/{$application->id}/reject", ['reason' => 'The permit is expired. Please upload the 2026 permit.'])->assertOk();
        $this->assertSame('pending', $application->user->fresh()->status, 'A rejected account is never activated.');

        // The rejected owner may sign in, sees the reason, but still can't use business actions.
        $this->app['auth']->shouldUse('web'); // a real login request starts unauthenticated
        $this->postJson('/api/v1/login', ['email' => 'ana@example.com', 'password' => 'password123'])->assertOk()->assertJsonPath('user.application_status', 'rejected');
        $owner = $application->user->fresh();
        $this->actingAs($owner)->getJson('/api/v1/business/dashboard')->assertForbidden();
        $this->actingAs($owner)->getJson('/api/v1/business/application')->assertJsonPath('application.rejection_reason', 'The permit is expired. Please upload the 2026 permit.');

        $oldPermit = $application->fresh()->business_permit_path;
        $this->actingAs($owner)->post('/api/v1/business/application/resubmit', [
            'business_name' => 'Hot Spring Inn', 'business_address' => 'Purok 1, San Benon, Irosin', 'municipality_id' => $this->town,
            'business_permit_number' => 'BP-2026-0099', 'business_permit_file' => UploadedFile::fake()->createWithContent('permit-2026.pdf', '%PDF-1.4 renewed permit'),
        ], ['Accept' => 'application/json'])->assertOk();

        $fresh = $application->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame('BP-2026-0099', $fresh->business_permit_number);
        Storage::disk('local')->assertExists($fresh->business_permit_path);
        Storage::disk('local')->assertMissing($oldPermit);
        // Back under review: signing in is blocked again, and it can't be resubmitted twice.
        $this->actingAs($owner)->post('/api/v1/business/application/resubmit', [], ['Accept' => 'application/json'])->assertStatus(409);
    }

    public function test_status_toggle_cannot_bypass_registration_review(): void
    {
        $application = $this->register();

        $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$application->user_id}", ['status' => 'active'])->assertUnprocessable();
        $this->assertSame('pending', $application->user->fresh()->status);

        // Owners who registered before applications existed keep the old activation path.
        $legacy = User::create(['role_id' => $this->ownerRole, 'name' => 'Legacy', 'email' => 'legacy@example.com', 'password' => bcrypt('x'), 'status' => 'pending']);
        $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$legacy->id}", ['status' => 'active'])->assertOk();
    }

    public function test_owners_cannot_approve_registrations(): void
    {
        $application = $this->register();
        $other = $this->register(['email' => 'ben@example.com']);
        $this->actingAs($this->admin)->putJson("/api/v1/admin/owner-applications/{$other->id}/approve")->assertOk();

        $this->actingAs($other->user->fresh())->putJson("/api/v1/admin/owner-applications/{$application->id}/approve")->assertForbidden();
        $this->assertSame('pending', $application->fresh()->status);
    }

    public function test_the_registration_permit_is_reused_for_the_listing_submission(): void
    {
        $application = $this->register();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/owner-applications/{$application->id}/approve")->assertOk();
        $owner = $application->user->fresh();

        $submission = [
            'business_name' => 'Hot Spring Inn', 'type' => 'accommodation',
            'description' => str_repeat('A family inn beside the hot springs of San Benon. ', 2),
            'address' => 'Purok 1, San Benon, Irosin', 'municipality_id' => $this->town,
            'latitude' => 12.70, 'longitude' => 124.03, 'contact_number' => '09170000000',
        ];
        // No permit attached: the registration permit satisfies the requirement.
        $this->actingAs($owner)->post('/api/v1/business/submissions', $submission, ['Accept' => 'application/json'])->assertCreated();

        $created = BusinessSubmission::firstOrFail();
        $this->assertSame($application->fresh()->business_permit_path, $created->business_permit_path);
        $this->assertCount(1, Storage::disk('local')->allFiles(), 'Nothing is uploaded twice.');

        // Withdrawing the submission never deletes the shared registration permit.
        $this->actingAs($owner)->deleteJson("/api/v1/business/submissions/{$created->id}")->assertOk();
        Storage::disk('local')->assertExists($application->fresh()->business_permit_path);

        // Owners without a registration permit on file still upload one.
        $legacy = User::create(['role_id' => $this->ownerRole, 'name' => 'Legacy', 'email' => 'legacy@example.com', 'password' => bcrypt('x'), 'status' => 'active', 'business_type' => 'accommodation']);
        $this->actingAs($legacy)->post('/api/v1/business/submissions', $submission, ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('business_permit');
    }
}

<?php

namespace Tests\Feature;

use App\Models\AccountActionLog;
use App\Models\AccountAppeal;
use App\Models\AccountViolation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/**
 * Account enforcement end to end: warnings, temporary suspension (with automatic restore),
 * deactivation, reactivation, appeals, the guarded closure step, server-side restrictions,
 * notifications, audit log and protections.
 */
class AccountEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $otherAdmin;

    private User $tourist;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $roles = collect(['admin', 'tourist', 'business_owner'])
            ->mapWithKeys(fn ($name) => [$name => Role::create(['name' => $name, 'description' => $name])->id]);
        $user = fn (string $role, string $email, array $extra = []) => User::create(array_merge([
            'role_id' => $roles[$role], 'name' => ucfirst($role).' '.$email, 'email' => $email, 'password' => bcrypt('password'), 'status' => 'active',
        ], $extra));

        $this->admin = $user('admin', 'admin@example.com');
        $this->otherAdmin = $user('admin', 'admin2@example.com');
        $this->tourist = $user('tourist', 'tourist@example.com');
        $this->owner = $user('business_owner', 'owner@example.com', ['business_type' => 'food_beverage']);
    }

    private function enforce(User $target, array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->post("/api/v1/admin/users/{$target->id}/enforcement", array_merge([
            'action' => 'warning',
            'category' => 'spam',
            'reason' => 'Repeated promotional posts',
            'details' => 'Posted the same promotional message in several reviews within one day.',
            'confirmed' => '1',
        ], $overrides), ['Accept' => 'application/json']);
    }

    /** A real sign-in request (resets the guard that actingAs() switched for earlier calls). */
    private function login(string $email)
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->postJson('/api/v1/login', ['email' => $email, 'password' => 'password']);
    }

    /** Requests with a real bearer token (so token revocation and middleware are exercised). */
    private function asBearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_the_old_instant_delete_endpoint_is_gone_and_the_user_remains(): void
    {
        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/users/{$this->tourist->id}")->assertStatus(405);
        $this->assertNotNull($this->tourist->fresh());
    }

    public function test_status_cannot_be_toggled_directly(): void
    {
        $this->actingAs($this->admin)->putJson("/api/v1/admin/users/{$this->tourist->id}", ['status' => 'suspended'])->assertUnprocessable();
        $this->assertSame('active', $this->tourist->fresh()->status);
    }

    public function test_a_warning_keeps_the_account_active_and_is_recorded_logged_and_notified(): void
    {
        $this->enforce($this->tourist)->assertCreated()->assertJsonPath('account_state', 'warning');

        $user = $this->tourist->fresh();
        $this->assertSame('active', $user->status);
        $this->assertSame(User::STATE_WARNING, $user->accountState());
        $this->assertFalse($user->isRestricted());

        $violation = AccountViolation::sole();
        $this->assertSame([$this->admin->id, 'warning', 'active'], [$violation->reviewed_by, $violation->action, $violation->status]);
        $this->assertNotNull($violation->reviewed_at);
        $this->assertTrue($violation->ends_at->isFuture(), 'Warnings expire after a period.');

        $log = AccountActionLog::sole();
        $this->assertSame(['issued_warning', 'active', 'warning', $this->admin->id], [$log->action, $log->previous_status, $log->new_status, $log->admin_id]);

        $notice = $user->notifications()->sole();
        $this->assertSame('warning', $notice->data['kind']);
        $this->assertSame('Repeated promotional posts', $notice->data['reason']);

        // Warned users keep normal access.
        $this->actingAs($user)->getJson('/api/v1/itineraries')->assertOk();
    }

    public function test_suspension_revokes_sessions_restricts_the_api_and_restores_access_automatically(): void
    {
        $token = $this->tourist->createToken('auth-token')->plainTextToken;
        $this->asBearer($token)->getJson('/api/v1/itineraries')->assertOk();

        $this->enforce($this->tourist, ['action' => 'suspension', 'suspension_days' => 3, 'evidence_files' => [UploadedFile::fake()->image('proof.jpg')]])
            ->assertCreated()->assertJsonPath('account_state', 'suspended');

        // The existing session no longer works.
        $this->asBearer($token)->getJson('/api/v1/itineraries')->assertUnauthorized();

        // Signing in again works, but only the Account Health area is reachable.
        $login = $this->login('tourist@example.com')
            ->assertOk()->assertJsonPath('user.account_state', 'suspended');
        $limited = $login->json('token');
        $this->asBearer($limited)->getJson('/api/v1/itineraries')->assertForbidden()->assertJsonPath('code', 'account_restricted');
        $this->asBearer($limited)->getJson('/api/v1/account/status')->assertOk()->assertJsonPath('account_state', 'suspended');

        // Evidence is private: admins can download it, the user cannot reach the admin endpoint.
        $violation = AccountViolation::sole();
        $this->actingAs($this->admin)->get("/api/v1/admin/account-violations/{$violation->id}/evidence/0")->assertOk();
        $this->asBearer($limited)->get("/api/v1/admin/account-violations/{$violation->id}/evidence/0", ['Accept' => 'application/json'])->assertForbidden();

        // After the period ends, signing in again (the old session has expired by now) restores access by itself.
        $this->travel(3)->days();
        $this->travel(1)->minutes();
        $fresh = $this->login('tourist@example.com')->assertOk()->assertJsonPath('user.account_state', 'active')->json('token');
        $this->asBearer($fresh)->getJson('/api/v1/itineraries')->assertOk();

        $user = $this->tourist->fresh();
        $this->assertSame(['active', null], [$user->status, $user->suspended_until]);
        $this->assertSame('expired', $violation->fresh()->status, 'The record is kept and marked expired.');
        $this->assertTrue(AccountActionLog::where('action', 'suspension_expired')->whereNull('admin_id')->exists());
        $this->assertSame(['suspension', 'suspension_ended'], $user->notifications()->reorder()->oldest()->get()->pluck('data.kind')->all());
    }

    public function test_the_hourly_command_also_ends_expired_suspensions(): void
    {
        $this->enforce($this->owner, ['action' => 'suspension', 'suspension_days' => 1])->assertCreated();
        $this->travel(2)->days();

        $this->artisan('accounts:expire-suspensions')->assertSuccessful();

        $this->assertSame('active', $this->owner->fresh()->status);
    }

    public function test_deactivation_blocks_business_routes_until_an_admin_reactivates_with_a_reason(): void
    {
        $this->enforce($this->owner, ['action' => 'deactivation', 'category' => 'fake_or_misleading_listing', 'review_date' => now()->addWeek()->toDateString()])
            ->assertCreated()->assertJsonPath('account_state', 'deactivated');

        $owner = $this->owner->fresh();
        $this->assertNull($owner->suspended_until);
        $this->actingAs($owner)->getJson('/api/v1/business/dashboard')->assertForbidden()->assertJsonPath('code', 'account_restricted');

        $this->travel(400)->days();
        $this->assertSame(User::STATE_DEACTIVATED, $owner->fresh()->accountState(), 'Deactivation does not expire.');

        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$owner->id}/reactivate", [])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$owner->id}/reactivate", ['reason' => 'Listing details corrected and verified.'])->assertOk();

        $this->assertSame('active', $owner->fresh()->status);
        $this->assertSame('lifted', AccountViolation::sole()->status);
        $this->assertContains('reactivation', $owner->fresh()->notifications()->get()->pluck('data.kind')->all());
    }

    public function test_appeal_flow_approved_reverses_the_action_and_restores_access(): void
    {
        $this->enforce($this->tourist, ['action' => 'suspension', 'suspension_days' => 30])->assertCreated();
        $violation = AccountViolation::sole();
        $tourist = $this->tourist->fresh();

        $this->actingAs($tourist)->post("/api/v1/account/violations/{$violation->id}/appeal", ['explanation' => 'Too short'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('explanation');
        $this->actingAs($tourist)->post("/api/v1/account/violations/{$violation->id}/appeal", [
            'explanation' => 'The posts were from a shared family account; I have changed my password and removed them.',
            'attachments' => [UploadedFile::fake()->create('statement.pdf', 50, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->actingAs($tourist)->postJson("/api/v1/account/violations/{$violation->id}/appeal", ['explanation' => str_repeat('Second appeal attempt. ', 3)])->assertStatus(409);
        $this->actingAs($this->owner)->postJson("/api/v1/account/violations/{$violation->id}/appeal", ['explanation' => str_repeat('Not my violation at all. ', 3)])->assertNotFound();

        $appeal = AccountAppeal::sole();
        $this->actingAs($tourist)->get("/api/v1/account/appeals/{$appeal->id}/attachments/0")->assertOk();
        $this->actingAs($this->admin)->get("/api/v1/admin/account-appeals/{$appeal->id}/attachments/0")->assertOk();

        $this->actingAs($this->admin)->getJson('/api/v1/admin/account-appeals?status=pending')->assertOk()->assertJsonPath('data.0.id', $appeal->id);
        $this->actingAs($this->admin)->postJson("/api/v1/admin/account-appeals/{$appeal->id}/decision", ['decision' => 'approved', 'note' => 'Evidence shows the account was misused by someone else.'])->assertOk();

        $tourist->refresh();
        $this->assertSame(['active', null], [$tourist->status, $tourist->suspended_until]);
        $this->assertSame('reversed', $violation->fresh()->status);
        $this->assertSame([$this->admin->id, 'approved'], [$appeal->fresh()->decided_by, $appeal->fresh()->status]);
        $this->assertContains('appeal_approved', $tourist->notifications()->get()->pluck('data.kind')->all());
        $this->actingAs($tourist)->getJson('/api/v1/itineraries')->assertOk();

        // Decided once only; the record stays on the user's Account Health page.
        $this->actingAs($this->admin)->postJson("/api/v1/admin/account-appeals/{$appeal->id}/decision", ['decision' => 'rejected', 'note' => 'Changing my mind later on.'])->assertStatus(409);
        $this->actingAs($tourist)->getJson('/api/v1/account/status')->assertOk()
            ->assertJsonPath('violations.0.status', 'reversed')
            ->assertJsonPath('violations.0.appeal.status', 'approved');
    }

    public function test_a_rejected_appeal_keeps_the_restriction(): void
    {
        $this->enforce($this->tourist, ['action' => 'deactivation'])->assertCreated();
        $violation = AccountViolation::sole();
        $this->actingAs($this->tourist->fresh())->postJson("/api/v1/account/violations/{$violation->id}/appeal", ['explanation' => 'I did nothing wrong and would like the decision reviewed again.'])->assertCreated();

        $this->actingAs($this->admin)->postJson('/api/v1/admin/account-appeals/'.AccountAppeal::sole()->id.'/decision', ['decision' => 'rejected', 'note' => 'The evidence clearly shows repeated violations.'])->assertOk();

        $this->assertSame(User::STATE_DEACTIVATED, $this->tourist->fresh()->accountState());
        $this->assertSame('active', $violation->fresh()->status);
        $this->assertContains('appeal_rejected', $this->tourist->fresh()->notifications()->get()->pluck('data.kind')->all());
    }

    public function test_admins_and_self_are_protected_and_only_admins_can_enforce(): void
    {
        $this->enforce($this->otherAdmin)->assertForbidden();
        $this->enforce($this->admin)->assertForbidden();
        $this->enforce($this->owner, [], $this->tourist)->assertForbidden();
        $this->actingAs($this->tourist)->getJson('/api/v1/admin/account-violations')->assertForbidden();
        $this->actingAs($this->owner)->getJson('/api/v1/admin/account-log')->assertForbidden();
        $this->assertSame(0, AccountViolation::count());
    }

    public function test_enforcement_requires_complete_reviewed_details_and_confirmation(): void
    {
        $this->enforce($this->tourist, ['action' => 'suspension'])->assertUnprocessable()->assertJsonValidationErrors('suspension_days');
        $this->enforce($this->tourist, ['confirmed' => ''])->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->enforce($this->tourist, ['category' => 'made_up'])->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->enforce($this->tourist, ['details' => 'short'])->assertUnprocessable()->assertJsonValidationErrors('details');
        $this->enforce($this->tourist, ['evidence_files' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertUnprocessable()->assertJsonValidationErrors('evidence_files.0');
        $this->assertSame(0, AccountViolation::count());
        $this->assertSame('active', $this->tourist->fresh()->status);
    }

    public function test_closing_an_account_needs_the_email_confirmation_and_keeps_all_data(): void
    {
        $this->enforce($this->tourist)->assertCreated();
        $this->tourist->ratings()->create(['rateable_type' => 'App\\Models\\Destination', 'rateable_id' => 1, 'score' => 5, 'comment' => 'Lovely beach.']);

        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$this->tourist->id}/close", ['reason' => 'User requested account deletion.', 'confirm_email' => 'wrong@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_email');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/users/{$this->tourist->id}/close", ['reason' => 'User requested account deletion.', 'confirm_email' => 'TOURIST@example.com'])
            ->assertOk();

        $user = $this->tourist->fresh();
        $this->assertNotNull($user->closed_at);
        $this->assertSame(1, $user->ratings()->count(), 'Reviews are kept.');
        $this->assertSame(1, $user->violations()->count(), 'Violation history is kept.');
        $this->login('tourist@example.com')->assertForbidden()->assertJsonPath('code', 'account_closed');

        // Hidden from the default list, shown under the closed filter.
        $this->actingAs($this->admin)->getJson('/api/v1/admin/users')->assertJsonMissing(['email' => 'tourist@example.com']);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/users?state=closed')->assertJsonPath('data.0.account_state', 'closed');
    }

    public function test_the_audit_log_cannot_be_changed_and_users_cannot_change_their_own_records(): void
    {
        $this->enforce($this->tourist)->assertCreated();
        $log = AccountActionLog::sole();

        $this->expectException(LogicException::class);
        $log->update(['reason' => 'tampered']);
    }

    public function test_the_audit_log_cannot_be_deleted(): void
    {
        $this->enforce($this->tourist)->assertCreated();

        $this->expectException(LogicException::class);
        AccountActionLog::sole()->delete();
    }

    public function test_admin_lists_show_states_records_and_filters(): void
    {
        $this->enforce($this->tourist)->assertCreated();
        $this->enforce($this->owner, ['action' => 'suspension', 'suspension_days' => 7, 'category' => 'fake_reviews'])->assertCreated();

        $users = collect($this->actingAs($this->admin)->getJson('/api/v1/admin/users')->assertOk()->json('data'))->keyBy('email');
        $this->assertSame('warning', $users['tourist@example.com']['account_state']);
        $this->assertSame('suspended', $users['owner@example.com']['account_state']);
        $this->assertSame('active', $users['admin@example.com']['account_state']);

        $this->actingAs($this->admin)->getJson('/api/v1/admin/account-violations?action=suspension')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.email', 'owner@example.com')->assertJsonPath('data.0.reviewer.name', $this->admin->name);
        $this->actingAs($this->admin)->getJson('/api/v1/admin/account-violations?role=tourist&q=tourist')->assertJsonCount(1, 'data');
        $this->actingAs($this->admin)->getJson('/api/v1/admin/account-violations?appeal=none')->assertJsonCount(2, 'data');

        $details = $this->actingAs($this->admin)->getJson("/api/v1/admin/users/{$this->owner->id}/account")->assertOk();
        $details->assertJsonPath('user.account_state', 'suspended')->assertJsonCount(1, 'violations')->assertJsonCount(1, 'account_log');

        $this->actingAs($this->admin)->getJson('/api/v1/admin/account-log?action=issued_suspension')->assertJsonCount(1, 'data');
    }
}

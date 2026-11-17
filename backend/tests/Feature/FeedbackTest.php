<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\FeedbackSubmission;
use App\Models\FoodBeverage;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feedback & Concerns: anyone can send feedback (anonymously too) and gets a reference number;
 * nothing is shown publicly; the office responsible for the listing handles it, general feedback
 * goes to the provincial admins, and only admins with access can read and update it.
 */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private int $adminRole;

    private int $bulan;

    private int $irosin;

    private User $provincial;

    private User $bulanAdmin;

    private User $tourist;

    private Destination $beach;

    private FoodBeverage $grill;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminRole = Role::create(['name' => 'admin', 'description' => 'Admin'])->id;
        $touristRole = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        $this->bulan = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $this->irosin = DB::table('municipalities')->insertGetId(['name' => 'Irosin', 'created_at' => now(), 'updated_at' => now()]);
        $this->provincial = $this->admin(User::ADMIN_SCOPE_PROVINCIAL);
        // Bulan has its own tourism office; Irosin has none, so the provincial admins handle it.
        $this->bulanAdmin = $this->admin(User::ADMIN_SCOPE_MUNICIPAL, $this->bulan);
        AdminScope::forgetOwnOffices();
        $this->tourist = User::create(['role_id' => $touristRole, 'name' => 'Ana Reyes', 'email' => 'ana@example.com', 'password' => 'Secret123!', 'status' => 'active']);

        $category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $place = ['description' => 'Test.', 'address' => 'Test St.', 'latitude' => 12.6, 'longitude' => 123.9];
        $this->beach = Destination::create($place + ['name' => 'Otavi Beach', 'municipality_id' => $this->bulan, 'category_id' => $category, 'created_by' => $this->provincial->id, 'status' => 'published']);
        $this->grill = FoodBeverage::create($place + ['name' => 'Irosin Grill', 'municipality_id' => $this->irosin, 'user_id' => $this->tourist->id, 'price_range' => 'budget', 'status' => 'approved']);
    }

    private function admin(string $scope, ?int $municipality = null): User
    {
        $user = User::create(['role_id' => $this->adminRole, 'name' => 'Admin '.uniqid(), 'email' => uniqid().'@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $user->forceFill(['admin_scope' => $scope, 'municipality_id' => $municipality])->save();

        return $user->fresh();
    }

    private function send(array $fields = [], ?string $ip = null)
    {
        $request = $ip ? $this->withServerVariables(['REMOTE_ADDR' => $ip]) : $this;

        return $request->postJson('/api/v1/feedback', $fields + [
            'category' => 'suggestion',
            'subject' => 'Add a map of jeepney routes',
            'message' => 'It would help visitors to see which jeepneys go to the beaches.',
        ]);
    }

    public function test_a_guest_can_send_feedback_anonymously_and_gets_a_reference_number(): void
    {
        $response = $this->send()->assertCreated()->assertJsonPath('duplicate', false);

        $reference = $response->json('reference');
        $this->assertMatchesRegularExpression('/^FB-\d{8}-[A-HJ-NP-Z2-9]{4}$/', $reference);
        $this->assertStringContainsString($reference, $response->json('message'));
        $saved = FeedbackSubmission::sole();
        $this->assertSame([$reference, null, null, null, 'pending', null], [$saved->reference, $saved->name, $saved->email, $saved->user_id, $saved->status, $saved->municipality_id]);
        // The address itself is never stored, only a keyed hash of it.
        $this->assertSame(64, strlen($saved->ip_hash));
        $this->assertStringNotContainsString('127.0.0.1', json_encode(DB::table('feedback_submissions')->first()));
    }

    public function test_required_fields_and_an_email_for_a_reply_are_checked(): void
    {
        $this->postJson('/api/v1/feedback', [])->assertUnprocessable()->assertJsonValidationErrors(['category', 'subject', 'message']);
        $this->send(['category' => 'not_a_category'])->assertUnprocessable()->assertJsonValidationErrors('category');
        $this->send(['message' => 'Too short'])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->send(['wants_reply' => true])->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'Please enter your email address so we can reply.']);
        $this->send(['email' => 'not-an-email'])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame(0, FeedbackSubmission::count());
        $this->send(['wants_reply' => true, 'email' => 'visitor@example.com', 'name' => 'Visitor'])->assertCreated();
    }

    public function test_feedback_about_a_place_belongs_to_its_town_and_only_public_places_are_accepted(): void
    {
        $this->send(['category' => 'incorrect_pricing', 'listing_type' => 'destination', 'listing_id' => $this->beach->id, 'module' => 'events'])->assertCreated();
        $saved = FeedbackSubmission::sole();
        $this->assertSame(['destinations', 'destination', $this->beach->id, $this->bulan], [$saved->module, $saved->listing_type, $saved->listing_id, $saved->municipality_id]);

        $this->beach->update(['status' => 'draft']);
        $this->send(['subject' => 'Another one', 'listing_type' => 'destination', 'listing_id' => $this->beach->id])
            ->assertUnprocessable()->assertJsonValidationErrors('listing_id');
        $this->send(['subject' => 'No id', 'listing_type' => 'destination'])->assertUnprocessable()->assertJsonValidationErrors('listing_id');
    }

    public function test_the_same_message_sent_twice_is_saved_once_but_different_senders_on_one_network_are_not_blocked(): void
    {
        $first = $this->send([], '10.0.0.5')->assertCreated()->json('reference');
        $this->send(['subject' => '  add a MAP of jeepney routes '], '10.0.0.5')->assertOk()
            ->assertJsonPath('duplicate', true)->assertJsonPath('reference', $first);
        $this->assertSame(1, FeedbackSubmission::count());

        // Someone else on the same network writing something else.
        $this->send(['subject' => 'Broken link on the Events page', 'message' => 'The Kasanggayahan link does not open.'], '10.0.0.5')->assertCreated();
        // A signed-in tourist on that network sending the same words is a different sender.
        $this->actingAs($this->tourist)->send([], '10.0.0.5')->assertCreated();
        $this->assertSame(3, FeedbackSubmission::count());
        $this->assertSame($this->tourist->id, FeedbackSubmission::latest('id')->first()->user_id);
    }

    public function test_bots_filling_the_hidden_field_are_turned_away(): void
    {
        $this->send(['contact_preference_check' => 'http://spam.example'])->assertUnprocessable();
        $this->assertSame(0, FeedbackSubmission::count());
    }

    public function test_too_many_messages_in_a_minute_are_slowed_down_with_a_clear_message(): void
    {
        // Correcting mistakes on the form does not count.
        foreach (range(1, 8) as $n) {
            $this->send(['message' => 'Short'], '10.0.0.9')->assertUnprocessable();
        }
        foreach (range(1, 5) as $n) {
            $this->send(['subject' => "Suggestion {$n}"], '10.0.0.9')->assertCreated();
        }
        $this->send(['subject' => 'Suggestion 6'], '10.0.0.9')->assertStatus(429)
            ->assertJsonPath('message', 'You have sent several messages in a short time. Please wait a little and try again.');
        // Another network is not affected.
        $this->send(['subject' => 'Suggestion 7'], '10.0.0.10')->assertCreated();
    }

    public function test_general_feedback_reaches_the_provincial_admins_and_place_feedback_its_own_office(): void
    {
        $this->send()->assertCreated();
        $this->assertSame(['feedback'], $this->provincial->notifications()->get()->pluck('data.kind')->all());
        $this->assertSame(0, $this->bulanAdmin->notifications()->count());

        $this->send(['subject' => 'Wrong fee', 'listing_type' => 'destination', 'listing_id' => $this->beach->id])->assertCreated();
        $this->assertSame(1, $this->bulanAdmin->notifications()->count());
        $this->assertSame(1, $this->provincial->notifications()->count(), 'Bulan has its own office.');

        $this->send(['subject' => 'Closed now', 'listing_type' => 'food_beverage', 'listing_id' => $this->grill->id])->assertCreated();
        $this->assertSame(2, $this->provincial->notifications()->count(), 'Irosin has no office of its own.');
    }

    public function test_each_admin_sees_and_handles_only_the_feedback_in_their_care(): void
    {
        $this->send()->assertCreated();
        $this->send(['subject' => 'Wrong fee', 'listing_type' => 'destination', 'listing_id' => $this->beach->id])->assertCreated();
        $general = FeedbackSubmission::where('subject', 'Add a map of jeepney routes')->sole();
        $bulan = FeedbackSubmission::where('subject', 'Wrong fee')->sole();

        $this->actingAs($this->bulanAdmin)->getJson('/api/v1/admin/feedback?status=all')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bulan->id)->assertJsonPath('open_count', 1);
        $this->actingAs($this->bulanAdmin)->getJson("/api/v1/admin/feedback/{$general->id}")->assertForbidden();
        $this->actingAs($this->bulanAdmin)->putJson("/api/v1/admin/feedback/{$general->id}", ['status' => 'resolved'])->assertForbidden();

        // The provincial admin sees both, but Bulan's is its own office's to handle.
        $this->actingAs($this->provincial)->getJson('/api/v1/admin/feedback?status=all')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('open_count', 1);
        $this->actingAs($this->provincial)->getJson("/api/v1/admin/feedback/{$bulan->id}")->assertOk()
            ->assertJsonPath('feedback.can_manage', false)->assertJsonPath('feedback.managed_by', 'Bulan Tourism Office')
            ->assertJsonPath('feedback.listing.name', 'Otavi Beach')->assertJsonPath('feedback.listing.path', "/destinations/{$this->beach->id}");
        $this->actingAs($this->provincial)->putJson("/api/v1/admin/feedback/{$bulan->id}", ['status' => 'resolved'])->assertForbidden();

        // The menu badge counts what each admin can act on.
        $this->actingAs($this->bulanAdmin)->getJson('/api/v1/admin/feedback-count')->assertOk()->assertExactJson(['open_count' => 1]);
        $this->actingAs($this->provincial)->getJson('/api/v1/admin/feedback-count')->assertOk()->assertExactJson(['open_count' => 1]);
    }

    public function test_status_changes_and_notes_are_recorded_and_notes_keep_their_history(): void
    {
        $this->send()->assertCreated();
        $feedback = FeedbackSubmission::sole();
        $url = "/api/v1/admin/feedback/{$feedback->id}";

        $this->actingAs($this->provincial)->putJson($url, ['status' => 'under_review', 'note' => 'Asked the planning team.'])->assertOk()
            ->assertJsonPath('feedback.status', 'under_review')->assertJsonPath('feedback.status_label', 'Under Review')
            ->assertJsonPath('feedback.handler.id', $this->provincial->id);
        $this->actingAs($this->provincial)->postJson("{$url}/notes", ['note' => 'Route map is being drawn.'])->assertCreated();
        $this->actingAs($this->provincial)->postJson("{$url}/notes", ['note' => ''])->assertUnprocessable();
        $this->actingAs($this->provincial)->putJson($url, ['status' => 'closed'])->assertUnprocessable();
        $this->actingAs($this->provincial)->putJson($url, ['status' => 'resolved'])->assertOk();

        $shown = $this->actingAs($this->provincial)->getJson($url)->assertOk();
        $this->assertSame(['Route map is being drawn.', 'Asked the planning team.'], array_column($shown->json('feedback.notes'), 'note'));
        $this->assertSame('resolved', $feedback->fresh()->status);
        $this->assertNotNull($feedback->fresh()->handled_at);
        $this->assertArrayNotHasKey('ip_hash', $shown->json('feedback'));
    }

    public function test_admins_can_filter_by_status_and_category_and_search(): void
    {
        $this->send()->assertCreated();
        $this->send(['category' => 'technical', 'subject' => 'Map does not load', 'message' => 'The map on the Bulan page stays grey on my phone.', 'email' => 'lito@example.com'])->assertCreated();
        FeedbackSubmission::where('category', 'technical')->update(['status' => 'resolved']);

        $list = fn (string $query) => $this->actingAs($this->provincial)->getJson('/api/v1/admin/feedback?'.$query)->assertOk();
        $this->assertSame(['Add a map of jeepney routes'], array_column($list('')->json('data'), 'subject'), 'Open feedback by default.');
        $this->assertCount(2, $list('status=all')->json('data'));
        $this->assertSame(['Map does not load'], array_column($list('status=resolved')->json('data'), 'subject'));
        $this->assertSame(['Map does not load'], array_column($list('status=all&category=technical')->json('data'), 'subject'));
        $this->assertSame(['Map does not load'], array_column($list('status=all&search=lito@')->json('data'), 'subject'));
        $reference = FeedbackSubmission::where('category', 'suggestion')->value('reference');
        $this->assertSame([$reference], array_column($list('status=all&search='.$reference)->json('data'), 'reference'));
        $list('status=all')->assertJsonPath('counts.pending', 1)->assertJsonPath('counts.resolved', 1);
    }

    public function test_guests_and_non_admins_cannot_reach_the_admin_feedback(): void
    {
        $this->send()->assertCreated();
        $id = FeedbackSubmission::sole()->id;

        $this->getJson('/api/v1/admin/feedback')->assertUnauthorized();
        $this->getJson("/api/v1/admin/feedback/{$id}")->assertUnauthorized();
        $this->getJson('/api/v1/admin/feedback-count')->assertUnauthorized();
        $this->actingAs($this->tourist)->getJson('/api/v1/admin/feedback')->assertForbidden();
        $this->actingAs($this->tourist)->putJson("/api/v1/admin/feedback/{$id}", ['status' => 'resolved'])->assertForbidden();
        // There is no public way to read feedback back.
        $this->getJson('/api/v1/feedback')->assertStatus(405);
    }
}

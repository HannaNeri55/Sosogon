<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\BusinessSubmission;
use App\Models\PushSubscription;
use App\Models\Rating;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Notifications\Channels\WebPushChannel;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

/** The notification bell: who is told about what, and the endpoints behind the bell. */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $otherAdmin;

    private User $owner;

    private User $tourist;

    private Accommodation $stay;

    private int $town;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');

        $adminRole = Role::create(['name' => 'admin', 'description' => 'Admin'])->id;
        $ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Owner'])->id;
        $touristRole = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        $user = fn ($role, $name, $extra = []) => User::create(['role_id' => $role, 'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com', 'password' => bcrypt('password'), 'status' => 'active'] + $extra);
        $this->admin = $user($adminRole, 'Admin One');
        $this->otherAdmin = $user($adminRole, 'Admin Two');
        $this->owner = $user($ownerRole, 'Olivia Owner', ['business_type' => 'accommodation']);
        $this->tourist = $user($touristRole, 'Tomas Tourist');

        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $this->stay = Accommodation::create(['user_id' => $this->owner->id, 'name' => 'Hilltop Inn', 'description' => 'An inn.', 'type' => 'Inn',
            'municipality_id' => $this->town, 'address' => 'Bulan', 'latitude' => 12.67, 'longitude' => 123.87, 'price_range' => 'moderate',
            'status' => 'approved', 'approved_at' => now()]);
    }

    private function messages(User $user): array
    {
        return $user->notifications()->get()->map(fn ($n) => $n->data['title'].' | '.$n->data['message'])->all();
    }

    public function test_a_tourist_registration_notifies_every_admin(): void
    {
        $this->postJson('/api/v1/register', ['name' => 'Nina Newcomer', 'email' => 'nina@example.com', 'phone' => '09171234567', 'password' => 'Password123!',
            'password_confirmation' => 'Password123!', 'role' => 'tourist'])->assertCreated();

        foreach ([$this->admin, $this->otherAdmin] as $admin) {
            $this->assertSame(['New tourist account | Nina Newcomer (nina@example.com) created a tourist account.'], $this->messages($admin));
        }
        $this->assertSame('/admin/users', $this->admin->notifications()->first()->data['link']);
    }

    public function test_a_business_owner_registration_tells_the_admins_who_and_what(): void
    {
        $barangay = DB::table('barangays')->insertGetId(['municipality_id' => $this->town, 'name' => 'Zone 1', 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/api/v1/register', ['name' => 'Ben Builder', 'email' => 'ben@example.com', 'phone' => '09171234567', 'password' => 'Password123!',
            'password_confirmation' => 'Password123!', 'role' => 'business_owner', 'business_type' => 'accommodation',
            'business_name' => 'Ben\'s Bay Inn', 'business_address' => 'Zone 1, Bulan', 'municipality_id' => $this->town, 'barangay_id' => $barangay,
            'business_permit_number' => 'BP-2026-001', 'business_permit_file' => UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf')],
            ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(['New business owner registration | Ben Builder registered Ben\'s Bay Inn (accommodation) and is waiting for approval.'], $this->messages($this->admin));
    }

    public function test_a_new_room_notifies_the_admins_and_the_decision_notifies_the_owner(): void
    {
        $this->actingAs($this->owner)->postJson('/api/v1/business/accommodation/rooms', ['room_number' => 'Sea View Room', 'category' => 'Deluxe'])->assertCreated();
        $this->assertSame(['New room to review | Olivia Owner added the room “Sea View Room” to Hilltop Inn.'], $this->messages($this->admin));
        $this->assertSame([], $this->messages($this->owner));

        $room = AccommodationRoom::where('name', 'Sea View Room')->firstOrFail();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/accommodation-rooms/{$room->id}/approve")->assertOk();

        $this->assertSame(['Room approved | The Tourism Office approved the room “Sea View Room” at Hilltop Inn. It is now shown on your listing.'], $this->messages($this->owner));
        $this->assertSame('/business/accommodation/rooms', $this->owner->notifications()->first()->data['link']);
        // The admin who approved it isn't told about their own action; nobody else is told twice.
        $this->assertCount(1, $this->messages($this->admin));
        $this->assertCount(1, $this->messages($this->otherAdmin));
    }

    public function test_a_review_notifies_the_owner_and_the_admins_and_a_reply_notifies_the_tourist(): void
    {
        $this->actingAs($this->tourist)->postJson('/api/v1/ratings', ['rateable_type' => 'accommodation', 'rateable_id' => $this->stay->id,
            'score' => 4, 'comment' => 'Clean rooms and a friendly staff.'])->assertCreated();

        $this->assertSame(['New review on Hilltop Inn | Tomas Tourist gave 4/5: “Clean rooms and a friendly staff.”'], $this->messages($this->owner));
        $this->assertSame(['New guest review | Tomas Tourist rated Hilltop Inn 4/5: “Clean rooms and a friendly staff.”'], $this->messages($this->admin));

        $rating = Rating::firstOrFail();
        $this->actingAs($this->owner)->postJson("/api/v1/business/reviews/{$rating->id}/reply", ['body' => 'Thank you for staying with us!'])->assertSuccessful();

        $this->assertSame(['Hilltop Inn replied to your review | “Thank you for staying with us!”'], $this->messages($this->tourist));
    }

    public function test_edits_to_a_live_room_notify_the_admins_once_and_bulk_approval_notifies_the_owner(): void
    {
        $room = AccommodationRoom::create(['accommodation_id' => $this->stay->id, 'room_number' => 'Garden Room', 'name' => 'Garden Room', 'price' => 1500]);
        $room->forceFill(['status' => 'approved'])->save();

        $this->actingAs($this->owner)->putJson("/api/v1/business/accommodation/rooms/{$room->id}", ['description' => 'Faces the garden, with a private veranda and hammock.'])->assertOk();
        $this->actingAs($this->owner)->putJson("/api/v1/business/accommodation/rooms/{$room->id}", ['description' => 'Faces the garden, with a private veranda, hammock and desk.'])->assertOk();
        $this->assertSame(['Room changes to review | Olivia Owner edited the room “Garden Room” at Hilltop Inn.'], $this->messages($this->admin));

        $hash = collect($this->actingAs($this->admin)->getJson('/api/v1/admin/item-reviews?tab=changes')->json('data'))->firstWhere('id', $room->id)['row']['pending_changes_hash'];
        $this->postJson('/api/v1/admin/item-reviews/bulk', ['action' => 'approve', 'scope' => 'changes', 'items' => [['kind' => 'rooms', 'id' => $room->id, 'changes_hash' => $hash]]])
            ->assertOk()->assertJson(['succeeded' => 1]);

        $this->assertSame(['Your changes are live | The Tourism Office approved your changes to the room “Garden Room” at Hilltop Inn.'], $this->messages($this->owner));
    }

    public function test_a_warning_on_a_business_tells_its_owner_why(): void
    {
        $submission = BusinessSubmission::create(['user_id' => $this->owner->id, 'business_name' => 'Hilltop Inn', 'type' => 'accommodation',
            'description' => 'An inn.', 'address' => 'Bulan', 'municipality_id' => $this->town, 'latitude' => 12.67, 'longitude' => 123.87,
            'contact_number' => '09170000000', 'business_permit_path' => 'business-permits/test.pdf', 'listing_id' => $this->stay->id, 'status' => 'approved']);

        $this->actingAs($this->admin)->putJson("/api/v1/admin/submissions/{$submission->id}/warn", ['notes' => 'Please answer guest messages.'])->assertOk();

        $this->assertSame(['Warning about your listing | The Tourism Office sent a warning about Hilltop Inn: Please answer guest messages.'], $this->messages($this->owner));
    }

    public function test_seeders_and_console_changes_notify_nobody(): void
    {
        AccommodationRoom::create(['accommodation_id' => $this->stay->id, 'room_number' => 'Quiet Room', 'name' => 'Quiet Room']);
        Rating::create(['user_id' => $this->tourist->id, 'rateable_type' => Accommodation::class, 'rateable_id' => $this->stay->id, 'score' => 5]);

        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_the_bell_lists_counts_and_marks_notifications_read(): void
    {
        $this->owner->notify(new ActivityNotification('test', 'First', 'One.', '/business'));
        $this->owner->notify(new ActivityNotification('test', 'Second', 'Two.', '/business'));

        $response = $this->actingAs($this->owner)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('unread', 2);
        $this->assertSame(['First', 'Second'], collect($response->json('notifications'))->pluck('title')->sort()->values()->all());

        $id = $response->json('notifications.0.id');
        $this->postJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('unread', 1);
        $this->postJson('/api/v1/notifications/read-all')->assertOk()->assertJsonPath('unread', 0);

        // Someone else's notifications can't be marked read.
        $this->tourist->notify(new ActivityNotification('test', 'Theirs', 'Hidden.'));
        $theirs = $this->tourist->notifications()->first()->id;
        $this->postJson("/api/v1/notifications/{$theirs}/read")->assertOk();
        $this->assertNull($this->tourist->notifications()->first()->read_at);
    }

    public function test_a_browser_can_sign_up_for_push_and_moves_to_whoever_signs_in_on_it(): void
    {
        $subscription = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123', 'keys' => ['p256dh' => 'BPublicKey', 'auth' => 'AuthSecret']];

        $this->actingAs($this->owner)->postJson('/api/v1/notifications/push-subscription', $subscription)->assertOk();
        $this->actingAs($this->tourist)->postJson('/api/v1/notifications/push-subscription', $subscription)->assertOk();

        $this->assertSame([$this->tourist->id], PushSubscription::pluck('user_id')->all());

        $this->deleteJson('/api/v1/notifications/push-subscription', ['endpoint' => $subscription['endpoint']])->assertOk();
        $this->assertSame(0, PushSubscription::count());

        $this->postJson('/api/v1/notifications/push-subscription', ['endpoint' => 'http://insecure.example.com/x', 'keys' => $subscription['keys']])->assertUnprocessable();
    }

    public function test_a_notification_is_pushed_to_each_browser_and_dropped_browsers_are_forgotten(): void
    {
        // Encrypting a push needs OpenSSL EC keys; Windows PHP needs OPENSSL_CONF for that.
        if (! @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC])) {
            $this->markTestSkipped('OpenSSL cannot create EC keys here (on Windows, set OPENSSL_CONF).');
        }
        $keys = VAPID::createVapidKeys();
        config(['services.webpush.public_key' => $keys['publicKey'], 'services.webpush.private_key' => $keys['privateKey']]);

        // A real browser key pair, as PushManager.subscribe() would give.
        $browser = openssl_pkey_get_details(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]))['ec'];
        $p256dh = rtrim(strtr(base64_encode("\x04".str_pad($browser['x'], 32, "\0", STR_PAD_LEFT).str_pad($browser['y'], 32, "\0", STR_PAD_LEFT)), '+/', '-_'), '=');
        foreach (['https://push.example.com/live', 'https://push.example.com/gone'] as $endpoint) {
            PushSubscription::create(['user_id' => $this->owner->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashFor($endpoint),
                'public_key' => $p256dh, 'auth_token' => rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=')]);
        }

        $sent = [];
        $mock = new MockHandler([new GuzzleResponse(201), new GuzzleResponse(410)]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($sent));
        $this->app->instance(WebPushChannel::class, new WebPushChannel(new GuzzleClient(['handler' => $stack])));

        $this->owner->notify(new ActivityNotification('test', 'Room approved', 'The Tourism Office approved a room.', '/business'));

        $this->assertCount(2, $sent);
        $this->assertSame('86400', $sent[0]['request']->getHeaderLine('TTL'));
        $this->assertStringStartsWith('vapid t=', $sent[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame(['https://push.example.com/live'], PushSubscription::pluck('endpoint')->all());
    }

    public function test_account_health_only_shows_and_clears_account_notices(): void
    {
        $this->owner->notify(new ActivityNotification('test', 'Room approved', 'A room.'));

        $this->actingAs($this->owner)->getJson('/api/v1/account/status')->assertOk()->assertJsonPath('unread_notifications', 0)->assertJsonCount(0, 'notifications');
        $this->postJson('/api/v1/account/notifications/read')->assertOk();

        $this->assertSame(1, $this->owner->unreadNotifications()->count());
    }
}

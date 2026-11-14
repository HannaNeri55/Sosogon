<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admin notifications reach the admins responsible for the record's municipality and the
 * provincial admins; login and /user tell the admin screens each admin's scope.
 */
class MunicipalAdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    private array $towns = [];

    private array $admins = [];

    private int $touristRole;

    private int $ownerRole;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutMiddleware(ThrottleRequests::class);

        $adminRole = Role::create(['name' => 'admin', 'description' => 'Admin'])->id;
        $this->ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Business Owner'])->id;
        $this->touristRole = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        foreach (['Bulan', 'Matnog', 'Gubat'] as $name) {
            $this->towns[$name] = DB::table('municipalities')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }

        foreach (['system' => [null, null], 'provincial' => ['provincial', null], 'bulan' => ['municipal', 'Bulan'], 'matnog' => ['municipal', 'Matnog']] as $key => [$scope, $town]) {
            $user = User::create(['role_id' => $adminRole, 'name' => ucfirst($key).' Admin', 'email' => "{$key}.admin@example.com", 'password' => 'Secret123!', 'status' => 'active']);
            $user->forceFill(['admin_scope' => $scope, 'municipality_id' => $town ? $this->towns[$town] : null])->save();
            $this->admins[$key] = $user->fresh();
        }
    }

    /** Which admins (by key) got a notification of $kind. */
    private function notified(string $kind): array
    {
        return collect($this->admins)->filter(fn (User $admin) => $admin->notifications()->get()->contains(fn ($n) => $n->data['kind'] === $kind))
            ->keys()->sort()->values()->all();
    }

    private function registerOwner(string $town, string $email): void
    {
        $barangay = DB::table('barangays')->insertGetId(['municipality_id' => $this->towns[$town], 'name' => "{$town} Proper", 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/api/v1/register', [
            'name' => 'Ana Reyes', 'email' => $email, 'phone' => '09171234567', 'password' => 'Hanna123!', 'password_confirmation' => 'Hanna123!',
            'role' => 'business_owner', 'business_type' => 'accommodation', 'business_name' => "{$town} Inn", 'business_address' => 'Zone 1',
            'municipality_id' => $this->towns[$town], 'barangay_id' => $barangay, 'business_permit_number' => 'BP-1',
            'business_permit_file' => UploadedFile::fake()->createWithContent('permit.pdf', "%PDF-1.4 {$email}"),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_registrations_reach_their_municipalitys_admins_and_the_provincial_ones(): void
    {
        $this->registerOwner('Bulan', 'bulan.owner@example.com');
        $this->assertSame(['bulan', 'provincial', 'system'], $this->notified('owner_registered'));

        // No municipal admin in Gubat: only the provincial admins hear about it.
        DB::table('notifications')->delete();
        $this->registerOwner('Gubat', 'gubat.owner@example.com');
        $this->assertSame(['provincial', 'system'], $this->notified('owner_registered'));

        // Tourists belong to no municipality.
        $this->postJson('/api/v1/register', ['name' => 'Ben Cruz', 'email' => 'ben@example.com', 'phone' => '09171234567',
            'password' => 'Hanna123!', 'password_confirmation' => 'Hanna123!', 'role' => 'tourist'])->assertCreated();
        $this->assertSame(['provincial', 'system'], $this->notified('tourist_registered'));
    }

    public function test_new_rooms_and_reviews_reach_the_listings_municipality(): void
    {
        $owner = User::create(['role_id' => $this->ownerRole, 'name' => 'Inn Owner', 'email' => 'inn@example.com', 'password' => 'Secret123!', 'status' => 'active', 'business_type' => 'accommodation']);
        $inn = Accommodation::create(['user_id' => $owner->id, 'name' => 'Matnog Inn', 'description' => 'Rooms.', 'type' => 'Inn', 'price_range' => 'moderate',
            'municipality_id' => $this->towns['Matnog'], 'address' => 'Port Rd.', 'latitude' => 12.58, 'longitude' => 124.08, 'status' => 'approved']);

        $this->actingAs($owner)->postJson('/api/v1/business/accommodation/rooms', ['room_number' => 'Sea View Room', 'category' => 'Deluxe'])->assertCreated();
        $this->assertSame(['matnog', 'provincial', 'system'], $this->notified('item_submitted'));

        $tourist = User::create(['role_id' => $this->touristRole, 'name' => 'Guest', 'email' => 'guest@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $this->actingAs($tourist)->postJson('/api/v1/ratings', ['rateable_type' => 'accommodation', 'rateable_id' => $inn->id, 'score' => 5, 'comment' => 'Lovely.'])->assertCreated();
        $this->assertSame(['matnog', 'provincial', 'system'], $this->notified('review_new'));
    }

    public function test_login_and_the_current_user_carry_the_admin_scope(): void
    {
        $this->admins['system']->forceFill(['admin_scope' => 'provincial', 'admin_title' => 'System Administrator'])->save();

        $bulan = $this->postJson('/api/v1/login', ['email' => 'bulan.admin@example.com', 'password' => 'Secret123!'])->assertOk();
        $bulan->assertJsonPath('user.admin.scope', 'municipal')
            ->assertJsonPath('user.admin.assigned', true)
            ->assertJsonPath('user.admin.municipality.name', 'Bulan')
            ->assertJsonPath('user.admin.designation', 'Bulan Tourism Office');

        $this->app['auth']->forgetGuards();
        $system = $this->postJson('/api/v1/login', ['email' => 'system.admin@example.com', 'password' => 'Secret123!'])->assertOk();
        $system->assertJsonPath('user.admin.scope', 'provincial')
            ->assertJsonPath('user.admin.municipality', null)
            ->assertJsonPath('user.admin.designation', 'System Administrator');

        $this->actingAs($this->admins['provincial'])->getJson('/api/v1/user')->assertOk()
            ->assertJsonPath('admin.designation', 'Provincial Administrator');
        $this->actingAs($this->admins['matnog'])->getJson('/api/v1/user')->assertOk()
            ->assertJsonPath('admin.municipality.id', $this->towns['Matnog']);

        $unassigned = User::create(['role_id' => $this->admins['system']->role_id, 'name' => 'New Admin', 'email' => 'new.admin@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $this->actingAs($unassigned)->getJson('/api/v1/user')->assertOk()
            ->assertJsonPath('admin.scope', 'provincial')->assertJsonPath('admin.assigned', false);

        $tourist = User::create(['role_id' => $this->touristRole, 'name' => 'Guest', 'email' => 'guest@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $this->actingAs($tourist)->getJson('/api/v1/user')->assertOk()->assertJsonPath('admin', null);
    }
}

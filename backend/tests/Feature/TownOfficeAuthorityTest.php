<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\AccountActionLog;
use App\Models\AccountViolation;
use App\Models\BusinessOwnerApplication;
use App\Models\BusinessSubmission;
use App\Models\Destination;
use App\Models\Event;
use App\Models\Rating;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A municipality with its own tourism office (an active municipal admin) is managed by that
 * office alone: provincial admins see its records but don't change them, apart from Featured,
 * Tourism priority and moves to another municipality. A municipality without an active office
 * of its own is the provincial admins', and it goes back to them when its office has none.
 */
class TownOfficeAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private int $bulan;

    private int $matnog;

    private User $bulanAdmin;

    private User $provincial;

    private User $unassigned;

    private int $ownerRole;

    private int $touristRole;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Mail::fake();

        $adminRole = Role::create(['name' => 'admin', 'description' => 'Admin'])->id;
        $this->ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Business Owner'])->id;
        $this->touristRole = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        $this->bulan = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $this->matnog = DB::table('municipalities')->insertGetId(['name' => 'Matnog', 'created_at' => now(), 'updated_at' => now()]);

        $admin = function (string $name, ?string $scope, ?int $town) use ($adminRole) {
            $user = User::create(['role_id' => $adminRole, 'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com', 'password' => 'Secret123!', 'status' => 'active']);
            $user->forceFill(['admin_scope' => $scope, 'municipality_id' => $town])->save();

            return $user->fresh();
        };
        $this->bulanAdmin = $admin('Bulan Admin', User::ADMIN_SCOPE_MUNICIPAL, $this->bulan);
        $this->provincial = $admin('Provincial Admin', User::ADMIN_SCOPE_PROVINCIAL, null);
        $this->unassigned = $admin('System Admin', null, null);
    }

    private function owner(string $name, string $status = 'active'): User
    {
        return User::create(['role_id' => $this->ownerRole, 'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'password' => 'Secret123!', 'status' => $status, 'business_type' => 'accommodation']);
    }

    private function destination(int $town, string $name): Destination
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);

        return Destination::create(['name' => $name, 'description' => "{$name} for testing.", 'category_id' => 1, 'municipality_id' => $town,
            'address' => 'Shore Rd.', 'latitude' => 12.6, 'longitude' => 123.9, 'created_by' => $this->provincial->id, 'status' => 'published']);
    }

    private function application(int $town, string $name): BusinessOwnerApplication
    {
        Storage::disk('local')->put("business-permits/registrations/{$name}.pdf", '%PDF-1.4 permit');

        return BusinessOwnerApplication::forceCreate([
            'user_id' => $this->owner("{$name} Owner", 'pending')->id, 'business_name' => $name, 'business_type' => 'accommodation',
            'business_address' => 'Zone 1', 'municipality_id' => $town, 'business_permit_number' => 'BP-'.$name,
            'business_permit_path' => "business-permits/registrations/{$name}.pdf", 'status' => 'pending',
        ]);
    }

    /** An owner's published accommodation with a 5-photo gallery and its approved submission. */
    private function publishedInn(int $town, string $name): Accommodation
    {
        $owner = $this->owner("{$name} Owner");
        $inn = Accommodation::create(['user_id' => $owner->id, 'name' => $name, 'description' => "{$name}, a family place near the plaza with friendly staff and a garden.",
            'municipality_id' => $town, 'address' => 'Rizal St.', 'latitude' => 12.67, 'longitude' => 123.87, 'status' => 'approved', 'type' => 'Inn', 'price_range' => 'moderate']);
        $inn->forceFill(['publication_review_status' => 'published', 'approved_at' => now()])->save();
        foreach (range(0, 4) as $i) {
            DB::table('accommodation_images')->insert(['accommodation_id' => $inn->id, 'image_path' => "seed/{$inn->id}-{$i}.webp", 'thumbnail_path' => "seed/{$inn->id}-{$i}-t.webp",
                'is_primary' => $i === 0, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
        }
        Storage::disk('local')->put("business-permits/{$name}.pdf", '%PDF-1.4 permit');
        BusinessSubmission::forceCreate([
            'user_id' => $owner->id, 'business_name' => $name, 'type' => 'accommodation', 'description' => "{$name} for testing.", 'address' => 'Zone 1',
            'municipality_id' => $town, 'latitude' => 12.67, 'longitude' => 123.87, 'contact_number' => '09171234567',
            'barangay_id' => DB::table('barangays')->insertGetId(['municipality_id' => $town, 'name' => "Zone {$name}", 'created_at' => now(), 'updated_at' => now()]),
            'business_permit_path' => "business-permits/{$name}.pdf", 'status' => 'approved', 'listing_id' => $inn->id,
        ]);

        return $inn;
    }

    private function enforce(User $target, string $action = 'warning', ?Rating $rating = null)
    {
        return $this->postJson("/api/v1/admin/users/{$target->id}/enforcement", array_filter([
            'action' => $action, 'category' => 'fake_reviews', 'reason' => 'Fake reviews',
            'details' => 'Posted several reviews that did not describe a real visit.', 'suspension_days' => $action === 'suspension' ? 7 : null,
            'rating_id' => $rating?->id, 'confirmed' => true,
        ], fn ($value) => $value !== null));
    }

    public function test_provincial_admins_view_but_do_not_change_a_municipality_with_its_own_office(): void
    {
        $bulanBeach = $this->destination($this->bulan, 'Bulan Beach');
        $matnogBeach = $this->destination($this->matnog, 'Matnog Beach');
        $bulanApp = $this->application($this->bulan, 'BulanInn');
        $matnogApp = $this->application($this->matnog, 'MatnogInn');

        foreach ([$this->provincial, $this->unassigned] as $admin) {
            $this->actingAs($admin);
            $this->assertCount(2, $this->getJson('/api/v1/admin/destinations')->assertOk()->json('data'), 'Every municipality is listed.');
            $this->getJson("/api/v1/admin/destinations/{$bulanBeach->id}")->assertOk();
            $this->putJson("/api/v1/admin/destinations/{$bulanBeach->id}", ['name' => 'Renamed'])->assertForbidden()
                ->assertJsonPath('message', 'Bulan has its own tourism office, which manages its records. The provincial administrator can view them.');
            $this->deleteJson("/api/v1/admin/destinations/{$bulanBeach->id}")->assertForbidden();

            $this->getJson("/api/v1/admin/owner-applications/{$bulanApp->id}")->assertOk();
            $this->get("/api/v1/admin/owner-applications/{$bulanApp->id}/permit")->assertOk();
            $this->putJson("/api/v1/admin/owner-applications/{$bulanApp->id}/approve")->assertForbidden();
        }
        $this->assertSame('Bulan Beach', $bulanBeach->fresh()->name);
        $this->assertSame('pending', $bulanApp->fresh()->status);

        // Matnog has no office of its own: the provincial admins manage it.
        $this->actingAs($this->provincial);
        $this->putJson("/api/v1/admin/destinations/{$matnogBeach->id}", ['name' => 'Matnog Cove'])->assertOk();
        $this->putJson("/api/v1/admin/owner-applications/{$matnogApp->id}/approve")->assertOk();

        // Bulan's office decides Bulan's.
        $this->actingAs($this->bulanAdmin)->putJson("/api/v1/admin/owner-applications/{$bulanApp->id}/approve")->assertOk();
    }

    public function test_province_wide_records_and_rankings_stay_with_the_provincial_admins(): void
    {
        $bulanBeach = $this->destination($this->bulan, 'Bulan Beach');
        $bulanFiesta = Event::create(['title' => 'Bulan Fiesta', 'description' => 'Fiesta.', 'municipality_id' => $this->bulan, 'start_date' => now()->addMonth(), 'status' => 'published', 'created_by' => $this->provincial->id]);
        $provinceFestival = Event::create(['title' => 'Kasanggayahan', 'description' => 'Festival.', 'municipality_id' => null, 'start_date' => now()->addMonth(), 'status' => 'published', 'created_by' => $this->provincial->id]);
        $this->actingAs($this->provincial);

        $this->putJson("/api/v1/admin/events/{$bulanFiesta->id}", ['title' => 'Renamed'])->assertForbidden();
        $this->putJson("/api/v1/admin/events/{$provinceFestival->id}", ['title' => 'Kasanggayahan Festival'])->assertOk();

        $this->patchJson("/api/v1/admin/destinations/{$bulanBeach->id}/ranking", ['is_featured' => true, 'tourism_priority' => 'iconic'])->assertOk();
        $this->patchJson("/api/v1/admin/events/{$bulanFiesta->id}/ranking", ['is_featured' => true])->assertOk();
        $this->assertTrue((bool) $bulanBeach->fresh()->is_featured);
        $this->assertSame('iconic', $bulanBeach->fresh()->tourism_priority);
        $this->assertTrue((bool) $bulanFiesta->fresh()->is_featured);
        $this->assertSame('Bulan Fiesta', $bulanFiesta->fresh()->title, 'The ranking route changes nothing else.');
    }

    public function test_a_municipality_goes_back_to_the_provincial_admins_while_its_office_has_no_active_admin(): void
    {
        $beach = $this->destination($this->bulan, 'Bulan Beach');
        $editAsProvincial = fn (string $name) => $this->actingAs($this->provincial)->putJson("/api/v1/admin/destinations/{$beach->id}", ['name' => $name]);
        $editAsProvincial('Held')->assertForbidden();

        $states = [
            'suspended' => ['status' => 'suspended', 'suspended_until' => now()->addWeek(), 'closed_at' => null],
            'deactivated' => ['status' => 'suspended', 'suspended_until' => null, 'closed_at' => null],
            'closed' => ['status' => 'active', 'suspended_until' => null, 'closed_at' => now()],
            'pending' => ['status' => 'pending', 'suspended_until' => null, 'closed_at' => null],
        ];
        foreach ($states as $state => $fields) {
            $this->bulanAdmin->forceFill($fields)->save();
            $this->assertFalse(AdminScope::hasOwnOffice($this->bulan), $state);
            $this->assertSame([$this->provincial->id, $this->unassigned->id], AdminScope::adminsFor($this->bulan)->pluck('id')->sort()->values()->all(), $state);
            $editAsProvincial("Back to provincial ({$state})")->assertOk();
        }

        // A suspension that has ended no longer restricts: the office is back.
        $this->bulanAdmin->forceFill(['status' => 'suspended', 'suspended_until' => now()->subDay(), 'closed_at' => null])->save();
        $this->assertTrue(AdminScope::hasOwnOffice($this->bulan));
        $editAsProvincial('Held again')->assertForbidden();

        $this->bulanAdmin->forceFill(['status' => 'active', 'suspended_until' => null])->save();
        $this->assertSame([$this->bulanAdmin->id], AdminScope::adminsFor($this->bulan)->pluck('id')->all());
        $editAsProvincial('Held')->assertForbidden();
    }

    public function test_owners_and_tourists_of_a_municipality_with_its_own_office_are_its_offices(): void
    {
        $bulanInn = $this->publishedInn($this->bulan, 'Bulan Inn');
        $matnogInn = $this->publishedInn($this->matnog, 'Matnog Inn');
        $ana = User::create(['role_id' => $this->touristRole, 'name' => 'Ana Cruz', 'email' => 'ana@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $anaInBulan = Rating::create(['user_id' => $ana->id, 'rateable_type' => Accommodation::class, 'rateable_id' => $bulanInn->id, 'score' => 1, 'comment' => 'Fake.']);
        $this->actingAs($this->provincial);

        // Business owners: by their business's municipality.
        $this->getJson("/api/v1/admin/users/{$bulanInn->user_id}/account")->assertOk();
        $this->enforce($bulanInn->user)->assertForbidden();
        $this->enforce($matnogInn->user)->assertCreated();

        // A tourist over a review of a Bulan listing: Bulan's office; otherwise the provincial admins.
        $this->enforce($ana, 'warning', $anaInBulan)->assertForbidden();
        $this->enforce($ana)->assertCreated();
        $this->actingAs($this->bulanAdmin)->enforce($ana, 'suspension', $anaInBulan)->assertCreated();
        $this->actingAs($this->provincial)->postJson("/api/v1/admin/users/{$ana->id}/reactivate", ['reason' => 'Lifting it.'])->assertForbidden();
        $this->actingAs($this->bulanAdmin)->postJson("/api/v1/admin/users/{$ana->id}/reactivate", ['reason' => 'Appeal accepted.'])->assertOk();
    }

    public function test_a_move_to_another_municipality_reaches_and_is_decided_by_the_provincial_admins(): void
    {
        $inn = $this->publishedInn($this->bulan, 'Bulan Inn');
        $owner = $inn->user;

        // An ordinary change is Bulan's office's to review.
        $this->actingAs($owner)->putJson('/api/v1/business/accommodation', ['description' => 'Updated. '.$inn->description])->assertOk();
        $this->actingAs($owner)->postJson('/api/v1/business/accommodation/submit-for-review')->assertOk();
        $this->assertTrue($this->bulanAdmin->notifications()->get()->contains(fn ($n) => $n->data['kind'] === 'revision_submitted'));
        $this->assertCount(0, $this->provincial->notifications()->get());
        $this->actingAs($this->provincial)->putJson("/api/v1/admin/accommodations/{$inn->id}", ['revision_action' => 'approve'])->assertForbidden();
        $this->actingAs($this->bulanAdmin)->putJson("/api/v1/admin/accommodations/{$inn->id}", ['revision_action' => 'approve'])->assertOk();

        // A move to Matnog is the provincial admins' decision, and they are the ones told.
        DB::table('notifications')->delete();
        $this->actingAs($owner)->putJson('/api/v1/business/accommodation/location', ['address' => 'Port Area, Matnog', 'municipality_id' => $this->matnog])->assertOk();
        $this->actingAs($owner)->postJson('/api/v1/business/accommodation/submit-for-review')->assertOk();
        $this->assertTrue($this->provincial->notifications()->get()->contains(fn ($n) => $n->data['kind'] === 'revision_submitted'));
        $this->assertCount(0, $this->bulanAdmin->notifications()->get());
        $this->actingAs($this->provincial)->putJson("/api/v1/admin/accommodations/{$inn->id}", ['revision_action' => 'approve'])->assertOk();
        $this->assertSame($this->matnog, (int) $inn->fresh()->municipality_id);
    }

    private function pendingSubmission(int $town, string $name): BusinessSubmission
    {
        return BusinessSubmission::forceCreate([
            'user_id' => $this->owner("{$name} Owner", 'pending')->id, 'business_name' => $name, 'type' => 'accommodation', 'description' => "{$name} for testing.",
            'address' => 'Zone 1', 'municipality_id' => $town, 'latitude' => 12.67, 'longitude' => 123.87, 'contact_number' => '09171234567',
            'barangay_id' => DB::table('barangays')->insertGetId(['municipality_id' => $town, 'name' => "Zone {$name}", 'created_at' => now(), 'updated_at' => now()]),
            'business_permit_path' => "business-permits/{$name}.pdf", 'status' => 'pending',
        ]);
    }

    public function test_lists_mark_what_the_admin_can_change_and_which_office_manages_the_rest(): void
    {
        $this->destination($this->bulan, 'Bulan Beach');
        $this->destination($this->matnog, 'Matnog Beach');
        $this->application($this->bulan, 'BulanApp');
        $this->application($this->matnog, 'MatnogApp');
        $bulanInn = $this->publishedInn($this->bulan, 'Bulan Inn');
        $matnogInn = $this->publishedInn($this->matnog, 'Matnog Inn');
        AccommodationRoom::forceCreate(['accommodation_id' => $bulanInn->id, 'name' => 'Bulan Deluxe', 'status' => 'pending']);
        AccommodationRoom::forceCreate(['accommodation_id' => $matnogInn->id, 'name' => 'Matnog Deluxe', 'status' => 'pending']);
        $ana = User::create(['role_id' => $this->touristRole, 'name' => 'Ana Cruz', 'email' => 'ana@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $anaInBulan = Rating::create(['user_id' => $ana->id, 'rateable_type' => Accommodation::class, 'rateable_id' => $bulanInn->id, 'score' => 2, 'comment' => 'Noisy.']);
        $anaInMatnog = Rating::create(['user_id' => $ana->id, 'rateable_type' => Accommodation::class, 'rateable_id' => $matnogInn->id, 'score' => 5, 'comment' => 'Lovely.']);
        $this->actingAs($this->bulanAdmin)->enforce($bulanInn->user)->assertCreated();

        // A row => [can_manage, managed_by], keyed by a name (in name order).
        $marks = fn (array $rows, string $key) => collect($rows)->mapWithKeys(fn ($row) => [data_get($row, $key) => [$row['can_manage'], $row['managed_by']]])->sortKeys()->all();
        $held = [false, 'Bulan Tourism Office'];
        $free = [true, null];
        $this->actingAs($this->provincial);

        $this->assertSame(['Bulan Beach' => $held, 'Matnog Beach' => $free], $marks($this->getJson('/api/v1/admin/destinations')->json('data'), 'name'));
        $this->assertSame(['BulanApp' => $held, 'MatnogApp' => $free], $marks($this->getJson('/api/v1/admin/owner-applications')->json(), 'business_name'));
        $this->assertSame(['Bulan Inn' => $held, 'Matnog Inn' => $free], $marks($this->getJson('/api/v1/admin/accommodations')->json('data'), 'name'));
        $this->assertSame(['Bulan Deluxe' => $held, 'Matnog Deluxe' => $free], $marks($this->getJson('/api/v1/admin/item-reviews')->json('data'), 'name'));
        $this->assertSame(['Lovely.' => $free, 'Noisy.' => $held], $marks($this->getJson('/api/v1/admin/guest-reviews')->json('data'), 'comment'));
        $this->assertSame(['Ana Cruz' => $free, 'Bulan Inn Owner' => $held, 'Matnog Inn Owner' => $free],
            collect($marks($this->getJson('/api/v1/admin/users?per_page=100')->json('data'), 'name'))->only(['Bulan Inn Owner', 'Matnog Inn Owner', 'Ana Cruz'])->all());
        $this->assertSame(['Bulan Inn Owner' => $held], $marks($this->getJson('/api/v1/admin/account-violations')->json('data'), 'user.name'));
        $this->getJson("/api/v1/admin/users/{$bulanInn->user_id}/account")->assertOk()
            ->assertJsonPath('user.can_manage', false)->assertJsonPath('user.managed_by', 'Bulan Tourism Office');
        // A provincial admin acts on the tourist over the Matnog review only.
        $this->assertSame([$anaInMatnog->id], $this->getJson("/api/v1/admin/users/{$ana->id}/account")->json('reviews.*.id'));

        // Bulan's office manages its own; it acts on the tourist over the Bulan review.
        $this->actingAs($this->bulanAdmin);
        $this->assertSame(['Bulan Beach' => $free], $marks($this->getJson('/api/v1/admin/destinations')->json('data'), 'name'));
        $this->assertSame(['Bulan Deluxe' => $free], $marks($this->getJson('/api/v1/admin/item-reviews')->json('data'), 'name'));
        $this->assertSame([$anaInBulan->id], $this->getJson("/api/v1/admin/users/{$ana->id}/account")->json('reviews.*.id'));
    }

    public function test_the_dashboard_counts_the_pending_work_waiting_on_the_admin(): void
    {
        $this->pendingSubmission($this->bulan, 'Bulan Bay Inn');
        $matnog = $this->pendingSubmission($this->matnog, 'Matnog Port Inn');

        $provincial = $this->actingAs($this->provincial)->getJson('/api/v1/admin/dashboard')->assertOk();
        $provincial->assertJsonPath('stats.pending_submissions', 1)
            ->assertJsonPath('own_offices', [['id' => $this->bulan, 'name' => 'Bulan', 'office' => 'Bulan Tourism Office']]);
        $this->assertSame([$matnog->id], $provincial->json('pending_submissions.*.id'));
        // The admin profile tells the screens the same (e.g. which municipalities to offer in forms).
        $this->getJson('/api/v1/user')->assertOk()
            ->assertJsonPath('admin.own_offices', [['id' => $this->bulan, 'name' => 'Bulan', 'office' => 'Bulan Tourism Office']]);
        $this->actingAs($this->bulanAdmin)->getJson('/api/v1/user')->assertOk()->assertJsonPath('admin.own_offices', []);

        $this->actingAs($this->bulanAdmin)->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('stats.pending_submissions', 1)->assertJsonPath('own_offices', []);

        // Without an active office, Bulan's submissions wait on the provincial admins again.
        $this->bulanAdmin->forceFill(['status' => 'suspended', 'suspended_until' => null])->save();
        $this->actingAs($this->provincial)->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('stats.pending_submissions', 2)->assertJsonPath('own_offices', []);
    }

    public function test_provincial_admins_deactivate_and_reactivate_a_municipal_admins_access(): void
    {
        $beach = $this->destination($this->bulan, 'Bulan Beach');
        $this->bulanAdmin->createToken('session');
        $access = fn (User $target, string $action) => $this->postJson("/api/v1/admin/users/{$target->id}/admin-access", ['action' => $action, 'reason' => 'Left the Bulan Tourism Office.']);

        // Only provincial admins, and only on municipal admin accounts.
        $this->actingAs($this->bulanAdmin);
        $access($this->bulanAdmin, 'deactivate')->assertForbidden();
        $this->actingAs($this->provincial);
        $access($this->unassigned, 'deactivate')->assertForbidden()
            ->assertJsonPath('message', 'Only municipal administrator accounts can be deactivated here. Provincial administrator accounts are protected.');
        $this->enforce($this->bulanAdmin, 'deactivation')->assertForbidden();
        $access($this->bulanAdmin, 'reactivate')->assertStatus(409);

        // Deactivated: signed out, Bulan goes back to the provincial admins. Not a violation.
        $access($this->bulanAdmin, 'deactivate')->assertOk()
            ->assertJsonPath('message', 'Access deactivated. Bulan is managed by the provincial administrator until it has an active municipal administrator.')
            ->assertJsonPath('account_state', User::STATE_DEACTIVATED);
        $this->assertSame(0, $this->bulanAdmin->tokens()->count());
        $this->assertSame(0, AccountViolation::where('user_id', $this->bulanAdmin->id)->count());
        $this->assertSame('Left the Bulan Tourism Office.', AccountActionLog::where('user_id', $this->bulanAdmin->id)->where('action', 'admin_deactivated')->value('reason'));
        $this->assertFalse(AdminScope::hasOwnOffice($this->bulan));
        $this->putJson("/api/v1/admin/destinations/{$beach->id}", ['name' => 'Bulan Beach Park'])->assertOk();
        $access($this->bulanAdmin, 'deactivate')->assertStatus(409);
        $this->actingAs($this->bulanAdmin->fresh())->getJson('/api/v1/admin/dashboard')->assertForbidden();

        // Reactivated: Bulan's office manages Bulan again.
        $this->actingAs($this->provincial);
        $access($this->bulanAdmin, 'reactivate')->assertOk()
            ->assertJsonPath('message', 'Access restored. Bulan is managed by its own tourism office again.')
            ->assertJsonPath('account_state', User::STATE_ACTIVE);
        $this->assertSame(1, AccountActionLog::where('user_id', $this->bulanAdmin->id)->where('action', 'admin_reactivated')->count());
        $this->putJson("/api/v1/admin/destinations/{$beach->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($this->bulanAdmin->fresh())->getJson('/api/v1/admin/dashboard')->assertOk();

        // The account panel tells the screens whose admin this is.
        $this->actingAs($this->provincial)->getJson("/api/v1/admin/users/{$this->bulanAdmin->id}/account")->assertOk()
            ->assertJsonPath('user.admin.scope', 'municipal')->assertJsonPath('user.admin.municipality.name', 'Bulan');
    }
}

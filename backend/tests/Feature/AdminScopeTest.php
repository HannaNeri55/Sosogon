<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\BusinessOwnerApplication;
use App\Models\Destination;
use App\Models\Rating;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The municipality rule every admin endpoint uses (App\Support\AdminScope): provincial admins
 * (and admins whose scope isn't assigned yet) manage everything, a municipal admin only their
 * municipality.
 */
class AdminScopeTest extends TestCase
{
    use RefreshDatabase;

    private int $bulan;

    private int $matnog;

    private int $adminRole;

    private int $ownerRole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminRole = Role::create(['name' => 'admin', 'description' => 'Admin'])->id;
        $this->ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Business Owner'])->id;
        Role::create(['name' => 'tourist', 'description' => 'Tourist']);
        $this->bulan = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $this->matnog = DB::table('municipalities')->insertGetId(['name' => 'Matnog', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('categories')->insert(['id' => 1, 'name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function admin(?string $scope, ?int $municipality = null): User
    {
        $user = User::create(['role_id' => $this->adminRole, 'name' => 'Admin '.uniqid(), 'email' => uniqid().'@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $user->forceFill(['admin_scope' => $scope, 'municipality_id' => $municipality])->save();

        return $user->fresh();
    }

    private function listingFields(int $town, string $name): array
    {
        return ['name' => $name, 'description' => 'Test.', 'municipality_id' => $town, 'address' => 'Test St.', 'latitude' => 12.6, 'longitude' => 123.9];
    }

    public function test_who_manages_which_municipality(): void
    {
        $provincial = $this->admin(User::ADMIN_SCOPE_PROVINCIAL);
        $unassigned = $this->admin(null);
        $bulanAdmin = $this->admin(User::ADMIN_SCOPE_MUNICIPAL, $this->bulan);
        $noTown = $this->admin(User::ADMIN_SCOPE_MUNICIPAL);

        foreach ([$provincial, $unassigned] as $admin) {
            $this->assertTrue($admin->isProvincialAdmin());
            $this->assertTrue($admin->managesMunicipality($this->bulan));
            $this->assertTrue($admin->managesMunicipality($this->matnog));
            $this->assertTrue($admin->managesMunicipality(null), 'Province-wide records.');
            $this->assertNull(AdminScope::municipalityIds($admin));
        }

        $this->assertTrue($bulanAdmin->isMunicipalAdmin());
        $this->assertFalse($bulanAdmin->isProvincialAdmin());
        $this->assertTrue($bulanAdmin->managesMunicipality($this->bulan));
        $this->assertFalse($bulanAdmin->managesMunicipality($this->matnog));
        $this->assertFalse($bulanAdmin->managesMunicipality(null));
        $this->assertSame([$this->bulan], AdminScope::municipalityIds($bulanAdmin));

        $this->assertSame([], AdminScope::municipalityIds($noTown), 'A municipal admin without a municipality manages nothing.');
        $this->assertFalse($noTown->managesMunicipality($this->bulan));

        $owner = User::create(['role_id' => $this->ownerRole, 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'Secret123!']);
        $this->assertFalse($owner->managesMunicipality($this->bulan), 'Only admins manage municipalities.');
    }

    public function test_queries_are_limited_to_the_admins_municipality(): void
    {
        $creator = $this->admin(null);
        $inBulan = Destination::create($this->listingFields($this->bulan, 'Bulan Beach') + ['category_id' => 1, 'created_by' => $creator->id, 'status' => 'published']);
        Destination::create($this->listingFields($this->matnog, 'Matnog Beach') + ['category_id' => 1, 'created_by' => $creator->id, 'status' => 'published']);
        $bulanStay = Accommodation::create($this->listingFields($this->bulan, 'Bulan Inn') + ['type' => 'Inn', 'price_range' => 'moderate', 'status' => 'approved']);
        $matnogStay = Accommodation::create($this->listingFields($this->matnog, 'Matnog Inn') + ['type' => 'Inn', 'price_range' => 'moderate', 'status' => 'approved']);
        $bulanRoom = AccommodationRoom::create(['accommodation_id' => $bulanStay->id, 'name' => 'Room A']);
        AccommodationRoom::create(['accommodation_id' => $matnogStay->id, 'name' => 'Room B']);
        $tourist = User::create(['role_id' => Role::where('name', 'tourist')->value('id'), 'name' => 'Guest', 'email' => 'guest@example.com', 'password' => 'Secret123!']);
        $bulanReview = Rating::create(['user_id' => $tourist->id, 'rateable_type' => Accommodation::class, 'rateable_id' => $bulanStay->id, 'score' => 5]);
        Rating::create(['user_id' => $tourist->id, 'rateable_type' => Accommodation::class, 'rateable_id' => $matnogStay->id, 'score' => 4]);

        $bulanAdmin = $this->admin(User::ADMIN_SCOPE_MUNICIPAL, $this->bulan);
        $this->assertSame([$inBulan->id], AdminScope::apply(Destination::query(), $bulanAdmin)->pluck('id')->all());
        $this->assertSame([$bulanRoom->id], AdminScope::applyThrough(AccommodationRoom::query(), $bulanAdmin, 'accommodation')->pluck('id')->all());
        $this->assertSame([$bulanReview->id], AdminScope::applyToRatings(Rating::query(), $bulanAdmin)->pluck('id')->all());

        $provincial = $this->admin(User::ADMIN_SCOPE_PROVINCIAL);
        $this->assertSame(2, AdminScope::apply(Destination::query(), $provincial)->count());
        $this->assertSame(2, AdminScope::applyThrough(AccommodationRoom::query(), $provincial, 'accommodation')->count());
        $this->assertSame(2, AdminScope::applyToRatings(Rating::query(), $provincial)->count());

        $this->assertSame(0, AdminScope::apply(Destination::query(), $this->admin(User::ADMIN_SCOPE_MUNICIPAL))->count());
    }

    public function test_authorize_answers_403_outside_the_admins_municipality(): void
    {
        $bulanAdmin = $this->admin(User::ADMIN_SCOPE_MUNICIPAL, $this->bulan);
        AdminScope::authorize($bulanAdmin, $this->bulan);

        foreach ([$this->matnog, null] as $town) {
            try {
                AdminScope::authorize($bulanAdmin, $town);
                $this->fail('Expected 403 for municipality '.var_export($town, true));
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        AdminScope::authorize($this->admin(User::ADMIN_SCOPE_PROVINCIAL), $this->matnog);
        AdminScope::authorize($this->admin(null), null);
        $this->addToAssertionCount(3);
    }

    public function test_province_only_routes_refuse_municipal_admins(): void
    {
        Route::middleware(['auth:sanctum', 'role:admin', 'admin.provincial'])->get('/api/v1/test-provincial-only', fn () => response()->json(['ok' => true]));

        $this->actingAs($this->admin(User::ADMIN_SCOPE_MUNICIPAL, $this->bulan))->getJson('/api/v1/test-provincial-only')->assertForbidden()
            ->assertJsonPath('message', 'Only the provincial administrator can do this.');
        $this->actingAs($this->admin(User::ADMIN_SCOPE_PROVINCIAL))->getJson('/api/v1/test-provincial-only')->assertOk();
        $this->actingAs($this->admin(null))->getJson('/api/v1/test-provincial-only')->assertOk();
    }

    public function test_a_business_owners_municipality_comes_from_their_listing_or_application(): void
    {
        $withListing = User::create(['role_id' => $this->ownerRole, 'name' => 'Inn Owner', 'email' => 'inn@example.com', 'password' => 'Secret123!']);
        Accommodation::create($this->listingFields($this->matnog, 'Matnog Inn') + ['type' => 'Inn', 'price_range' => 'moderate', 'status' => 'approved', 'user_id' => $withListing->id]);
        $this->assertSame($this->matnog, $withListing->businessMunicipalityId());

        $applicant = User::create(['role_id' => $this->ownerRole, 'name' => 'Applicant', 'email' => 'applicant@example.com', 'password' => 'Secret123!']);
        BusinessOwnerApplication::forceCreate([
            'user_id' => $applicant->id, 'business_name' => 'New Shop', 'business_type' => 'retail_product', 'business_address' => 'Zone 1',
            'municipality_id' => $this->bulan, 'business_permit_number' => 'BP-1', 'business_permit_path' => 'permits/x.pdf', 'status' => 'pending',
        ]);
        $this->assertSame($this->bulan, $applicant->businessMunicipalityId());

        $unknown = User::create(['role_id' => $this->ownerRole, 'name' => 'Old Owner', 'email' => 'old@example.com', 'password' => 'Secret123!']);
        $this->assertNull($unknown->businessMunicipalityId());
    }

    public function test_responsible_admins_are_the_municipalitys_and_the_provincial_ones(): void
    {
        $provincial = $this->admin(User::ADMIN_SCOPE_PROVINCIAL);
        $unassigned = $this->admin(null);
        $bulanAdmin = $this->admin(User::ADMIN_SCOPE_MUNICIPAL, $this->bulan);
        $matnogAdmin = $this->admin(User::ADMIN_SCOPE_MUNICIPAL, $this->matnog);
        User::create(['role_id' => $this->ownerRole, 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'Secret123!']);

        $ids = fn (?int $town) => AdminScope::adminsFor($town)->pluck('id')->sort()->values()->all();
        $this->assertSame([$provincial->id, $unassigned->id, $bulanAdmin->id], $ids($this->bulan));
        $this->assertSame([$provincial->id, $unassigned->id, $matnogAdmin->id], $ids($this->matnog));
        $this->assertSame([$provincial->id, $unassigned->id], $ids(null), 'Province-wide: provincial admins only.');

        $gubat = DB::table('municipalities')->insertGetId(['name' => 'Gubat', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([$provincial->id, $unassigned->id], $ids($gubat), 'No municipal admin there yet: the provincial ones.');
    }

    public function test_an_invalid_scope_grants_nothing_while_null_provincial_and_municipal_keep_their_access(): void
    {
        $unassigned = $this->admin(null);
        $provincial = $this->admin(User::ADMIN_SCOPE_PROVINCIAL);
        $bulanAdmin = $this->admin(User::ADMIN_SCOPE_MUNICIPAL, $this->bulan);

        // null: the approved temporary full access, flagged as not assigned.
        $this->assertTrue($unassigned->isProvincialAdmin());
        $this->assertFalse($unassigned->hasInvalidAdminScope());
        $this->assertSame(['scope' => 'provincial', 'assigned' => false], array_intersect_key($unassigned->adminProfile(), ['scope' => 1, 'assigned' => 1]));
        // provincial and municipal: unchanged.
        $this->assertTrue($provincial->managesMunicipality($this->matnog));
        $this->assertSame([$this->bulan], AdminScope::municipalityIds($bulanAdmin));
        $this->assertFalse($bulanAdmin->managesMunicipality($this->matnog));

        // Anything else, even with a municipality set, manages nothing and is never provincial.
        foreach (['Provincial', 'MUNICIPAL', 'admin', 'all', ' provincial', ''] as $value) {
            $invalid = $this->admin($value, $this->bulan);
            $this->assertTrue($invalid->hasInvalidAdminScope(), var_export($value, true));
            $this->assertFalse($invalid->isProvincialAdmin(), var_export($value, true));
            $this->assertFalse($invalid->isMunicipalAdmin(), var_export($value, true));
            $this->assertFalse($invalid->managesMunicipality($this->bulan), 'Not even its own municipality_id.');
            $this->assertFalse($invalid->managesMunicipality(null));
            $this->assertSame([], AdminScope::municipalityIds($invalid));
            $this->assertSame('invalid', $invalid->adminProfile()['scope']);
            $this->assertSame(0, AdminScope::apply(Destination::query(), $invalid)->count());
            try {
                AdminScope::requireProvincial($invalid);
                $this->fail('An invalid scope passed requireProvincial.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
            $this->assertNotContains($invalid->id, AdminScope::adminsFor($this->bulan)->pluck('id')->all(), 'No notifications either.');
            $this->assertNotContains($invalid->id, AdminScope::adminsFor(null)->pluck('id')->all());
        }
    }
}

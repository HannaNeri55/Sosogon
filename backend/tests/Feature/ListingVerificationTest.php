<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\ListingVerification;
use App\Models\MenuItem;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Last verified": each part of a published listing (price, location, hours, contact) is verified
 * only by an explicit admin action; edits never verify anything; a published change since the
 * check shows as changed, while owners' edits waiting for approval change nothing; checks become
 * overdue after the configured days; history is kept; and only the responsible admins can verify.
 */
class ListingVerificationTest extends TestCase
{
    use RefreshDatabase;

    private int $adminRole;

    private int $bulan;

    private int $irosin;

    private User $provincial;

    private User $irosinAdmin;

    private User $owner;

    private Destination $beach;

    private Accommodation $inn;

    private FoodBeverage $grill;

    private RetailShop $shop;

    private MenuItem $dish;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminRole = Role::create(['name' => 'admin', 'description' => 'Admin'])->id;
        $ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Owner'])->id;
        Role::create(['name' => 'tourist', 'description' => 'Tourist']);
        $this->bulan = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $this->irosin = DB::table('municipalities')->insertGetId(['name' => 'Irosin', 'created_at' => now(), 'updated_at' => now()]);
        $this->provincial = $this->admin(User::ADMIN_SCOPE_PROVINCIAL);
        // Irosin has its own tourism office; Bulan's listings are the provincial admins'.
        $this->irosinAdmin = $this->admin(User::ADMIN_SCOPE_MUNICIPAL, $this->irosin);
        AdminScope::forgetOwnOffices();
        $this->owner = User::create(['role_id' => $ownerRole, 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'Secret123!', 'status' => 'active', 'business_type' => 'food_beverage']);

        $place = fn (int $town) => ['description' => 'A place for tests.', 'municipality_id' => $town, 'address' => 'Test St.', 'latitude' => 12.67, 'longitude' => 123.88, 'operating_hours' => '8:00 AM - 5:00 PM', 'contact_number' => '0917 000 0000'];
        $category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $this->beach = Destination::create($place($this->bulan) + ['name' => 'Otavi Beach', 'category_id' => $category, 'created_by' => $this->provincial->id, 'status' => 'published', 'entrance_fee' => 20]);
        DB::table('destination_fees')->insert(['destination_id' => $this->beach->id, 'label' => 'Adult', 'amount' => 20, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->inn = Accommodation::create($place($this->bulan) + ['name' => 'Bay Inn', 'user_id' => $this->owner->id, 'type' => 'Inn', 'price_range' => 'budget', 'status' => 'approved', 'check_in_time' => '2:00 PM']);
        AccommodationRoom::create(['accommodation_id' => $this->inn->id, 'name' => 'Fan Room', 'price' => 900, 'status' => 'approved']);
        $this->grill = FoodBeverage::create($place($this->irosin) + ['name' => 'Irosin Grill', 'user_id' => $this->owner->id, 'price_range' => 'budget', 'status' => 'approved']);
        $this->dish = MenuItem::create(['food_beverage_id' => $this->grill->id, 'name' => 'Pinangat', 'description' => 'A local dish.', 'price' => 120]);
        $this->dish->forceFill(['status' => 'approved'])->save();
        $this->shop = RetailShop::create($place($this->bulan) + ['name' => 'Pili Shop', 'user_id' => $this->owner->id, 'status' => 'approved']);
        $product = RetailProduct::create(['retail_shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'name' => 'Pili tart', 'description' => 'Sweet.', 'product_type' => 'Food', 'municipality_id' => $this->bulan, 'price' => 80]);
        $product->forceFill(['status' => 'approved'])->save();
    }

    private function admin(string $scope, ?int $municipality = null): User
    {
        $user = User::create(['role_id' => $this->adminRole, 'name' => 'Admin '.uniqid(), 'email' => uniqid().'@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $user->forceFill(['admin_scope' => $scope, 'municipality_id' => $municipality])->save();

        return $user->fresh();
    }

    private function verify(string $type, int $id, array $aspects, ?User $as = null, array $extra = [])
    {
        return $this->actingAs($as ?? $this->provincial)->postJson("/api/v1/admin/verifications/{$type}/{$id}", ['aspects' => $aspects] + $extra);
    }

    /** The public detail page's verification, as aspect => [status, verified_at]. */
    private function publicStatus(string $path, string $key = 'verification'): array
    {
        $data = $this->getJson("/api/v1/{$path}")->assertOk()->json($key);

        return collect($data['aspects'])->mapWithKeys(fn ($row) => [$row['aspect'] => [$row['state'], $row['verified_at']]])->all();
    }

    private function statuses(string $path): array
    {
        return array_map(fn ($row) => $row[0], $this->publicStatus($path));
    }

    public function test_nothing_is_verified_until_an_admin_verifies_it_and_edits_never_verify(): void
    {
        $this->beach->update(['operating_hours' => '7:00 AM - 6:00 PM', 'contact_number' => '0918 111 1111']);

        foreach (["destinations/{$this->beach->id}", "accommodations/{$this->inn->id}", "food-beverages/{$this->grill->id}", "retail-shops/{$this->shop->id}"] as $path) {
            $this->assertSame(['price' => ['not_verified', null], 'location' => ['not_verified', null], 'hours' => ['not_verified', null], 'contact' => ['not_verified', null]], $this->publicStatus($path), $path);
        }
        $this->assertSame(0, ListingVerification::count());
    }

    public function test_one_part_or_all_parts_can_be_verified_and_the_public_never_sees_who(): void
    {
        $this->verify('destination', $this->beach->id, ['price'])->assertCreated()->assertJsonPath('message', 'Price marked as verified.');
        $public = $this->getJson("/api/v1/destinations/{$this->beach->id}")->assertOk()->json('verification');
        $this->assertSame('Tourism Office', $public['verified_by']);
        $this->assertSame(['verified', 'not_verified', 'not_verified', 'not_verified'], array_column($public['aspects'], 'state'));
        $this->assertNotNull($public['aspects'][0]['verified_at']);
        $this->assertStringNotContainsString($this->provincial->name, json_encode($public));

        // Verify All.
        $this->verify('accommodation', $this->inn->id, ['price', 'location', 'hours', 'contact'])->assertCreated()
            ->assertJsonPath('message', 'Price, Location, Operating Hours and Contact Information marked as verified.')
            ->assertJsonPath('listing.aspects.0.verified_by', $this->provincial->name);
        $this->assertSame(['verified', 'verified', 'verified', 'verified'], array_values($this->statuses("accommodations/{$this->inn->id}")));
    }

    public function test_a_published_change_shows_as_changed_for_that_part_only(): void
    {
        $this->verify('destination', $this->beach->id, ['price', 'location', 'hours', 'contact'])->assertCreated();

        $this->beach->update(['entrance_fee' => 30]);
        $this->assertSame(['price' => 'changed', 'location' => 'verified', 'hours' => 'verified', 'contact' => 'verified'], $this->statuses("destinations/{$this->beach->id}"));

        // A fee row is part of the price too; the coordinates are the location.
        $this->verify('destination', $this->beach->id, ['price'])->assertCreated();
        DB::table('destination_fees')->where('destination_id', $this->beach->id)->update(['amount' => 25]);
        $this->beach->update(['latitude' => 12.68]);
        $this->assertSame(['price' => 'changed', 'location' => 'changed', 'hours' => 'verified', 'contact' => 'verified'], $this->statuses("destinations/{$this->beach->id}"));

        // Saving the same values again is not a change.
        $this->verify('destination', $this->beach->id, ['price', 'location'])->assertCreated();
        $this->beach->update(['latitude' => '12.680000', 'operating_hours' => '8:00 AM  -  5:00 PM']);
        $this->assertSame('verified', $this->statuses("destinations/{$this->beach->id}")['location']);
        $this->assertSame('verified', $this->statuses("destinations/{$this->beach->id}")['hours']);
    }

    public function test_owner_edits_waiting_for_approval_do_not_affect_the_verified_prices(): void
    {
        $this->verify('food_beverage', $this->grill->id, ['price'], $this->irosinAdmin)->assertCreated();

        // A proposed new price and a new dish, both still waiting for the admin.
        $this->dish->forceFill(['pending_changes' => ['values' => ['price' => 150]]])->save();
        $pending = MenuItem::create(['food_beverage_id' => $this->grill->id, 'name' => 'Laing', 'description' => 'Taro leaves.', 'price' => 90]);
        $this->assertSame('verified', $this->statuses("food-beverages/{$this->grill->id}")['price']);

        // Once approved, it is published, and the price was not checked yet.
        $pending->forceFill(['status' => 'approved'])->save();
        $this->assertSame('changed', $this->statuses("food-beverages/{$this->grill->id}")['price']);

        // The same for an accommodation's room.
        $this->verify('accommodation', $this->inn->id, ['price'])->assertCreated();
        AccommodationRoom::create(['accommodation_id' => $this->inn->id, 'name' => 'Aircon Room', 'price' => 1500, 'status' => 'pending']);
        $this->assertSame('verified', $this->statuses("accommodations/{$this->inn->id}")['price']);
        AccommodationRoom::where('name', 'Fan Room')->update(['price' => 1000]);
        $this->assertSame('changed', $this->statuses("accommodations/{$this->inn->id}")['price']);
    }

    public function test_checks_become_overdue_after_each_parts_own_period_which_admins_can_change(): void
    {
        $this->verify('retail_shop', $this->shop->id, ['price', 'location', 'hours', 'contact'])->assertCreated();

        $this->travel(31)->days();
        $this->assertSame(['price' => 'overdue', 'location' => 'verified', 'hours' => 'verified', 'contact' => 'verified'], $this->statuses("retail-shops/{$this->shop->id}"));
        $this->travel(60)->days();
        $this->assertSame(['price' => 'overdue', 'location' => 'verified', 'hours' => 'overdue', 'contact' => 'overdue'], $this->statuses("retail-shops/{$this->shop->id}"));

        $this->actingAs($this->provincial)->getJson('/api/v1/admin/verification-settings')->assertOk()
            ->assertJsonPath('overdue_days', ['price' => 30, 'hours' => 90, 'contact' => 90, 'location' => 180]);
        $days = ['price' => 120, 'hours' => 120, 'contact' => 120, 'location' => 365];
        $this->actingAs($this->irosinAdmin)->putJson('/api/v1/admin/verification-settings', ['overdue_days' => $days])->assertForbidden();
        $this->actingAs($this->provincial)->putJson('/api/v1/admin/verification-settings', ['overdue_days' => ['price' => 0] + $days])->assertUnprocessable();
        $this->actingAs($this->provincial)->putJson('/api/v1/admin/verification-settings', ['overdue_days' => $days])->assertOk();
        $this->assertSame(['price' => 'verified', 'location' => 'verified', 'hours' => 'verified', 'contact' => 'verified'], $this->statuses("retail-shops/{$this->shop->id}"));
    }

    public function test_every_check_is_kept_in_the_history(): void
    {
        $this->verify('destination', $this->beach->id, ['price'], extra: ['note' => 'Called the barangay hall.'])->assertCreated();
        $first = ListingVerification::sole()->only(['fingerprint', 'verified_at', 'note']);
        $this->travel(5)->days();
        $this->beach->update(['entrance_fee' => 30]);
        $this->verify('destination', $this->beach->id, ['price'])->assertCreated();

        $this->assertSame(2, ListingVerification::count());
        $this->assertEquals($first, ListingVerification::orderBy('id')->first()->only(['fingerprint', 'verified_at', 'note']));
        $history = $this->actingAs($this->provincial)->getJson("/api/v1/admin/verifications/destination/{$this->beach->id}")->assertOk()->json('history');
        $this->assertSame([null, 'Called the barangay hall.'], array_column($history, 'note'));
        $this->assertSame([$this->provincial->name, $this->provincial->name], array_column($history, 'verified_by'));
    }

    public function test_information_that_changed_while_being_reviewed_is_not_verified(): void
    {
        $seen = $this->actingAs($this->provincial)->getJson("/api/v1/admin/verifications/destination/{$this->beach->id}")->assertOk()->json('listing.aspects.0.fingerprint');
        $this->beach->update(['entrance_fee' => 50]);

        $this->verify('destination', $this->beach->id, ['price'], extra: ['fingerprints' => ['price' => $seen]])->assertUnprocessable()->assertJsonValidationErrors('fingerprints');
        $this->assertSame(0, ListingVerification::count());
    }

    public function test_only_the_responsible_admins_verify_and_only_published_listings(): void
    {
        $this->postJson("/api/v1/admin/verifications/destination/{$this->beach->id}", ['aspects' => ['price']])->assertUnauthorized();
        $this->getJson('/api/v1/admin/verifications')->assertUnauthorized();

        // Irosin has its own office: its admin verifies, the provincial admin only views.
        $this->verify('food_beverage', $this->grill->id, ['hours'], $this->irosinAdmin)->assertCreated();
        $this->verify('food_beverage', $this->grill->id, ['hours'])->assertForbidden();
        $this->actingAs($this->provincial)->getJson("/api/v1/admin/verifications/food_beverage/{$this->grill->id}")->assertOk()
            ->assertJsonPath('listing.can_manage', false)->assertJsonPath('listing.managed_by', 'Irosin Tourism Office');
        // The Irosin admin can't touch Bulan's listings.
        $this->verify('destination', $this->beach->id, ['hours'], $this->irosinAdmin)->assertForbidden();
        $this->actingAs($this->irosinAdmin)->getJson("/api/v1/admin/verifications/destination/{$this->beach->id}")->assertForbidden();

        $this->verify('destination', $this->beach->id, ['opening_hours'])->assertUnprocessable()->assertJsonValidationErrors('aspects.0');
        $this->verify('destination', $this->beach->id, [])->assertUnprocessable()->assertJsonValidationErrors('aspects');
        $this->beach->update(['status' => 'draft']);
        $this->verify('destination', $this->beach->id, ['price'])->assertNotFound();

        $this->actingAs($this->owner)->postJson("/api/v1/admin/verifications/food_beverage/{$this->grill->id}", ['aspects' => ['price']])->assertForbidden();
        $this->assertSame(1, ListingVerification::count());
    }

    public function test_the_admin_list_shows_what_needs_attention_within_each_admins_scope(): void
    {
        $this->verify('destination', $this->beach->id, ['price', 'location', 'hours', 'contact'])->assertCreated();
        $this->verify('accommodation', $this->inn->id, ['price'])->assertCreated();

        $list = fn (User $as, string $query = '') => $this->actingAs($as)->getJson('/api/v1/admin/verifications?'.$query)->assertOk();
        $this->assertSame(['Bay Inn', 'Irosin Grill', 'Pili Shop'], collect($list($this->provincial)->json('data'))->pluck('name')->sort()->values()->all(), 'Needs attention by default.');
        $list($this->provincial)->assertJsonPath('counts.verified', 1)->assertJsonPath('counts.needs_attention', 3)->assertJsonPath('counts.all', 4);
        $this->assertSame(['Otavi Beach'], array_column($list($this->provincial, 'status=verified')->json('data'), 'name'));
        $this->assertSame(['Bay Inn'], array_column($list($this->provincial, 'type=accommodation&status=all')->json('data'), 'name'));
        $this->assertSame(['Pili Shop'], array_column($list($this->provincial, 'status=all&search=pili')->json('data'), 'name'));

        $this->travel(31)->days();
        $this->assertSame(['Bay Inn', 'Otavi Beach'], collect($list($this->provincial, 'status=overdue')->json('data'))->pluck('name')->sort()->values()->all());

        // A municipal admin sees only their municipality's listings.
        $this->assertSame(['Irosin Grill'], array_column($list($this->irosinAdmin, 'status=all')->json('data'), 'name'));
    }
}

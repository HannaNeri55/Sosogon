<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\AccountAppeal;
use App\Models\Announcement;
use App\Models\BusinessOwnerApplication;
use App\Models\BusinessSubmission;
use App\Models\Destination;
use App\Models\Event;
use App\Models\FoodBeverage;
use App\Models\HeroSlide;
use App\Models\MenuItem;
use App\Models\Rating;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\ReviewReport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Municipality-based admin authority on the admin API, module by module: a Bulan municipal
 * admin works on Bulan records only (403 on Matnog's, through direct API calls too), while a
 * provincial admin, and an admin whose scope isn't assigned yet, keep province-wide access.
 */
class MunicipalAdminAccessTest extends TestCase
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

    private function owner(string $name, string $status = 'active', string $type = 'accommodation'): User
    {
        return User::create(['role_id' => $this->ownerRole, 'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com', 'password' => 'Secret123!', 'status' => $status, 'business_type' => $type]);
    }

    // ==================== 1. Owner applications ====================

    private function application(int $town, string $name): BusinessOwnerApplication
    {
        Storage::disk('local')->put("business-permits/registrations/{$name}.pdf", '%PDF-1.4 permit');

        return BusinessOwnerApplication::forceCreate([
            'user_id' => $this->owner("{$name} Owner", 'pending')->id, 'business_name' => $name, 'business_type' => 'accommodation',
            'business_address' => 'Zone 1', 'municipality_id' => $town, 'business_permit_number' => 'BP-'.$name,
            'business_permit_path' => "business-permits/registrations/{$name}.pdf", 'status' => 'pending',
        ]);
    }

    public function test_owner_applications_are_decided_by_their_municipalitys_admin(): void
    {
        $bulanApp = $this->application($this->bulan, 'BulanInn');
        $matnogApp = $this->application($this->matnog, 'MatnogInn');
        $base = '/api/v1/admin/owner-applications';

        $listed = $this->actingAs($this->bulanAdmin)->getJson($base)->assertOk()->json('*.id');
        $this->assertSame([$bulanApp->id], $listed, 'A Bulan admin lists Bulan registrations only.');

        $this->getJson("{$base}/{$matnogApp->id}")->assertForbidden();
        $this->get("{$base}/{$matnogApp->id}/permit")->assertForbidden();
        $this->putJson("{$base}/{$matnogApp->id}/approve")->assertForbidden();
        $this->putJson("{$base}/{$matnogApp->id}/reject", ['reason' => 'Permit is not readable, please upload again.'])->assertForbidden();
        $this->assertSame('pending', $matnogApp->fresh()->status);
        $this->assertSame('pending', $matnogApp->user->fresh()->status, 'The Matnog owner stays pending.');

        $this->getJson("{$base}/{$bulanApp->id}")->assertOk();
        $this->get("{$base}/{$bulanApp->id}/permit")->assertOk();
        $this->putJson("{$base}/{$bulanApp->id}/approve")->assertOk();
        $this->assertSame('active', $bulanApp->user->fresh()->status);

        // Province-wide authority: every municipality, including towns without a municipal admin.
        foreach ([$this->provincial, $this->unassigned] as $admin) {
            $this->assertCount(2, $this->actingAs($admin)->getJson($base)->assertOk()->json());
        }
        $this->actingAs($this->provincial)->putJson("{$base}/{$matnogApp->id}/approve")->assertOk();
    }

    // ==================== 2. Business submissions and revisions ====================

    private function submission(int $town, string $name, string $status = 'pending', ?Model $listing = null): BusinessSubmission
    {
        Storage::disk('local')->put("business-permits/{$name}.pdf", '%PDF-1.4 permit');

        return BusinessSubmission::forceCreate([
            'user_id' => $listing?->user_id ?? $this->owner("{$name} Owner")->id, 'business_name' => $name, 'type' => 'accommodation',
            'description' => "{$name} for testing.", 'address' => 'Zone 1', 'municipality_id' => $town, 'latitude' => 12.67, 'longitude' => 123.87, 'contact_number' => '09171234567',
            'barangay_id' => DB::table('barangays')->insertGetId(['municipality_id' => $town, 'name' => "Zone {$name}", 'created_at' => now(), 'updated_at' => now()]),
            'business_permit_path' => "business-permits/{$name}.pdf", 'status' => $status, 'listing_id' => $listing?->id,
        ]);
    }

    /** Owner route, admin listing path (null: shops use their own revision routes), images table, key, extra fields. */
    private const LISTING_TYPES = [
        Accommodation::class => ['accommodation', 'accommodations', 'accommodation_images', 'accommodation_id', ['type' => 'Inn', 'price_range' => 'moderate']],
        FoodBeverage::class => ['food-beverage', 'food-beverages', 'food_beverage_images', 'food_beverage_id', ['price_range' => 'moderate']],
        RetailShop::class => ['retail-shop', null, 'retail_shop_images', 'retail_shop_id', []],
    ];

    /** An owner's published listing with a 5-photo gallery. */
    private function publishedListing(string $class, int $town, string $name): Model
    {
        [, , $images, $fk, $extra] = self::LISTING_TYPES[$class];
        $owner = $this->owner("{$name} Owner", 'active', $class::SUBMISSION_TYPE);
        $listing = $class::create(['user_id' => $owner->id, 'name' => $name, 'description' => "{$name}, a family place near the plaza with friendly staff and a garden.",
            'municipality_id' => $town, 'address' => 'Rizal St.', 'latitude' => 12.67, 'longitude' => 123.87, 'status' => 'approved'] + $extra);
        $listing->forceFill(['publication_review_status' => 'published', 'approved_at' => now()])->save();
        foreach (range(0, 4) as $i) {
            DB::table($images)->insert([$fk => $listing->id, 'image_path' => "seed/{$listing->id}-{$i}.webp", 'thumbnail_path' => "seed/{$listing->id}-{$i}-t.webp",
                'is_primary' => $i === 0, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $listing;
    }

    /** The admin's "approve the submitted revision" call for a listing. */
    private function approveRevision(Model $listing)
    {
        $adminPath = self::LISTING_TYPES[$listing::class][1];

        return $adminPath
            ? $this->putJson("/api/v1/admin/{$adminPath}/{$listing->id}", ['revision_action' => 'approve'])
            : $this->putJson("/api/v1/admin/retail-shops/{$listing->id}/approve-revision");
    }

    public function test_business_submissions_are_handled_by_their_municipalitys_admin(): void
    {
        $bulanNew = $this->submission($this->bulan, 'Bulan Bay Inn');
        $matnogNew = $this->submission($this->matnog, 'Matnog Port Inn');
        $matnogLive = $this->submission($this->matnog, 'Matnog Live Inn', 'approved');
        $base = '/api/v1/admin/submissions';

        $listed = $this->actingAs($this->bulanAdmin)->getJson($base)->assertOk()->json('data.*.id');
        $this->assertSame([$bulanNew->id], $listed);

        $this->get("{$base}/{$matnogNew->id}/permit")->assertForbidden();
        $this->putJson("{$base}/{$matnogNew->id}/approve")->assertForbidden();
        $this->putJson("{$base}/{$matnogNew->id}/reject", ['notes' => 'Wrong permit.'])->assertForbidden();
        $this->putJson("{$base}/{$matnogLive->id}/warn", ['notes' => 'Many complaints.'])->assertForbidden();
        $this->putJson("{$base}/{$matnogLive->id}/clear-warning")->assertForbidden();
        $this->putJson("{$base}/{$matnogLive->id}/remove", ['notes' => 'Closed.'])->assertForbidden();
        $this->assertSame('pending', $matnogNew->fresh()->status);
        $this->assertSame('approved', $matnogLive->fresh()->status);
        $this->assertNull($matnogLive->fresh()->warned_at);

        $this->get("{$base}/{$bulanNew->id}/permit")->assertOk();
        $this->putJson("{$base}/{$bulanNew->id}/reject", ['notes' => 'Please upload a clearer permit.'])->assertOk();

        // Matnog has no municipal admin: its submissions are the provincial admins'.
        foreach ([$this->provincial, $this->unassigned] as $admin) {
            $this->assertCount(3, $this->actingAs($admin)->getJson($base)->assertOk()->json('data'));
        }
        $this->actingAs($this->provincial)->putJson("{$base}/{$matnogLive->id}/warn", ['notes' => 'Many complaints.'])->assertOk();
    }

    public function test_revisions_are_reviewed_in_their_municipality_and_moves_need_the_provincial_admin(): void
    {
        foreach (self::LISTING_TYPES as $class => [$route]) {
            $listing = $this->publishedListing($class, $this->bulan, class_basename($class).' Bulan');
            $submission = $this->submission($this->bulan, class_basename($class).' Bulan', 'approved', $listing);
            $submission->forceFill(['type' => $class::SUBMISSION_TYPE])->save();

            // The owner proposes moving the business to Matnog: not a Bulan decision.
            $this->actingAs($listing->user)->putJson("/api/v1/business/{$route}/location", ['address' => 'Port Area, Matnog', 'municipality_id' => $this->matnog])->assertOk();
            $this->actingAs($listing->user)->postJson("/api/v1/business/{$route}/submit-for-review")->assertOk();
            $this->actingAs($this->bulanAdmin);
            $this->approveRevision($listing)->assertForbidden()
                ->assertJsonPath('message', "Moving a listing to another municipality needs the provincial administrator's approval.");
            $this->assertSame($this->bulan, (int) $listing->fresh()->municipality_id, $class);

            // A Matnog listing's revision can't be reviewed from Bulan at all.
            $matnog = $this->publishedListing($class, $this->matnog, class_basename($class).' Matnog');
            $this->actingAs($matnog->user)->putJson("/api/v1/business/{$route}", ['description' => 'Updated. '.$matnog->description])->assertOk();
            $this->actingAs($matnog->user)->postJson("/api/v1/business/{$route}/submit-for-review")->assertOk();
            $this->actingAs($this->bulanAdmin);
            $this->approveRevision($matnog)->assertForbidden();
            $this->assertStringStartsNotWith('Updated.', (string) $matnog->fresh()->description);

            // The provincial admin approves the move; the submission follows the listing.
            $this->actingAs($this->provincial);
            $this->approveRevision($listing)->assertOk();
            $this->assertSame($this->matnog, (int) $listing->fresh()->municipality_id, $class);
            $this->assertSame($this->matnog, (int) $submission->fresh()->municipality_id, $class);
        }
    }

    // ==================== 3. Rooms, menu items and products ====================

    /** A pending room, dish and product in a municipality's listings. */
    private function items(int $town, string $label): array
    {
        $stay = $this->publishedListing(Accommodation::class, $town, "{$label} Inn");
        $eatery = $this->publishedListing(FoodBeverage::class, $town, "{$label} Grill");
        $shop = $this->publishedListing(RetailShop::class, $town, "{$label} Pasalubong");
        $barangay = DB::table('barangays')->insertGetId(['municipality_id' => $town, 'name' => "{$label} Proper", 'created_at' => now(), 'updated_at' => now()]);

        return [
            'rooms' => AccommodationRoom::forceCreate(['accommodation_id' => $stay->id, 'name' => "{$label} Deluxe", 'status' => 'pending']),
            'menu' => MenuItem::forceCreate(['food_beverage_id' => $eatery->id, 'name' => "{$label} Adobo", 'price' => 150, 'status' => 'pending']),
            'products' => RetailProduct::forceCreate(['retail_shop_id' => $shop->id, 'user_id' => $shop->user_id, 'name' => "{$label} Pili Tarts", 'description' => 'Sweet.',
                'product_type' => 'Delicacy', 'municipality_id' => $town, 'barangay_id' => $barangay, 'status' => 'pending']),
        ];
    }

    public function test_rooms_menu_items_and_products_follow_their_listings_municipality(): void
    {
        $bulan = $this->items($this->bulan, 'Bulan');
        $matnog = $this->items($this->matnog, 'Matnog');
        $paths = ['rooms' => 'accommodation-rooms', 'menu' => 'menu-items', 'products' => 'retail-products'];
        $this->actingAs($this->bulanAdmin);

        foreach ($paths as $kind => $path) {
            $listed = $this->getJson("/api/v1/admin/{$path}")->assertOk()->json('data.*.id');
            $this->assertSame([$bulan[$kind]->id], $listed, $kind);

            $id = $matnog[$kind]->id;
            $this->putJson("/api/v1/admin/{$path}/{$id}/approve")->assertForbidden();
            $this->putJson("/api/v1/admin/{$path}/{$id}/reject", ['notes' => 'Not allowed here.'])->assertForbidden();
            $this->putJson("/api/v1/admin/{$path}/{$id}/approve-changes", ['changes_hash' => 'x'])->assertForbidden();
            $this->putJson("/api/v1/admin/{$path}/{$id}/reject-changes", ['notes' => 'Not allowed here.'])->assertForbidden();
            $this->assertSame('pending', $matnog[$kind]->fresh()->status, $kind);

            $this->putJson("/api/v1/admin/{$path}/{$bulan[$kind]->id}/approve")->assertOk();
        }

        // Direct room correction, and product show/edit/delete/create.
        $this->putJson("/api/v1/admin/accommodation-rooms/{$matnog['rooms']->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->getJson("/api/v1/admin/retail-products/{$matnog['products']->id}")->assertForbidden();
        $this->putJson("/api/v1/admin/retail-products/{$matnog['products']->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/v1/admin/retail-products/{$matnog['products']->id}")->assertForbidden();
        $this->postJson('/api/v1/admin/retail-products', ['name' => 'Matnog Shells', 'description' => 'Craft.', 'product_type' => 'Craft',
            'municipality_id' => $this->matnog, 'status' => 'pending'])->assertForbidden();
        $this->assertSame('Matnog Deluxe', $matnog['rooms']->fresh()->name);
        $this->assertNotNull($matnog['products']->fresh());

        // A Bulan product can't be moved to Matnog by a payload, and its shop's town counts too.
        $this->putJson("/api/v1/admin/retail-products/{$bulan['products']->id}", ['municipality_id' => $this->matnog])->assertForbidden();
        $this->assertSame($this->bulan, (int) $bulan['products']->fresh()->municipality_id);
        $mismatched = $bulan['products']->replicate(['view_count']);
        $mismatched->forceFill(['retail_shop_id' => $matnog['products']->retail_shop_id])->save();
        $this->getJson("/api/v1/admin/retail-products/{$mismatched->id}")->assertForbidden();

        // Province-wide authority keeps every item.
        $this->actingAs($this->provincial);
        foreach ($paths as $kind => $path) {
            $this->putJson("/api/v1/admin/{$path}/{$matnog[$kind]->id}/approve")->assertOk();
        }
    }

    public function test_the_item_review_queue_and_bulk_decisions_stay_in_the_municipality(): void
    {
        $bulan = $this->items($this->bulan, 'Bulan');
        $matnog = $this->items($this->matnog, 'Matnog');

        $queue = $this->actingAs($this->bulanAdmin)->getJson('/api/v1/admin/item-reviews')->assertOk();
        $this->assertEqualsCanonicalizing(
            ["rooms-{$bulan['rooms']->id}", "menu-{$bulan['menu']->id}", "products-{$bulan['products']->id}"],
            $queue->json('data.*.key'),
        );
        $queue->assertJsonPath('counts.new', 3)->assertJsonPath('counts.total', 3);
        $this->assertCount(3, $queue->json('businesses'), 'Only Bulan businesses in the filter.');

        $bulk = $this->postJson('/api/v1/admin/item-reviews/bulk', ['action' => 'approve', 'scope' => 'new', 'items' => [
            ['kind' => 'rooms', 'id' => $bulan['rooms']->id], ['kind' => 'rooms', 'id' => $matnog['rooms']->id], ['kind' => 'menu', 'id' => $matnog['menu']->id],
        ]])->assertOk();
        $bulk->assertJsonPath('succeeded', 1)->assertJsonPath('failed', 2);
        $this->assertSame('approved', $bulan['rooms']->fresh()->status);
        $this->assertSame('pending', $matnog['rooms']->fresh()->status);
        $this->assertSame('pending', $matnog['menu']->fresh()->status);

        $this->actingAs($this->provincial)->getJson('/api/v1/admin/item-reviews')->assertOk()->assertJsonPath('counts.new', 5);
    }

    // ==================== 4. Destinations and accommodations ====================

    private function destination(int $town, string $name): Destination
    {
        DB::table('categories')->insertOrIgnore(['id' => 1, 'name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $destination = Destination::create(['name' => $name, 'description' => "{$name} for testing.", 'category_id' => 1, 'municipality_id' => $town,
            'address' => 'Shore Rd.', 'latitude' => 12.6, 'longitude' => 123.9, 'created_by' => $this->provincial->id, 'status' => 'published']);
        $image = $destination->images()->create(['image_path' => "seed/{$destination->id}.webp", 'thumbnail_path' => "seed/{$destination->id}-t.webp", 'is_primary' => false, 'sort_order' => 0]);

        return $destination->setRelation('firstImage', $image);
    }

    public function test_destinations_are_managed_by_their_municipality(): void
    {
        $bulan = $this->destination($this->bulan, 'Bulan Beach');
        $matnog = $this->destination($this->matnog, 'Matnog Beach');
        $image = $matnog->firstImage->id;
        $base = '/api/v1/admin/destinations';
        $this->actingAs($this->bulanAdmin);

        $this->assertSame([$bulan->id], $this->getJson($base)->assertOk()->json('data.*.id'));
        $this->getJson("{$base}/{$matnog->id}")->assertForbidden();
        $this->putJson("{$base}/{$matnog->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->patchJson("{$base}/{$matnog->id}/images/{$image}/cover")->assertForbidden();
        $this->deleteJson("{$base}/{$matnog->id}/images/{$image}")->assertForbidden();
        $this->deleteJson("{$base}/{$matnog->id}")->assertForbidden();
        $this->postJson($base, ['name' => 'New Matnog Spot', 'description' => 'A new place.', 'category_id' => 1, 'municipality_id' => $this->matnog,
            'address' => 'Port Rd.', 'latitude' => 12.58, 'longitude' => 124.08, 'status' => 'draft'])->assertForbidden();
        $this->assertSame('Matnog Beach', $matnog->fresh()->name);
        $this->assertSame(1, $matnog->images()->count());

        // Their own: everything works, but not a move to another municipality by payload.
        $this->getJson("{$base}/{$bulan->id}")->assertOk();
        $this->putJson("{$base}/{$bulan->id}", ['name' => 'Bulan Beach Park'])->assertOk();
        $this->putJson("{$base}/{$bulan->id}", ['municipality_id' => $this->matnog])->assertForbidden();
        $this->assertSame($this->bulan, (int) $bulan->fresh()->municipality_id);
        $this->postJson($base, ['name' => 'New Bulan Spot', 'description' => 'A new place.', 'category_id' => 1, 'municipality_id' => $this->bulan,
            'address' => 'Zone 2', 'latitude' => 12.67, 'longitude' => 123.88, 'status' => 'draft'])->assertCreated();

        $this->actingAs($this->provincial);
        $this->assertCount(3, $this->getJson($base)->assertOk()->json('data'));
        $this->putJson("{$base}/{$matnog->id}", ['municipality_id' => $this->bulan])->assertOk();
    }

    public function test_accommodations_are_managed_by_their_municipality(): void
    {
        $bulan = $this->publishedListing(Accommodation::class, $this->bulan, 'Bulan Inn');
        $matnog = $this->publishedListing(Accommodation::class, $this->matnog, 'Matnog Inn');
        $base = '/api/v1/admin/accommodations';
        $this->actingAs($this->bulanAdmin);

        $this->assertSame([$bulan->id], $this->getJson($base)->assertOk()->json('data.*.id'));
        $this->getJson("{$base}/{$matnog->id}")->assertForbidden();
        $this->putJson("{$base}/{$matnog->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("{$base}/{$matnog->id}")->assertForbidden();
        $this->postJson($base, ['name' => 'New Matnog Inn', 'description' => 'Rooms.', 'type' => 'Inn', 'municipality_id' => $this->matnog,
            'address' => 'Port Rd.', 'latitude' => 12.58, 'longitude' => 124.08, 'price_range' => 'budget', 'status' => 'pending'])->assertForbidden();
        $this->assertSame(0, Accommodation::where('name', 'New Matnog Inn')->count());
        $this->assertSame('Matnog Inn', $matnog->fresh()->name);

        $this->getJson("{$base}/{$bulan->id}")->assertOk();
        $this->putJson("{$base}/{$bulan->id}", ['name' => 'Bulan Bay Inn'])->assertOk();
        $this->putJson("{$base}/{$bulan->id}", ['municipality_id' => $this->matnog])->assertForbidden();
        $this->assertSame($this->bulan, (int) $bulan->fresh()->municipality_id);

        $this->actingAs($this->provincial);
        $this->assertCount(2, $this->getJson($base)->assertOk()->json('data'));
        $this->putJson("{$base}/{$matnog->id}", ['name' => 'Matnog Port Inn'])->assertOk();
    }

    // ==================== 5. Food & beverage and shops ====================

    public function test_food_and_beverage_listings_are_managed_by_their_municipality(): void
    {
        $bulan = $this->publishedListing(FoodBeverage::class, $this->bulan, 'Bulan Grill');
        $matnog = $this->publishedListing(FoodBeverage::class, $this->matnog, 'Matnog Grill');
        $base = '/api/v1/admin/food-beverages';
        $this->actingAs($this->bulanAdmin);

        $this->assertSame([$bulan->id], $this->getJson($base)->assertOk()->json('data.*.id'));
        $this->getJson("{$base}/{$matnog->id}")->assertForbidden();
        $this->putJson("{$base}/{$matnog->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("{$base}/{$matnog->id}")->assertForbidden();
        $this->postJson($base, ['name' => 'New Matnog Eatery', 'description' => 'Meals.', 'cuisine_type' => 'Filipino', 'municipality_id' => $this->matnog,
            'address' => 'Port Rd.', 'latitude' => 12.58, 'longitude' => 124.08, 'price_range' => 'budget', 'status' => 'pending'])->assertForbidden();
        $this->assertSame('Matnog Grill', $matnog->fresh()->name);
        $this->assertSame(0, FoodBeverage::where('name', 'New Matnog Eatery')->count());

        $this->getJson("{$base}/{$bulan->id}")->assertOk();
        $this->putJson("{$base}/{$bulan->id}", ['name' => 'Bulan Seaside Grill'])->assertOk();

        $this->actingAs($this->provincial);
        $this->assertCount(2, $this->getJson($base)->assertOk()->json('data'));
        $this->putJson("{$base}/{$matnog->id}", ['name' => 'Matnog Port Grill'])->assertOk();
    }

    public function test_shops_are_managed_by_their_municipality(): void
    {
        $bulan = $this->publishedListing(RetailShop::class, $this->bulan, 'Bulan Pasalubong');
        $matnog = $this->publishedListing(RetailShop::class, $this->matnog, 'Matnog Pasalubong');
        // A Matnog shop waiting for publication review.
        $matnog->forceFill(['status' => 'pending', 'publication_review_status' => 'submitted'])->save();
        $base = '/api/v1/admin/retail-shops';
        $this->actingAs($this->bulanAdmin);

        $this->assertSame([$bulan->id], $this->getJson($base)->assertOk()->json('data.*.id'));
        $this->getJson("{$base}/{$matnog->id}")->assertForbidden();
        $this->putJson("{$base}/{$matnog->id}/approve")->assertForbidden();
        $this->putJson("{$base}/{$matnog->id}/reject", ['notes' => 'Missing photos.'])->assertForbidden();
        $this->putJson("{$base}/{$matnog->id}/request-revision-changes", ['notes' => 'Missing photos.'])->assertForbidden();
        $this->assertSame('pending', $matnog->fresh()->status);

        $this->getJson("{$base}/{$bulan->id}")->assertOk();

        $this->actingAs($this->provincial);
        $this->assertCount(2, $this->getJson($base)->assertOk()->json('data'));
        $this->putJson("{$base}/{$matnog->id}/approve")->assertOk();
    }

    // ==================== 6. Events, announcements and hero slides ====================

    private function event(?int $town, string $title): Event
    {
        return Event::create(['title' => $title, 'description' => 'Fiesta.', 'municipality_id' => $town, 'start_date' => now()->addWeek()->toDateString(),
            'status' => 'published', 'created_by' => $this->provincial->id]);
    }

    public function test_events_belong_to_a_municipality_or_the_whole_province(): void
    {
        $bulan = $this->event($this->bulan, 'Bulan Fiesta');
        $matnog = $this->event($this->matnog, 'Matnog Fiesta');
        $province = $this->event(null, 'Kasanggayahan Festival');
        $base = '/api/v1/admin/events';
        $this->actingAs($this->bulanAdmin);

        $rows = collect($this->getJson($base)->assertOk()->json('data'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$bulan->id, $province->id], $rows->keys()->all(), 'Their own and the province-wide ones.');
        $this->assertTrue($rows[$bulan->id]['can_manage']);
        $this->assertFalse($rows[$province->id]['can_manage']);

        $this->getJson("{$base}/{$province->id}")->assertOk()->assertJsonPath('can_manage', false);
        $this->getJson("{$base}/{$matnog->id}")->assertForbidden();
        foreach ([$matnog, $province] as $event) {
            $this->putJson("{$base}/{$event->id}", ['title' => 'Renamed'])->assertForbidden();
            $this->deleteJson("{$base}/{$event->id}")->assertForbidden();
        }
        $this->putJson("{$base}/{$bulan->id}", ['municipality_id' => null])->assertForbidden();
        $this->putJson("{$base}/{$bulan->id}", ['municipality_id' => $this->matnog])->assertForbidden();
        $this->putJson("{$base}/{$bulan->id}", ['title' => 'Bulan Town Fiesta'])->assertOk();

        $new = ['title' => 'Bulan Night Market', 'description' => 'Food stalls.', 'start_date' => now()->addMonth()->toDateString(), 'status' => 'draft'];
        $this->postJson($base, $new + ['municipality_id' => $this->matnog])->assertForbidden();
        $created = $this->postJson($base, $new)->assertCreated();
        $this->assertSame($this->bulan, (int) $created->json('event.municipality_id'), 'No municipality given: their own.');
        $this->assertSame('Kasanggayahan Festival', $province->fresh()->title);

        $this->actingAs($this->provincial);
        $this->assertCount(4, $this->getJson($base)->assertOk()->json('data'));
        $this->putJson("{$base}/{$province->id}", ['title' => 'Kasanggayahan Festival 2026'])->assertOk();
        $this->assertNull($this->postJson($base, $new)->assertCreated()->json('event.municipality_id'), 'Province-wide when none is given.');
    }

    public function test_announcements_belong_to_a_municipality_or_the_whole_province(): void
    {
        $province = Announcement::create(['title' => 'Province advisory', 'content' => 'All towns.', 'status' => 'published', 'created_by' => $this->provincial->id]);
        $matnog = Announcement::create(['title' => 'Matnog advisory', 'content' => 'Port.', 'status' => 'published', 'municipality_id' => $this->matnog, 'created_by' => $this->provincial->id]);
        $base = '/api/v1/admin/announcements';
        $this->actingAs($this->bulanAdmin);

        $created = $this->postJson($base, ['title' => 'Bulan advisory', 'content' => 'Town hall closed.', 'status' => 'published'])->assertCreated();
        $bulanId = $created->json('announcement.id');
        $this->assertSame($this->bulan, (int) $created->json('announcement.municipality_id'));

        $this->assertEqualsCanonicalizing([$province->id, $bulanId], $this->getJson($base)->assertOk()->json('data.*.id'));
        $this->getJson("{$base}/{$province->id}")->assertOk()->assertJsonPath('can_manage', false);
        $this->getJson("{$base}/{$matnog->id}")->assertForbidden();
        foreach ([$province, $matnog] as $item) {
            $this->putJson("{$base}/{$item->id}", ['title' => 'Changed'])->assertForbidden();
            $this->deleteJson("{$base}/{$item->id}")->assertForbidden();
        }
        $this->postJson($base, ['title' => 'Everyone', 'content' => 'Province.', 'status' => 'draft', 'municipality_id' => $this->matnog])->assertForbidden();
        $this->putJson("{$base}/{$bulanId}", ['municipality_id' => null])->assertForbidden();
        $this->putJson("{$base}/{$bulanId}", ['title' => 'Bulan advisory (updated)'])->assertOk();
        $this->deleteJson("{$base}/{$bulanId}")->assertOk();
        $this->assertSame('Province advisory', $province->fresh()->title);

        $this->actingAs($this->unassigned);
        $this->assertCount(2, $this->getJson($base)->assertOk()->json('data'));
        $this->putJson("{$base}/{$province->id}", ['title' => 'Province advisory (updated)'])->assertOk();
    }

    public function test_hero_slides_are_changed_by_provincial_admins_only(): void
    {
        $slide = HeroSlide::create(['title' => 'Welcome to Sorsogon', 'image_path' => 'hero/a.webp', 'sort_order' => 0, 'is_active' => true, 'created_by' => $this->provincial->id]);
        $base = '/api/v1/admin/hero-slides';
        $this->actingAs($this->bulanAdmin);

        $this->getJson($base)->assertOk();
        $this->getJson("{$base}/{$slide->id}")->assertOk();
        $this->putJson("{$base}/{$slide->id}", ['title' => 'Welcome to Bulan'])->assertForbidden();
        $this->deleteJson("{$base}/{$slide->id}")->assertForbidden();
        $this->postJson($base, ['title' => 'New'])->assertForbidden();
        $this->assertSame('Welcome to Sorsogon', $slide->fresh()->title);

        $this->actingAs($this->provincial)->putJson("{$base}/{$slide->id}", ['title' => 'Welcome to the Province'])->assertOk();
    }

    // ==================== 7. Reviews and review reports ====================

    private function tourist(string $name): User
    {
        return User::create(['role_id' => $this->touristRole, 'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@example.com', 'password' => 'Secret123!', 'status' => 'active']);
    }

    /** A review of $listing and a pending report on it by the listing's owner, with evidence. */
    private function reportedReview(Model $listing, string $reviewer): ReviewReport
    {
        $rating = Rating::create(['user_id' => $this->tourist($reviewer)->id, 'rateable_type' => $listing::class, 'rateable_id' => $listing->id, 'score' => 1, 'comment' => 'Terrible.']);
        Storage::disk('local')->put("review-reports/{$rating->id}.png", 'png');
        $report = ReviewReport::create(['rating_id' => $rating->id, 'reported_by' => $listing->user_id, 'reason' => 'offensive', 'details' => 'Abusive words.']);
        $report->forceFill(['evidence_path' => "review-reports/{$rating->id}.png"])->save();

        return $report;
    }

    public function test_reviews_and_review_reports_are_handled_in_the_listings_municipality(): void
    {
        $bulanReport = $this->reportedReview($this->publishedListing(Accommodation::class, $this->bulan, 'Bulan Inn'), 'Ana Cruz');
        $matnogReport = $this->reportedReview($this->publishedListing(Accommodation::class, $this->matnog, 'Matnog Inn'), 'Ben Reyes');
        $this->actingAs($this->bulanAdmin);

        $reviews = $this->getJson('/api/v1/admin/guest-reviews')->assertOk();
        $this->assertSame([$bulanReport->rating_id], $reviews->json('data.*.id'));
        $reviews->assertJsonPath('counts.accommodation', 1)->assertJsonPath('summary.total', 1);

        $reports = $this->getJson('/api/v1/admin/review-reports')->assertOk();
        $this->assertSame([$bulanReport->id], $reports->json('data.*.id'));
        $reports->assertJsonPath('counts.pending', 1);

        $this->putJson("/api/v1/admin/review-reports/{$matnogReport->id}", ['status' => 'action_taken', 'decision_note' => 'Hidden for abuse.'])->assertForbidden();
        $this->get("/api/v1/admin/review-reports/{$matnogReport->id}/evidence")->assertForbidden();
        $this->assertSame('pending', $matnogReport->fresh()->status);
        $this->assertNull($matnogReport->rating->fresh()->hidden_at, 'The Matnog review stays visible.');

        $this->get("/api/v1/admin/review-reports/{$bulanReport->id}/evidence")->assertOk();
        $this->putJson("/api/v1/admin/review-reports/{$bulanReport->id}", ['status' => 'action_taken', 'decision_note' => 'Hidden for abuse.'])->assertOk();
        $this->assertNotNull($bulanReport->rating->fresh()->hidden_at);

        $this->actingAs($this->provincial);
        $this->assertCount(2, $this->getJson('/api/v1/admin/guest-reviews')->assertOk()->json('data'));
        $this->putJson("/api/v1/admin/review-reports/{$matnogReport->id}", ['status' => 'dismissed', 'decision_note' => 'The review is fair.'])->assertOk();
    }

    // ==================== 8. Users and account enforcement ====================

    private function enforce(User $target, string $action = 'warning', ?Rating $rating = null)
    {
        return $this->postJson("/api/v1/admin/users/{$target->id}/enforcement", array_filter([
            'action' => $action, 'category' => 'fake_reviews', 'reason' => 'Fake reviews',
            'details' => 'Posted several reviews that did not describe a real visit.', 'suspension_days' => $action === 'suspension' ? 7 : null,
            'rating_id' => $rating?->id, 'confirmed' => true,
        ], fn ($value) => $value !== null));
    }

    public function test_user_management_follows_the_business_municipality_and_reviews(): void
    {
        $bulanStay = $this->publishedListing(Accommodation::class, $this->bulan, 'Bulan Inn');
        $matnogStay = $this->publishedListing(Accommodation::class, $this->matnog, 'Matnog Inn');
        [$bulanOwner, $matnogOwner] = [$bulanStay->user, $matnogStay->user];
        $legacyOwner = $this->owner('Old Owner'); // no listing, no registration application
        $ana = $this->tourist('Ana Cruz');
        $ben = $this->tourist('Ben Reyes');
        $anaInBulan = Rating::create(['user_id' => $ana->id, 'rateable_type' => Accommodation::class, 'rateable_id' => $bulanStay->id, 'score' => 1, 'comment' => 'Fake.']);
        $benInMatnog = Rating::create(['user_id' => $ben->id, 'rateable_type' => Accommodation::class, 'rateable_id' => $matnogStay->id, 'score' => 1, 'comment' => 'Fake.']);
        $this->actingAs($this->bulanAdmin);

        // The list: Bulan's owners and the tourists who reviewed Bulan listings; never admins.
        $listed = $this->getJson('/api/v1/admin/users?per_page=100')->assertOk()->json('data.*.id');
        $this->assertEqualsCanonicalizing([$bulanOwner->id, $ana->id], $listed);
        $this->getJson("/api/v1/admin/users/{$bulanOwner->id}/account")->assertOk();
        // The account shows the tourist's reviews this admin can act over: Bulan's only.
        $this->assertSame([$anaInBulan->id], $this->getJson("/api/v1/admin/users/{$ana->id}/account")->assertOk()->json('reviews.*.id'));
        foreach ([$matnogOwner, $ben, $legacyOwner, $this->provincial] as $other) {
            $this->getJson("/api/v1/admin/users/{$other->id}/account")->assertForbidden();
        }

        // Owners by their business's municipality.
        $this->enforce($bulanOwner)->assertCreated();
        $this->enforce($matnogOwner)->assertForbidden();
        $this->enforce($legacyOwner)->assertForbidden();
        $this->enforce($this->provincial)->assertForbidden();
        $this->putJson("/api/v1/admin/users/{$bulanOwner->id}", ['name' => 'Bulan Inn Owner'])->assertOk();
        $this->putJson("/api/v1/admin/users/{$matnogOwner->id}", ['name' => 'Renamed'])->assertForbidden();

        // Tourists only over their review of a Bulan listing.
        $this->enforce($ana)->assertForbidden();
        $this->enforce($ana, 'warning', $benInMatnog)->assertUnprocessable()->assertJsonValidationErrors('rating_id');
        $this->enforce($ben, 'warning', $benInMatnog)->assertForbidden();
        $this->enforce($ana, 'suspension', $anaInBulan)->assertCreated()->assertJsonPath('violation.rating_id', $anaInBulan->id);
        $this->postJson("/api/v1/admin/users/{$ana->id}/reactivate", ['reason' => 'Removed the fake review.'])->assertOk();
        $this->postJson("/api/v1/admin/users/{$ana->id}/close", ['reason' => 'Repeated fake reviews.', 'confirm_email' => $ana->email])->assertForbidden();
        $this->putJson("/api/v1/admin/users/{$ana->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->postJson("/api/v1/admin/users/{$matnogOwner->id}/close", ['reason' => 'Closed business.', 'confirm_email' => $matnogOwner->email])->assertForbidden();
        $this->assertSame(0, $matnogOwner->violations()->count());
        $this->assertSame(0, $ben->violations()->count());

        // A provincial warning to Ana that isn't about a Bulan review stays out of Bulan's lists.
        $this->actingAs($this->provincial);
        $this->enforce($ana)->assertCreated();
        $this->enforce($legacyOwner)->assertCreated();
        $this->enforce($matnogOwner)->assertCreated();
        $provincialWarning = $ana->violations()->whereNull('rating_id')->firstOrFail();
        $appeal = AccountAppeal::create(['account_violation_id' => $provincialWarning->id, 'user_id' => $ana->id, 'explanation' => 'I never posted that.', 'status' => 'pending']);

        $this->actingAs($this->bulanAdmin);
        $violations = $this->getJson('/api/v1/admin/account-violations')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(
            [[$bulanOwner->id, null], [$ana->id, $anaInBulan->id]],
            collect($violations)->map(fn ($v) => [$v['user_id'], $v['rating_id']])->all(),
        );
        $this->assertSame([], $this->getJson('/api/v1/admin/account-appeals')->assertOk()->json('data'));
        $this->postJson("/api/v1/admin/account-appeals/{$appeal->id}/decision", ['decision' => 'approved', 'note' => 'The warning was a mistake.'])->assertForbidden();
        $this->assertSame('pending', $appeal->fresh()->status);

        $logged = collect($this->getJson('/api/v1/admin/account-log')->assertOk()->json('data'));
        $this->assertTrue($logged->every(fn ($row) => $row['user_id'] === $bulanOwner->id || $row['admin_id'] === $this->bulanAdmin->id));
        $this->assertFalse($logged->contains('user_id', $matnogOwner->id));

        // Province-wide authority: every account, admins included, as before.
        $this->actingAs($this->provincial);
        $this->assertCount(8, $this->getJson('/api/v1/admin/users?per_page=100')->assertOk()->json('data'));
        $this->postJson("/api/v1/admin/account-appeals/{$appeal->id}/decision", ['decision' => 'approved', 'note' => 'The warning was a mistake.'])->assertOk();
    }

    // ==================== 9. Dashboard and reports ====================

    public function test_the_dashboard_and_reports_show_the_municipalitys_figures(): void
    {
        $bulanBeach = $this->destination($this->bulan, 'Bulan Beach');
        $matnogBeach = $this->destination($this->matnog, 'Matnog Beach');
        $bulanBeach->forceFill(['view_count' => 40])->save();
        $matnogBeach->forceFill(['view_count' => 90])->save();
        $this->publishedListing(Accommodation::class, $this->bulan, 'Bulan Inn');
        $this->publishedListing(Accommodation::class, $this->matnog, 'Matnog Inn');
        $this->publishedListing(Accommodation::class, $this->matnog, 'Matnog Lodge');
        $this->submission($this->bulan, 'Bulan Cafe');
        $this->submission($this->matnog, 'Matnog Cafe');
        $this->event($this->bulan, 'Bulan Fiesta');
        $this->event($this->matnog, 'Matnog Fiesta');
        $today = now(config('app.local_timezone'))->toDateString();
        foreach (["/destinations/{$bulanBeach->id}" => 7, "/destinations/{$matnogBeach->id}" => 20, '/' => 50] as $path => $views) {
            DB::table('page_views')->insert(['date' => $today, 'path' => $path, 'views' => $views, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('search_logs')->insert(['query' => 'whale shark', 'results_count' => 3, 'created_at' => now()]);
        $this->actingAs($this->bulanAdmin);

        $stats = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats');
        $this->assertSame(1, $stats['total_destinations']);
        $this->assertSame(1, $stats['total_accommodations']);
        $this->assertSame(1, $stats['pending_submissions']);
        $this->assertSame(1, $stats['total_events']);
        $this->assertSame(7, $stats['monthly_views'], 'Only the views of Bulan pages.');
        $this->assertSame(2, $stats['total_users'], "The Bulan inn's owner and the Bulan Cafe's (known by its submission).");

        $this->assertSame(7, $this->getJson('/api/v1/admin/reports/overview')->assertOk()->json('monthly_views'));
        $this->assertNull($this->getJson('/api/v1/admin/reports/overview')->json('monthly_searches'));
        $this->assertSame(7, collect($this->getJson('/api/v1/admin/reports/visitors?days=7')->assertOk()->json())->sum('total_views'));
        $this->assertSame([$bulanBeach->id], $this->getJson('/api/v1/admin/reports/popular')->assertOk()->json('*.id'));
        $this->assertSame([], $this->getJson('/api/v1/admin/reports/searches')->assertOk()->json());
        $this->get('/api/v1/admin/reports/export')->assertOk();

        $this->actingAs($this->provincial);
        $stats = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json('stats');
        $this->assertSame(2, $stats['total_destinations']);
        $this->assertSame(3, $stats['total_accommodations']);
        $this->assertSame(77, $stats['monthly_views']);
        $this->assertSame([$matnogBeach->id, $bulanBeach->id], $this->getJson('/api/v1/admin/reports/popular')->assertOk()->json('*.id'));
        $this->assertSame(1, $this->getJson('/api/v1/admin/reports/overview')->json('monthly_searches'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/reports/searches')->assertOk()->json());
    }

    // ==================== Province-wide rankings: Featured and Tourism priority ====================

    public function test_only_provincial_admins_set_featured_and_tourism_priority(): void
    {
        $beach = $this->destination($this->bulan, 'Bulan Beach');
        $fiesta = $this->event($this->bulan, 'Bulan Fiesta');
        $new = ['description' => 'A new place.', 'category_id' => 1, 'municipality_id' => $this->bulan, 'address' => 'Zone 2', 'latitude' => 12.67, 'longitude' => 123.88, 'status' => 'draft'];
        $this->actingAs($this->bulanAdmin);

        // Changing a ranking: 403, on new and existing records.
        $this->postJson('/api/v1/admin/destinations', $new + ['name' => 'Featured Spot', 'is_featured' => true])->assertForbidden();
        $this->postJson('/api/v1/admin/destinations', $new + ['name' => 'Iconic Spot', 'tourism_priority' => 'iconic'])->assertForbidden();
        $this->putJson("/api/v1/admin/destinations/{$beach->id}", ['is_featured' => true])->assertForbidden();
        $this->putJson("/api/v1/admin/destinations/{$beach->id}", ['tourism_priority' => 'popular'])->assertForbidden();
        $this->putJson("/api/v1/admin/events/{$fiesta->id}", ['is_featured' => true])->assertForbidden();
        $this->postJson('/api/v1/admin/events', ['title' => 'Night Market', 'description' => 'Stalls.', 'start_date' => now()->addMonth()->toDateString(),
            'status' => 'draft', 'is_featured' => true])->assertForbidden();
        $this->assertFalse((bool) $beach->fresh()->is_featured);
        $this->assertNull($beach->fresh()->tourism_priority);
        $this->assertSame(0, Destination::whereIn('name', ['Featured Spot', 'Iconic Spot'])->count());

        // The forms send every field: unchanged rankings are fine.
        $this->postJson('/api/v1/admin/destinations', $new + ['name' => 'Plain Spot', 'is_featured' => false, 'tourism_priority' => null])->assertCreated();
        $this->putJson("/api/v1/admin/destinations/{$beach->id}", ['name' => 'Bulan Beach Park', 'is_featured' => false, 'tourism_priority' => null])->assertOk();
        $this->putJson("/api/v1/admin/events/{$fiesta->id}", ['title' => 'Bulan Town Fiesta', 'is_featured' => false])->assertOk();

        // The provincial admin sets them, and the municipal admin can still edit the record after.
        $this->actingAs($this->provincial);
        $this->putJson("/api/v1/admin/destinations/{$beach->id}", ['is_featured' => true, 'tourism_priority' => 'iconic'])->assertOk();
        $this->putJson("/api/v1/admin/events/{$fiesta->id}", ['is_featured' => true])->assertOk();
        $this->actingAs($this->bulanAdmin);
        $this->putJson("/api/v1/admin/destinations/{$beach->id}", ['name' => 'Bulan Beach', 'is_featured' => true, 'tourism_priority' => 'iconic'])->assertOk();
        $this->putJson("/api/v1/admin/destinations/{$beach->id}", ['is_featured' => false])->assertForbidden();
        $this->assertSame('iconic', $beach->fresh()->tourism_priority);
    }

    // ==================== Fail-closed: an invalid admin_scope ====================

    public function test_an_admin_with_an_invalid_scope_is_refused_everywhere_in_the_admin_api(): void
    {
        $bulanBeach = $this->destination($this->bulan, 'Bulan Beach');
        $matnogBeach = $this->destination($this->matnog, 'Matnog Beach');
        $invalid = $this->bulanAdmin->replicate(['email']);
        $invalid->forceFill(['email' => 'invalid.admin@example.com', 'admin_scope' => 'Provincial', 'municipality_id' => $this->bulan])->save();
        $this->actingAs($invalid);

        $requests = [
            ['GET', '/api/v1/admin/dashboard'],
            ['GET', '/api/v1/admin/destinations'],
            ['GET', "/api/v1/admin/destinations/{$bulanBeach->id}"],
            ['GET', "/api/v1/admin/destinations/{$matnogBeach->id}"],
            ['PUT', "/api/v1/admin/destinations/{$bulanBeach->id}", ['name' => 'Renamed']],
            ['PUT', "/api/v1/admin/destinations/{$matnogBeach->id}", ['is_featured' => true]],
            ['POST', '/api/v1/admin/hero-slides', ['title' => 'New']],
            ['GET', '/api/v1/admin/hero-slides'],
            ['GET', '/api/v1/admin/users'],
            ['GET', '/api/v1/admin/reports/searches'],
        ];
        foreach ($requests as $request) {
            [$method, $uri] = $request;
            $this->json($method, $uri, $request[2] ?? [])->assertForbidden()
                ->assertJsonPath('message', "This admin account's scope isn't valid, so admin access is blocked. Ask the system administrator to assign its scope.");
        }
        $this->assertSame('Bulan Beach', $bulanBeach->fresh()->name);
        $this->assertFalse((bool) $matnogBeach->fresh()->is_featured);

        // It can still sign in and see why; nothing about the account was changed or repaired.
        $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('admin.scope', 'invalid');
        $this->assertSame('Provincial', $invalid->fresh()->admin_scope);

        // The other scopes keep their access.
        $this->actingAs($this->unassigned)->getJson("/api/v1/admin/destinations/{$matnogBeach->id}")->assertOk();
        $this->actingAs($this->provincial)->getJson("/api/v1/admin/destinations/{$matnogBeach->id}")->assertOk();
        $this->actingAs($this->bulanAdmin)->getJson("/api/v1/admin/destinations/{$bulanBeach->id}")->assertOk();
        $this->actingAs($this->bulanAdmin)->getJson("/api/v1/admin/destinations/{$matnogBeach->id}")->assertForbidden();
    }
}

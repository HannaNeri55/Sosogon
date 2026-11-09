<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\ActivityLog;
use App\Models\FoodBeverage;
use App\Models\MenuItem;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminItemReviewTest extends TestCase
{
    use RefreshDatabase;

    private const LIST = '/api/v1/admin/item-reviews';

    private const BULK = '/api/v1/admin/item-reviews/bulk';

    private User $admin;

    private User $owner;

    private int $town;

    private Accommodation $stay;

    private FoodBeverage $restaurant;

    private RetailShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $adminRole = Role::create(['name' => 'admin', 'description' => 'Admin'])->id;
        $ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Owner'])->id;
        $this->admin = User::create(['role_id' => $adminRole, 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x'), 'status' => 'active']);
        $this->owner = User::create(['role_id' => $ownerRole, 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => bcrypt('x'), 'status' => 'active', 'business_type' => 'food_beverage']);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Gubat', 'created_at' => now(), 'updated_at' => now()]);

        $base = ['user_id' => $this->owner->id, 'description' => 'A place.', 'municipality_id' => $this->town, 'address' => 'Gubat', 'latitude' => 12.9, 'longitude' => 124.1, 'status' => 'pending'];
        $this->stay = Accommodation::create($base + ['name' => 'Hilltop Inn', 'type' => 'Inn', 'price_range' => 'moderate']);
        $this->restaurant = FoodBeverage::create($base + ['name' => 'Sea Grill', 'price_range' => 'moderate']);
        $this->shop = RetailShop::create($base + ['name' => 'Pili House']);
    }

    private function room(string $name, string $status = 'pending'): AccommodationRoom
    {
        $room = AccommodationRoom::create(['accommodation_id' => $this->stay->id, 'room_number' => $name, 'name' => $name, 'price' => 1500]);
        $room->forceFill(['status' => $status])->save();

        return $room->fresh();
    }

    private function dish(string $name, string $status = 'pending'): MenuItem
    {
        $item = MenuItem::create(['food_beverage_id' => $this->restaurant->id, 'name' => $name, 'description' => 'A local dish.', 'price' => 120]);
        $item->forceFill(['status' => $status])->save();

        return $item->fresh();
    }

    private function product(string $name, string $status = 'pending'): RetailProduct
    {
        $item = RetailProduct::create(['retail_shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'name' => $name, 'description' => 'A local product.', 'product_type' => 'Food', 'municipality_id' => $this->town, 'price' => 80]);
        $item->forceFill(['status' => $status])->save();

        return $item->fresh();
    }

    private function propose($item, array $values): void
    {
        $item->forceFill(['pending_changes' => ['values' => $values, 'base' => array_map(fn ($field) => $item->getAttribute($field), array_combine(array_keys($values), array_keys($values))), 'status' => 'pending', 'submitted_at' => now()->toIso8601String()]])->save();
    }

    private function list(array $query = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->getJson(self::LIST.'?'.http_build_query($query))->assertOk();
    }

    private function bulk(array $payload)
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson(self::BULK, $payload);
    }

    private function item($model, string $kind): array
    {
        return ['kind' => $kind, 'id' => $model->id];
    }

    public function test_the_queue_merges_all_three_types_with_counts(): void
    {
        $this->room('Family Room');
        $this->dish('Laing');
        $this->product('Pili Tart');
        $this->dish('Old Dish', 'rejected');
        $this->propose($this->dish('Sinigang', 'approved'), ['price' => 150]);

        $response = $this->list();

        $this->assertEqualsCanonicalizing(['Family Room', 'Laing', 'Pili Tart'], array_column($response->json('data'), 'name'));
        $this->assertSame(['new' => 3, 'changes' => 1, 'rejected' => 1, 'total' => 5], $response->json('counts'));
        $this->assertSame('Sea Grill', collect($response->json('data'))->firstWhere('name', 'Laing')['business_name']);
    }

    public function test_tabs_search_type_and_business_filters_work_together(): void
    {
        $this->dish('Laing');
        $this->dish('Pinangat');
        $this->product('Laing Jar');
        $this->room('Laing Suite');

        $this->assertEqualsCanonicalizing(['Laing', 'Laing Jar', 'Laing Suite'], array_column($this->list(['q' => 'laing'])->json('data'), 'name'));
        $this->assertSame(['Laing Jar'], array_column($this->list(['q' => 'laing', 'kind' => 'products'])->json('data'), 'name'));
        $this->assertCount(2, $this->list(['q' => 'sea grill'])->json('data'));
        $this->assertCount(2, $this->list(['business' => "menu:{$this->restaurant->id}"])->json('data'));
        $this->assertSame(['Laing'], array_column($this->list(['business' => "menu:{$this->restaurant->id}", 'q' => 'laing'])->json('data'), 'name'));
        $this->assertCount(0, $this->list(['tab' => 'rejected'])->json('data'));
    }

    public function test_sorting_dates_and_pagination(): void
    {
        foreach (['First', 'Second', 'Third'] as $index => $name) {
            $dish = $this->dish($name);
            DB::table('menu_items')->where('id', $dish->id)->update(['created_at' => now()->subDays(10 - $index)]);
        }

        $this->assertSame(['Third', 'Second', 'First'], array_column($this->list()->json('data'), 'name'));
        $this->assertSame(['First', 'Second', 'Third'], array_column($this->list(['sort' => 'oldest'])->json('data'), 'name'));

        $from = now()->subDays(9)->setTimezone('Asia/Manila')->toDateString();
        $to = now()->subDays(9)->setTimezone('Asia/Manila')->toDateString();
        $this->assertSame(['Second'], array_column($this->list(['from' => $from, 'to' => $to])->json('data'), 'name'));

        foreach (range(1, 11) as $n) {
            $this->dish("Dish {$n}");
        }
        $page = $this->list(['per_page' => 10, 'page' => 2]);
        $this->assertCount(4, $page->json('data'));
        $this->assertSame(14, $page->json('total'));
        $this->assertSame(2, $page->json('last_page'));
    }

    public function test_the_changes_tab_filters_by_date_and_carries_the_hash(): void
    {
        $this->propose($this->dish('Sinigang', 'approved'), ['price' => 150]);
        $today = now()->setTimezone('Asia/Manila')->toDateString();

        $row = $this->list(['tab' => 'changes', 'from' => $today, 'to' => $today])->json('data.0');

        $this->assertSame('Sinigang', $row['name']);
        $this->assertNotNull($row['row']['pending_changes_hash']);
        $this->assertEquals(150, $row['row']['pending_changes']['values']['price']);
        $this->assertCount(0, $this->list(['tab' => 'changes', 'to' => now()->subYear()->toDateString()])->json('data'));
    }

    public function test_bulk_approval_covers_every_type_and_never_publishes_the_listing(): void
    {
        $room = $this->room('Family Room');
        $dish = $this->dish('Laing');
        $product = $this->product('Pili Tart');

        $this->bulk(['action' => 'approve', 'scope' => 'new', 'items' => [$this->item($room, 'rooms'), $this->item($dish, 'menu'), $this->item($product, 'products')]])
            ->assertOk()->assertJson(['succeeded' => 3, 'failed' => 0]);

        foreach ([$room, $dish, $product] as $item) {
            $this->assertSame('approved', $item->fresh()->status);
            $this->assertSame($this->admin->id, $item->fresh()->approved_by);
        }
        foreach ([$this->stay, $this->restaurant, $this->shop] as $listing) {
            $this->assertSame('pending', $listing->fresh()->status);
        }
        $this->assertSame(1, ActivityLog::where('action', 'bulk_approve_items')->count());
    }

    public function test_bulk_rejection_requires_a_reason_and_records_it(): void
    {
        $dish = $this->dish('Laing');

        $this->bulk(['action' => 'reject', 'scope' => 'new', 'items' => [$this->item($dish, 'menu')]])->assertUnprocessable();
        $this->assertSame('pending', $dish->fresh()->status);

        $this->bulk(['action' => 'reject', 'scope' => 'new', 'notes' => 'Blurry description.', 'items' => [$this->item($dish, 'menu')]])
            ->assertOk()->assertJson(['succeeded' => 1, 'failed' => 0]);

        $this->assertSame('rejected', $dish->fresh()->status);
        $this->assertSame('Blurry description.', $dish->fresh()->admin_notes);
        $row = $this->list(['tab' => 'rejected'])->json('data.0');
        $this->assertSame('Blurry description.', $row['row']['admin_notes']);
    }

    public function test_already_processed_or_missing_items_fail_without_blocking_the_others(): void
    {
        $good = $this->dish('Laing');
        $done = $this->dish('Sinigang', 'approved');

        $response = $this->bulk(['action' => 'approve', 'scope' => 'new', 'items' => [$this->item($good, 'menu'), $this->item($done, 'menu'), ['kind' => 'menu', 'id' => 99999]]])
            ->assertOk()->assertJson(['succeeded' => 1, 'failed' => 2]);

        $this->assertSame('approved', $good->fresh()->status);
        $this->assertSame('Already processed or no longer available.', collect($response->json('results'))->firstWhere('id', $done->id)['message']);
        $approvedAt = $done->fresh()->approved_at;
        $this->assertNull($approvedAt, 'An already approved item is not approved a second time.');
    }

    public function test_the_same_item_twice_in_one_request_is_processed_once(): void
    {
        $dish = $this->dish('Laing');

        $this->bulk(['action' => 'approve', 'scope' => 'new', 'items' => [$this->item($dish, 'menu'), $this->item($dish, 'menu')]])
            ->assertOk()->assertJson(['succeeded' => 1, 'failed' => 0]);
    }

    public function test_proposed_changes_apply_only_the_reviewed_version(): void
    {
        $good = $this->dish('Laing', 'approved');
        $stale = $this->dish('Sinigang', 'approved');
        $this->propose($good, ['price' => 150]);
        $this->propose($stale, ['price' => 175]);
        $hashes = collect($this->list(['tab' => 'changes'])->json('data'))->mapWithKeys(fn ($row) => [$row['id'] => $row['row']['pending_changes_hash']]);

        $this->assertEquals(120, (float) $good->fresh()->price, 'The approved version stays until a change is approved.');

        $response = $this->bulk(['action' => 'approve', 'scope' => 'changes', 'items' => [
            $this->item($good, 'menu') + ['changes_hash' => $hashes[$good->id]],
            $this->item($stale, 'menu') + ['changes_hash' => 'outdated'],
        ]])->assertOk()->assertJson(['succeeded' => 1, 'failed' => 1]);

        $this->assertEquals(150, (float) $good->fresh()->price);
        $this->assertNull($good->fresh()->pending_changes);
        $this->assertEquals(120, (float) $stale->fresh()->price);
        $this->assertSame('pending', $stale->fresh()->pending_changes['status']);
        $this->assertStringContainsString('updated these changes', collect($response->json('results'))->firstWhere('id', $stale->id)['message']);
    }

    public function test_proposed_changes_can_be_rejected_and_the_approved_version_stays(): void
    {
        $dish = $this->dish('Laing', 'approved');
        $this->propose($dish, ['price' => 150]);

        $this->bulk(['action' => 'reject', 'scope' => 'changes', 'notes' => 'no', 'items' => [$this->item($dish, 'menu')]])->assertUnprocessable();
        $this->bulk(['action' => 'reject', 'scope' => 'changes', 'notes' => 'Price looks wrong.', 'items' => [$this->item($dish, 'menu')]])
            ->assertOk()->assertJson(['succeeded' => 1]);

        $this->assertEquals(120, (float) $dish->fresh()->price);
        $this->assertSame('approved', $dish->fresh()->status);
        $this->assertSame('rejected', $dish->fresh()->pending_changes['status']);
        $this->assertSame('Price looks wrong.', $dish->fresh()->pending_changes['notes']);
    }

    public function test_rejected_items_are_kept_and_a_new_item_scope_cannot_touch_an_approved_change(): void
    {
        $rejected = $this->dish('Old Dish', 'rejected');
        $approved = $this->dish('Laing', 'approved');
        $this->propose($approved, ['price' => 150]);

        $this->bulk(['action' => 'approve', 'scope' => 'new', 'items' => [$this->item($rejected, 'menu'), $this->item($approved, 'menu')]])
            ->assertOk()->assertJson(['succeeded' => 0, 'failed' => 2]);

        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertEquals(120, (float) $approved->fresh()->price);
        $this->assertSame(0, ActivityLog::where('action', 'bulk_approve_items')->count());
    }

    public function test_only_admins_can_reach_the_queue_and_requests_are_validated(): void
    {
        $dish = $this->dish('Laing');

        $this->actingAs($this->owner, 'sanctum')->getJson(self::LIST)->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')->postJson(self::BULK, ['action' => 'approve', 'scope' => 'new', 'items' => [$this->item($dish, 'menu')]])->assertForbidden();
        $this->assertSame('pending', $dish->fresh()->status);

        $this->bulk(['action' => 'publish', 'scope' => 'new', 'items' => [$this->item($dish, 'menu')]])->assertUnprocessable();
        $this->bulk(['action' => 'approve', 'scope' => 'rejected', 'items' => [$this->item($dish, 'menu')]])->assertUnprocessable();
        $this->bulk(['action' => 'approve', 'scope' => 'new', 'items' => [['kind' => 'destinations', 'id' => 1]]])->assertUnprocessable();
        $this->bulk(['action' => 'approve', 'scope' => 'new', 'items' => array_fill(0, 201, $this->item($dish, 'menu'))])->assertUnprocessable();
        $this->actingAs($this->admin, 'sanctum')->getJson(self::LIST.'?business=users:1')->assertUnprocessable();
    }

    public function test_owner_submissions_still_arrive_in_the_queue(): void
    {
        $this->owner->forceFill(['business_type' => 'food_beverage'])->save();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/business/food-beverage/menu-items', ['name' => 'Bicol Express', 'description' => 'Pork in coconut milk.', 'price' => 180])
            ->assertCreated();

        $this->assertContains('Bicol Express', array_column($this->list()->json('data'), 'name'));
    }

    public function test_the_existing_per_type_endpoints_still_work(): void
    {
        $dish = $this->dish('Laing');

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/menu-items?status=pending')->assertOk()->assertJsonPath('data.0.name', 'Laing');
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/menu-items/{$dish->id}/approve")->assertOk();

        $this->assertSame('approved', $dish->fresh()->status);
    }
}

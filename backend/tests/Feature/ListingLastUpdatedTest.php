<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\MenuItem;
use App\Models\Rating;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Last Updated" on the public pages of destinations, accommodations, restaurants and shops: the
 * date is recorded only when the information visitors see is published or changes (approval of a
 * new listing or of an owner's edit, a minor correction, an admin's edit, an approved room, menu
 * item, product or fee). Pending edits, page views, reviews and re-saves leave it alone, and a
 * listing published before this existed has no date until its first real update.
 */
class ListingLastUpdatedTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'A beautiful family place near the plaza, open daily from 8 AM with 12 rooms and a garden.';

    private int $town;

    private User $admin;

    private User $tourist;

    private int $ownerRole;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('public');
        $this->admin = User::create(['role_id' => Role::create(['name' => 'admin', 'description' => 'Admin'])->id,
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x'), 'status' => 'active']);
        $this->ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Business Owner'])->id;
        $this->tourist = User::create(['role_id' => Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id,
            'name' => 'Ana', 'email' => 'ana@example.com', 'password' => bcrypt('x'), 'status' => 'active']);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Gubat', 'created_at' => now(), 'updated_at' => now()]);
        $this->travelTo(Carbon::parse('2026-11-01 09:00:00'));
    }

    /** Per business type: model, owner route, public route, image table and key, extra fields. */
    private function types(): array
    {
        return [
            'accommodation' => [Accommodation::class, 'accommodation', 'accommodations', 'accommodation_images', 'accommodation_id', ['type' => 'Inn', 'price_range' => 'moderate']],
            'food_beverage' => [FoodBeverage::class, 'food-beverage', 'food-beverages', 'food_beverage_images', 'food_beverage_id', ['price_range' => 'moderate']],
            'retail_product' => [RetailShop::class, 'retail-shop', 'retail-shops', 'retail_shop_images', 'retail_shop_id', []],
        ];
    }

    /** A published listing from before "Last Updated" existed: no date recorded. */
    private function published(string $type): array
    {
        [$class, , , $imageTable, $fk, $extra] = $this->types()[$type];
        $owner = User::create(['role_id' => $this->ownerRole, 'name' => "Owner {$type}", 'email' => "{$type}@example.com",
            'password' => bcrypt('x'), 'status' => 'active', 'business_type' => $type]);
        $listing = $class::create(['user_id' => $owner->id, 'name' => 'Seaside Place', 'description' => self::DESCRIPTION,
            'municipality_id' => $this->town, 'address' => 'Rizal St., Gubat', 'latitude' => 12.92, 'longitude' => 124.12, 'status' => 'approved'] + $extra);
        $listing->forceFill(['publication_review_status' => 'published', 'approved_at' => now()])->save();
        foreach (range(0, 4) as $i) {
            DB::table($imageTable)->insert([$fk => $listing->id, 'image_path' => "seed/{$type}-{$i}.webp", 'thumbnail_path' => "seed/{$type}-{$i}-t.webp",
                'is_primary' => $i === 0, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table($listing->getTable())->where('id', $listing->id)->update(['info_updated_at' => null]);

        return [$owner, $listing->fresh()];
    }

    private function publicDate(string $type, Model $listing): ?string
    {
        return $this->getJson("/api/v1/{$this->types()[$type][2]}/{$listing->id}")->assertOk()->json('info_updated_at');
    }

    private function later(int $days = 1): string
    {
        $this->travel($days)->days();

        return now()->toDateString();
    }

    public function test_publishing_a_new_listing_records_the_date_and_pending_ones_have_none(): void
    {
        foreach ($this->types() as $type => [$class, , , , , $extra]) {
            $listing = $class::create(['user_id' => $this->admin->id, 'name' => "New {$type}", 'description' => self::DESCRIPTION,
                'municipality_id' => $this->town, 'address' => 'Gubat', 'latitude' => 12.9, 'longitude' => 124.1, 'status' => 'pending'] + $extra);
            $this->assertNull($listing->fresh()->info_updated_at, $type);

            $day = $this->later();
            $listing->update(['status' => 'approved']);
            $this->assertStringStartsWith($day, $this->publicDate($type, $listing), $type);
        }
    }

    public function test_an_owners_edit_counts_only_once_the_admin_approves_it(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, $listing] = $this->published($type);
            $route = $this->types()[$type][1];

            $this->actingAs($owner, 'sanctum')->putJson("/api/v1/business/{$route}", ['description' => str_replace('12 rooms', '14 rooms', self::DESCRIPTION)])->assertOk();
            $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$route}/submit-for-review")->assertOk();
            $this->assertNull($this->publicDate($type, $listing), "{$type}: a pending edit is not public");

            $day = $this->later(3);
            $approve = $type === 'retail_product'
                ? $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/retail-shops/{$listing->id}/approve-revision")
                : $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/{$this->types()[$type][2]}/{$listing->id}", ['revision_action' => 'approve']);
            $approve->assertOk();
            $this->assertStringStartsWith($day, $this->publicDate($type, $listing), $type);
        }
    }

    public function test_minor_corrections_count_when_they_go_live(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, $listing] = $this->published($type);
            $listing->forceFill(['description' => str_replace('beautiful', 'beatiful', self::DESCRIPTION)])->saveQuietly();

            $day = $this->later();
            $this->actingAs($owner, 'sanctum')->putJson("/api/v1/business/{$this->types()[$type][1]}", ['description' => self::DESCRIPTION, 'minor_edit' => true])->assertOk();
            $this->assertSame(self::DESCRIPTION, $listing->fresh()->description, $type);
            $this->assertStringStartsWith($day, $this->publicDate($type, $listing), $type);
        }
    }

    public function test_views_reviews_photos_and_re_saving_the_same_values_change_nothing(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [, $listing] = $this->published($type);

            $this->publicDate($type, $listing);
            Rating::create(['user_id' => $this->tourist->id, 'rateable_type' => $listing::class, 'rateable_id' => $listing->id, 'score' => 5, 'comment' => 'Lovely place, friendly staff.']);
            $listing->fresh()->update(['latitude' => '12.9200000', 'description' => '  '.self::DESCRIPTION.' ', 'view_count' => 99]);
            $listing->images()->first()->update(['is_primary' => false]);

            $this->assertNull($this->publicDate($type, $listing), $type);
        }
    }

    public function test_rooms_menu_items_and_products_count_when_their_published_details_change(): void
    {
        $items = [
            'accommodation' => fn ($l, $o) => AccommodationRoom::create(['accommodation_id' => $l->id, 'room_number' => 'Family Room', 'category' => 'Standard', 'price' => 1500, 'description' => 'A room for four.']),
            'food_beverage' => fn ($l, $o) => MenuItem::create(['food_beverage_id' => $l->id, 'name' => 'Laing', 'description' => 'A local dish.', 'price' => 120]),
            'retail_product' => fn ($l, $o) => RetailProduct::create(['retail_shop_id' => $l->id, 'user_id' => $o->id, 'name' => 'Pili Tart', 'description' => 'A local tart.', 'product_type' => 'Food', 'price' => 80, 'municipality_id' => $this->town]),
        ];
        $paths = ['accommodation' => ['rooms', 'accommodation-rooms'], 'food_beverage' => ['menu-items', 'menu-items'], 'retail_product' => ['products', 'retail-products']];

        foreach (array_keys($this->types()) as $type) {
            [$owner, $listing] = $this->published($type);
            [$ownerPath, $adminPath] = $paths[$type];

            // A new item waiting for review is not public.
            $item = $items[$type]($listing, $owner);
            $this->assertNull($this->publicDate($type, $listing), "{$type}: pending item");

            $day = $this->later();
            $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/{$adminPath}/{$item->id}/approve")->assertOk();
            $this->assertStringStartsWith($day, $this->publicDate($type, $listing), "{$type}: item approved");

            // The owner's new price waits for review; approving it moves the date.
            $this->later();
            $before = $this->publicDate($type, $listing);
            $this->actingAs($owner, 'sanctum')->putJson("/api/v1/business/{$this->types()[$type][1]}/{$ownerPath}/{$item->id}", ['price' => 999])->assertOk();
            $this->assertSame($before, $this->publicDate($type, $listing), "{$type}: pending price");

            $hash = collect($this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/admin/{$adminPath}?changes=pending")->assertOk()->json('data'))
                ->firstWhere('id', $item->id)['pending_changes_hash'];
            $day = $this->later();
            $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/{$adminPath}/{$item->id}/approve-changes", ['changes_hash' => $hash])->assertOk();
            $this->assertStringStartsWith($day, $this->publicDate($type, $listing), "{$type}: price approved");
        }
    }

    public function test_destinations_count_admin_changes_to_what_visitors_see_including_fees(): void
    {
        $category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $beach = Destination::create(['name' => 'Otavi Beach', 'description' => 'A quiet beach.', 'municipality_id' => $this->town, 'category_id' => $category,
            'address' => 'Gubat', 'latitude' => 12.9, 'longitude' => 124.1, 'created_by' => $this->admin->id, 'status' => 'draft']);
        $this->assertNull($beach->fresh()->info_updated_at, 'A draft is not public.');

        $save = fn (array $fields) => $this->actingAs($this->admin)->post("/api/v1/admin/destinations/{$beach->id}", $fields + ['_method' => 'PUT'], ['Accept' => 'application/json'])->assertOk();
        $date = fn () => $this->getJson("/api/v1/destinations/{$beach->id}")->assertOk()->json('destination.info_updated_at');

        $day = $this->later();
        $save(['status' => 'published']);
        $this->assertStringStartsWith($day, $date());

        $day = $this->later();
        $save(['fees' => [['label' => 'Adult', 'amount' => 20]]]);
        $this->assertStringStartsWith($day, $date(), 'New fee.');

        // Saving the same fees and details again is not an update.
        $this->later();
        $save(['fees' => [['label' => 'Adult', 'amount' => '20.00']], 'description' => 'A quiet beach.']);
        $this->assertStringStartsWith($day, $date());

        $day = $this->later();
        $save(['operating_hours' => '6:00 AM - 6:00 PM']);
        $this->assertStringStartsWith($day, $date(), 'New hours.');
    }
}

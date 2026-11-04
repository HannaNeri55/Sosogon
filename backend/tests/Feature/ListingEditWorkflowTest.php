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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Edits to PUBLISHED listings and their rooms/menu items/products:
 * minor corrections publish directly, everything else waits for review while
 * the approved version stays public; approval applies, rejection preserves.
 */
class ListingEditWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'A beautiful family place near the plaza, open daily from 8 AM with 12 rooms and a garden.';

    private int $town;

    private User $admin;

    private int $ownerRole;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('public');
        $this->admin = User::create(['role_id' => Role::create(['name' => 'admin', 'description' => 'Admin'])->id,
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x'), 'status' => 'active']);
        $this->ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Business Owner'])->id;
        Role::create(['name' => 'tourist', 'description' => 'Tourist']);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Gubat', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Per business type: model, owner route prefix, public route, admin approve/reject. */
    private function types(): array
    {
        return [
            'accommodation' => [Accommodation::class, 'accommodation', 'accommodations', 'accommodation_images', 'accommodation_id', ['type' => 'Inn', 'price_range' => 'moderate']],
            'food_beverage' => [FoodBeverage::class, 'food-beverage', 'food-beverages', 'food_beverage_images', 'food_beverage_id', ['price_range' => 'moderate']],
            'retail_product' => [RetailShop::class, 'retail-shop', 'retail-shops', 'retail_shop_images', 'retail_shop_id', []],
        ];
    }

    /** An active owner with a published listing that has a 5-photo gallery. */
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

        return [$owner, $listing];
    }

    private function edit(string $type, User $owner, array $data)
    {
        return $this->actingAs($owner, 'sanctum')->putJson("/api/v1/business/{$this->types()[$type][1]}", $data);
    }

    private function publicDescription(string $type, Model $listing): ?string
    {
        return $this->getJson("/api/v1/{$this->types()[$type][2]}/{$listing->id}")->assertOk()->json('description');
    }

    private function approve(string $type, Model $listing)
    {
        return $type === 'retail_product'
            ? $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/retail-shops/{$listing->id}/approve-revision")
            : $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/{$this->types()[$type][2]}/{$listing->id}", ['revision_action' => 'approve']);
    }

    private function reject(string $type, Model $listing, string $reason)
    {
        return $type === 'retail_product'
            ? $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/retail-shops/{$listing->id}/request-revision-changes", ['notes' => $reason])
            : $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/{$this->types()[$type][2]}/{$listing->id}", ['revision_action' => 'needs_revision', 'admin_notes' => $reason]);
    }

    public function test_major_edits_wait_for_review_while_the_published_version_stays_public(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, $listing] = $this->published($type);
            $changed = str_replace('12 rooms', '20 rooms', self::DESCRIPTION);

            $this->edit($type, $owner, ['description' => $changed, 'minor_edit' => true])->assertOk();

            $this->assertSame('approved', $listing->fresh()->status, $type);
            $this->assertSame(self::DESCRIPTION, $listing->fresh()->description, $type);
            $this->assertSame(self::DESCRIPTION, $this->publicDescription($type, $listing), $type);
            $revision = $listing->revisions()->latest('id')->firstOrFail();
            $this->assertSame($changed, $revision->proposed_data['description'], $type);
            $this->assertNotNull($revision->base_snapshot, $type);
        }
    }

    public function test_declared_spelling_and_formatting_fixes_publish_directly_and_are_logged(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, $listing] = $this->published($type);
            $listing->forceFill(['description' => str_replace('beautiful', 'beatiful', self::DESCRIPTION)])->save();

            $this->edit($type, $owner, ['description' => self::DESCRIPTION, 'name' => 'SEASIDE PLACE', 'minor_edit' => true])->assertOk();

            $this->assertSame(self::DESCRIPTION, $this->publicDescription($type, $listing), $type);
            $this->assertSame('SEASIDE PLACE', $listing->fresh()->name, $type);
            $this->assertSame(0, $listing->revisions()->count(), $type);
            $log = ActivityLog::where('action', 'minor_listing_edit')->where('model_id', $listing->id)->where('model_type', $listing::class)->firstOrFail();
            $this->assertStringContainsString('beatiful', $log->description, $type);
        }
    }

    public function test_ambiguous_or_undeclared_corrections_go_to_review(): void
    {
        [$owner, $listing] = $this->published('accommodation');
        $cases = [
            'not declared' => [str_replace('beautiful', 'beutiful', self::DESCRIPTION), false],
            'number changed' => [str_replace('8 AM', '9 AM', self::DESCRIPTION), true],
            'meaning word' => [str_replace('open daily', 'open weekends', self::DESCRIPTION), true],
            'word added' => [str_replace('a garden', 'a big garden', self::DESCRIPTION), true],
            'renamed' => [null, true],
        ];

        foreach ($cases as $label => [$description, $declared]) {
            $listing->revisions()->delete();
            $data = $description ? ['description' => $description] : ['name' => 'Seaview Place'];
            $this->edit('accommodation', $owner, $data + ['minor_edit' => $declared])->assertOk();

            $this->assertSame(self::DESCRIPTION, $listing->fresh()->description, $label);
            $this->assertSame('Seaside Place', $listing->fresh()->name, $label);
            $this->assertSame(1, $listing->revisions()->count(), $label);
        }
    }

    public function test_approval_applies_the_revision_and_rejection_keeps_the_published_version(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, $listing] = $this->published($type);
            $route = $this->types()[$type][1];
            $first = str_replace('12 rooms', '14 rooms', self::DESCRIPTION);

            $this->edit($type, $owner, ['description' => $first])->assertOk();
            $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$route}/submit-for-review")->assertOk();
            $this->approve($type, $listing)->assertOk();
            $this->assertSame($first, $this->publicDescription($type, $listing), $type);

            $second = str_replace('a garden', 'a rooftop pool', $first);
            $this->edit($type, $owner, ['description' => $second])->assertOk();
            $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$route}/submit-for-review")->assertOk();
            $this->reject($type, $listing, '')->assertUnprocessable();
            $this->reject($type, $listing, 'Please send a photo of the pool first.')->assertOk();

            $this->assertSame($first, $this->publicDescription($type, $listing), $type);
            $revision = $listing->revisions()->latest('id')->firstOrFail();
            $this->assertSame('needs_revision', $revision->status, $type);
            $this->assertSame('Please send a photo of the pool first.', $revision->admin_notes, $type);
            // The owner can correct and resubmit.
            $this->edit($type, $owner, ['description' => str_replace('a rooftop pool', 'a pool', $second)])->assertOk();
            $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$route}/submit-for-review")->assertOk();
        }
    }

    public function test_approval_never_overwrites_newer_published_values(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, $listing] = $this->published($type);
            $route = $this->types()[$type][1];

            // The owner renames; meanwhile the live description changes. Not a conflict: both are kept.
            $this->edit($type, $owner, ['name' => 'Seaside Place Gubat'])->assertOk();
            $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$route}/submit-for-review")->assertOk();
            $newer = str_replace('12 rooms', '15 rooms', self::DESCRIPTION);
            $listing->forceFill(['description' => $newer])->save();
            $this->approve($type, $listing)->assertOk();
            $this->assertSame('Seaside Place Gubat', $listing->fresh()->name, $type);
            $this->assertSame($newer, $listing->fresh()->description, $type);

            // Both sides changed the description: refused, live value kept, returned to the owner.
            $this->edit($type, $owner, ['description' => str_replace('15 rooms', '18 rooms', $newer)])->assertOk();
            $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$route}/submit-for-review")->assertOk();
            $latest = str_replace('15 rooms', '16 rooms', $newer);
            $listing->forceFill(['description' => $latest])->save();
            $this->approve($type, $listing)->assertStatus(409)->assertJsonPath('conflicts', ['description']);
            $this->assertSame($latest, $listing->fresh()->description, $type);
            $revision = $listing->revisions()->latest('id')->firstOrFail();
            $this->assertSame('needs_revision', $revision->status, $type);
            $this->assertStringContainsString('description', $revision->admin_notes, $type);
        }
    }

    public function test_owners_cannot_change_protected_fields_or_approve_their_own_changes(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, $listing] = $this->published($type);
            $listing->forceFill(['status' => 'pending', 'publication_review_status' => 'draft'])->save();

            $this->edit($type, $owner, ['status' => 'approved', 'publication_review_status' => 'published', 'is_sample' => true,
                'approved_at' => now()->toDateTimeString(), 'user_id' => $this->admin->id, 'name' => 'Draft Name'])->assertOk();

            $fresh = $listing->fresh();
            $this->assertSame(['pending', 'draft', false, $owner->id], [$fresh->status, $fresh->publication_review_status, $fresh->is_sample, $fresh->user_id], $type);
            $this->getJson("/api/v1/{$this->types()[$type][2]}/{$listing->id}")->assertNotFound();
        }
    }

    public function test_owner_api_calls_cannot_reach_admin_review_endpoints(): void
    {
        [$owner, $listing] = $this->published('accommodation');
        $this->edit('accommodation', $owner, ['description' => str_replace('12', '30', self::DESCRIPTION)])->assertOk();
        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/business/accommodation/submit-for-review')->assertOk();

        $this->actingAs($owner, 'sanctum')->putJson("/api/v1/admin/accommodations/{$listing->id}", ['revision_action' => 'approve'])->assertForbidden();
        $this->assertSame(self::DESCRIPTION, $listing->fresh()->description);
        $this->assertSame('submitted', $listing->revisions()->latest('id')->value('status'));
    }

    /** Rooms, menu items and products: Option 5b. */
    private function approvedItem(string $type): array
    {
        [$owner, $listing] = $this->published($type);
        $item = match ($type) {
            'accommodation' => AccommodationRoom::create(['accommodation_id' => $listing->id, 'room_number' => 'Family Room', 'category' => 'Standard', 'price' => 1500, 'description' => 'A beautiful room for four guests.']),
            'food_beverage' => MenuItem::create(['food_beverage_id' => $listing->id, 'name' => 'Laing', 'description' => 'A beautiful local dish.', 'price' => 120]),
            'retail_product' => RetailProduct::create(['retail_shop_id' => $listing->id, 'user_id' => $owner->id, 'name' => 'Pili Tart', 'description' => 'A beautiful local tart.', 'product_type' => 'Food', 'price' => 80, 'municipality_id' => $this->town]),
        };
        $item->forceFill(['status' => 'approved', 'approved_at' => now()])->save();
        $paths = ['accommodation' => ['rooms', 'accommodation-rooms', 'rooms'], 'food_beverage' => ['menu-items', 'menu-items', 'menu_items'], 'retail_product' => ['products', 'retail-products', 'products']][$type];

        return [$owner, $listing, $item, ...$paths];
    }

    public function test_item_changes_wait_for_review_and_the_approved_version_stays_public(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, $listing, $item, $ownerPath, $adminPath] = $this->approvedItem($type);

            $this->actingAs($owner, 'sanctum')->putJson("/api/v1/business/{$this->types()[$type][1]}/{$ownerPath}/{$item->id}", ['price' => 999])->assertOk();

            $this->assertEquals(['accommodation' => 1500, 'food_beverage' => 120, 'retail_product' => 80][$type], (float) $item->fresh()->price, $type);
            $this->assertSame('pending', $item->fresh()->pending_changes['status'], $type);
            $public = json_encode($this->getJson("/api/v1/{$this->types()[$type][2]}/{$listing->id}")->assertOk()->json());
            $this->assertStringNotContainsString('pending_changes', $public, $type);
            $this->assertStringNotContainsString('999', $public, $type);

            // The admin sees it in the changes queue with a hash, and approval must echo that exact version.
            $row = collect($this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/admin/{$adminPath}?changes=pending")->assertOk()->json('data'))->firstWhere('id', $item->id);
            $this->assertNotNull($row, $type);
            // The queue row carries the parent listing's status and municipality for the reviewer.
            $parent = ['accommodation' => $row['accommodation'] ?? null, 'food_beverage' => $row['food_beverage'] ?? null, 'retail_product' => $row['shop'] ?? null][$type];
            $this->assertSame('approved', $parent['status'] ?? null, $type);
            $this->assertSame('Gubat', $type === 'retail_product' ? ($row['municipality']['name'] ?? null) : ($parent['municipality']['name'] ?? null), $type);
            $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/{$adminPath}/{$item->id}/approve-changes", ['changes_hash' => 'stale'])->assertStatus(409);
            $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/{$adminPath}/{$item->id}/approve-changes", ['changes_hash' => $row['pending_changes_hash']])->assertOk();
            $this->assertEquals(999, (float) $item->fresh()->price, $type);
            $this->assertNull($item->fresh()->pending_changes, $type);
        }
    }

    public function test_item_rejection_keeps_the_approved_version_and_records_a_reason(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, , $item, $ownerPath, $adminPath] = $this->approvedItem($type);
            $before = (float) $item->price;

            $this->actingAs($owner, 'sanctum')->putJson("/api/v1/business/{$this->types()[$type][1]}/{$ownerPath}/{$item->id}", ['price' => 5])->assertOk();
            $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/{$adminPath}/{$item->id}/reject-changes", [])->assertUnprocessable();
            $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/{$adminPath}/{$item->id}/reject-changes", ['notes' => 'That price looks like a typo.'])->assertOk();

            $fresh = $item->fresh();
            $this->assertEquals($before, (float) $fresh->price, $type);
            $this->assertSame('approved', $fresh->status, $type);
            $this->assertSame(['rejected', 'That price looks like a typo.'], [$fresh->pending_changes['status'], $fresh->pending_changes['notes']], $type);
            // Correcting resubmits for review.
            $this->actingAs($owner, 'sanctum')->putJson("/api/v1/business/{$this->types()[$type][1]}/{$ownerPath}/{$item->id}", ['price' => 50])->assertOk();
            $this->assertSame('pending', $item->fresh()->pending_changes['status'], $type);
        }
    }

    public function test_item_minor_corrections_publish_directly_and_new_items_stay_pending(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, $listing, $item, $ownerPath] = $this->approvedItem($type);
            $item->forceFill(['description' => str_replace('beautiful', 'beatiful', $item->description)])->save();
            $fixed = str_replace('beatiful', 'beautiful', $item->description);

            $this->actingAs($owner, 'sanctum')->putJson("/api/v1/business/{$this->types()[$type][1]}/{$ownerPath}/{$item->id}", ['description' => $fixed, 'minor_edit' => true])->assertOk();
            $this->assertSame($fixed, $item->fresh()->description, $type);
            $this->assertNull($item->fresh()->pending_changes, $type);

            // A new item on a published listing is never live until reviewed.
            $new = ['accommodation' => ['room_number' => 'New Room', 'category' => 'Standard'],
                'food_beverage' => ['name' => 'New Dish', 'price' => 99],
                'retail_product' => ['name' => 'New Tart', 'description' => 'A new local tart.', 'product_type' => 'Food', 'price' => 50]][$type];
            $created = $this->actingAs($owner, 'sanctum')->postJson("/api/v1/business/{$this->types()[$type][1]}/{$ownerPath}", $new)->assertCreated()->json();
            $this->assertSame('pending', collect($created)->first(fn ($v) => is_array($v) && isset($v['status']))['status'], $type);
            $this->assertStringNotContainsString('New ', json_encode($this->getJson("/api/v1/{$this->types()[$type][2]}/{$listing->id}")->json()), $type);
        }
    }

    /** What the owner's management page shows: approved values, the proposal, and the rejection reason. */
    public function test_owner_item_list_shows_proposed_and_rejected_changes(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, , $item, $ownerPath, $adminPath] = $this->approvedItem($type);
            $base = "/api/v1/business/{$this->types()[$type][1]}/{$ownerPath}";

            $this->actingAs($owner, 'sanctum')->putJson("{$base}/{$item->id}", ['price' => 777])->assertOk();
            $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/{$adminPath}/{$item->id}/reject-changes", ['notes' => 'Please confirm the new price.'])->assertOk();

            $row = collect($this->actingAs($owner, 'sanctum')->getJson($base)->assertOk()->json())->firstWhere('id', $item->id);
            $this->assertEquals((float) $item->price, (float) $row['price'], $type);
            $this->assertEquals(777, (float) $row['pending_changes']['values']['price'], $type);
            $this->assertSame(['rejected', 'Please confirm the new price.'], [$row['pending_changes']['status'], $row['pending_changes']['notes']], $type);
        }
    }

    /** What the admin comparison reads: the live listing + images, and the revision's proposal and snapshot. */
    public function test_admin_listing_detail_includes_the_revision_comparison_data(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, $listing] = $this->published($type);
            $changed = str_replace('12 rooms', '20 rooms', self::DESCRIPTION);
            $this->edit($type, $owner, ['description' => $changed])->assertOk();

            $detail = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/admin/{$this->types()[$type][2]}/{$listing->id}")->assertOk()->json();
            $detail = $detail['data'] ?? $detail;
            $revision = $detail['revisions'][0];

            $this->assertSame(self::DESCRIPTION, $detail['description'], $type);
            $this->assertCount(5, $detail['images'], $type);
            $this->assertSame($changed, $revision['proposed_data']['description'], $type);
            $this->assertSame(self::DESCRIPTION, $revision['base_snapshot']['data']['description'], $type);
            $this->assertCount(5, $revision['proposed_gallery'], $type);
            $this->assertStringStartsWith('existing:', $revision['proposed_gallery'][0]['key'], $type);
        }
    }
}

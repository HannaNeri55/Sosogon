<?php

namespace Tests\Feature;

use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RetailShopProductTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private RetailShop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        // Product image uploads go to a throwaway disk, never the app's real storage.
        Storage::fake('public');

        $roleId = Role::create(['name' => 'business_owner', 'description' => 'Business Establishment Owner'])->id;

        $municipalityId = DB::table('municipalities')->insertGetId([
            'name' => 'Bulan', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $barangayId = DB::table('barangays')->insertGetId([
            'municipality_id' => $municipalityId, 'name' => 'Zone 1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->owner = User::create([
            'role_id' => $roleId,
            'name' => 'Shop Owner',
            'email' => 'shopowner@example.com',
            'password' => bcrypt('password'),
            'business_type' => 'retail_product',
        ]);

        $this->shop = RetailShop::create([
            'user_id' => $this->owner->id,
            'name' => 'Test Pasalubong Shop',
            'description' => 'A souvenir shop used for automated testing purposes only, selling local Sorsogon products.',
            'municipality_id' => $municipalityId,
            'barangay_id' => $barangayId,
            'address' => '123 Test St., Bulan, Sorsogon',
            'latitude' => 12.6744,
            'longitude' => 123.8756,
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    private function makeProduct(string $name): RetailProduct
    {
        return RetailProduct::create([
            'retail_shop_id' => $this->shop->id,
            'name' => $name,
            'description' => 'A test product description.',
            'product_type' => 'Souvenir',
            'price' => 100,
            'municipality_id' => $this->shop->municipality_id,
            'barangay_id' => $this->shop->barangay_id,
        ]);
    }

    public function test_owner_can_add_products_up_to_the_limit(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $response = $this->actingAs($this->owner)->postJson('/api/v1/business/retail-shop/products', [
                'name' => "Product {$i}",
                'description' => 'A test product description.',
                'product_type' => 'Souvenir',
                'price' => 50,
            ]);
            $response->assertCreated();
        }

        $this->assertSame(20, $this->shop->products()->count());
    }

    public function test_twenty_first_product_is_rejected_with_the_capacity_message(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->makeProduct("Product {$i}");
        }

        $response = $this->actingAs($this->owner)->postJson('/api/v1/business/retail-shop/products', [
            'name' => 'One Too Many',
            'description' => 'A test product description.',
            'product_type' => 'Souvenir',
            'price' => 50,
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Maximum capacity reached: You have registered the limit of 20 products. Delete an existing product to feature a new one.']);

        $this->assertSame(20, $this->shop->products()->count());
    }

    public function test_deleting_a_product_frees_a_slot(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->makeProduct("Product {$i}");
        }
        $toDelete = $this->shop->products()->first();

        $this->actingAs($this->owner)
            ->deleteJson("/api/v1/business/retail-shop/products/{$toDelete->id}")
            ->assertOk();

        $response = $this->actingAs($this->owner)->postJson('/api/v1/business/retail-shop/products', [
            'name' => 'New Product',
            'description' => 'A test product description.',
            'product_type' => 'Souvenir',
            'price' => 50,
        ]);

        $response->assertCreated();
        $this->assertSame(20, $this->shop->products()->count());
    }

    public function test_editing_an_approved_product_keeps_it_live_and_holds_the_change_for_review(): void
    {
        $product = $this->makeProduct('Approved Product');
        $product->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->owner->id]);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/business/retail-shop/products/{$product->id}", ['name' => 'Approved Product (Updated)'])
            ->assertOk();

        // Option 5b: the approved product stays public unchanged; the edit waits for review.
        $product->refresh();
        $this->assertSame('approved', $product->status);
        $this->assertNotNull($product->approved_at);
        $this->assertSame('Approved Product', $product->name);
        $this->assertSame('Approved Product (Updated)', $product->pending_changes['values']['name']);
        $this->assertSame('pending', $product->pending_changes['status']);
    }

    public function test_editing_a_product_under_an_untrusted_owner_resets_it_to_pending(): void
    {
        $this->shop->update(['status' => 'pending']);

        $product = $this->makeProduct('Approved Product');
        $product->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->owner->id]);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/business/retail-shop/products/{$product->id}", ['name' => 'Approved Product (Updated)'])
            ->assertOk();

        $product->refresh();
        $this->assertSame('pending', $product->status);
        $this->assertNull($product->approved_at);
    }

    public function test_new_products_stay_pending_even_when_the_shop_is_approved(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/v1/business/retail-shop/products', [
            'name' => 'Needs Review Product',
            'description' => 'A test product description.',
            'product_type' => 'Souvenir',
            'price' => 50,
        ]);

        // Option 5b: every new product is reviewed before tourists see it.
        $response->assertCreated();
        $this->assertSame('pending', $response->json('product.status'));
        $this->assertNull($response->json('product.approved_at'));
    }

    public function test_new_products_stay_pending_when_the_shop_is_not_yet_approved(): void
    {
        $this->shop->update(['status' => 'pending']);

        $response = $this->actingAs($this->owner)->postJson('/api/v1/business/retail-shop/products', [
            'name' => 'Needs Review Product',
            'description' => 'A test product description.',
            'product_type' => 'Souvenir',
            'price' => 50,
        ]);

        $response->assertCreated();
        $this->assertSame('pending', $response->json('product.status'));
        $this->assertNull($response->json('product.approved_at'));
    }

    public function test_editing_a_published_shop_keeps_it_published_and_holds_the_changes_for_review(): void
    {
        $original = $this->shop->description;
        $proposed = str_repeat('Updated shop description. ', 5);

        $this->actingAs($this->owner)
            ->putJson('/api/v1/business/retail-shop', ['description' => $proposed])
            ->assertOk();

        // The published shop is untouched and still public...
        $this->shop->refresh();
        $this->assertSame('approved', $this->shop->status);
        $this->assertNotNull($this->shop->approved_at);
        $this->assertSame($original, $this->shop->description);
        $this->assertSame($original, $this->getJson("/api/v1/retail-shops/{$this->shop->id}")->assertOk()->json('shop.description') ?? $this->getJson("/api/v1/retail-shops/{$this->shop->id}")->json('description'));

        // ...while the proposed text waits in a revision until an admin approves it.
        $revision = $this->shop->revisions()->latest('id')->firstOrFail();
        $this->assertSame('draft', $revision->status);
        $this->assertSame(trim($proposed), $revision->proposed_data['description']); // request input is trimmed
        $this->assertNull($revision->reviewed_at);
    }

    public function test_only_approved_products_are_visible_on_the_public_endpoint(): void
    {
        $this->makeProduct('Approved Product')->update(['status' => 'approved']);
        $this->makeProduct('Pending Product');

        $response = $this->getJson("/api/v1/retail-shops/{$this->shop->id}");

        $response->assertOk();
        $names = collect($response->json('products'))->pluck('name');
        $this->assertTrue($names->contains('Approved Product'));
        $this->assertFalse($names->contains('Pending Product'));
    }

    public function test_replacing_a_products_image_results_in_exactly_one_image(): void
    {
        $product = $this->makeProduct('Product With Image');

        $file1 = UploadedFile::fake()->image('first.jpg');
        $this->actingAs($this->owner)
            ->post("/api/v1/business/retail-shop/products/{$product->id}", ['_method' => 'PUT', 'image' => $file1])
            ->assertOk();

        $this->assertSame(1, $product->images()->count());
        $first = $product->images()->first();
        Storage::disk('public')->assertExists([$first->image_path, $first->thumbnail_path]);

        $file2 = UploadedFile::fake()->image('second.jpg');
        $this->actingAs($this->owner)
            ->post("/api/v1/business/retail-shop/products/{$product->id}", ['_method' => 'PUT', 'image' => $file2])
            ->assertOk();

        $this->assertSame(1, $product->images()->count());
        // The replaced image's files are removed; only the new image and its thumbnail remain.
        $second = $product->images()->first();
        Storage::disk('public')->assertMissing([$first->image_path, $first->thumbnail_path]);
        Storage::disk('public')->assertExists([$second->image_path, $second->thumbnail_path]);
        $this->assertCount(2, Storage::disk('public')->allFiles("retail-products/{$product->id}"));
    }
}

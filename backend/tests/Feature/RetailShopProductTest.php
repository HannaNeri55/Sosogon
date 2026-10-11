<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetailShopProductTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private RetailShop $shop;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_editing_a_product_under_a_trusted_owner_stays_live(): void
    {
        $product = $this->makeProduct('Approved Product');
        $product->update(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->owner->id]);

        $this->actingAs($this->owner)
            ->putJson("/api/v1/business/retail-shop/products/{$product->id}", ['name' => 'Approved Product (Updated)'])
            ->assertOk();

        $product->refresh();
        $this->assertSame('approved', $product->status);
        $this->assertNotNull($product->approved_at);
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

    public function test_new_products_are_auto_approved_when_the_shop_is_approved(): void
    {
        $response = $this->actingAs($this->owner)->postJson('/api/v1/business/retail-shop/products', [
            'name' => 'Instant Live Product',
            'description' => 'A test product description.',
            'product_type' => 'Souvenir',
            'price' => 50,
        ]);

        $response->assertCreated();
        $this->assertSame('approved', $response->json('product.status'));
        $this->assertNotNull($response->json('product.approved_at'));
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

    public function test_editing_an_approved_shop_resets_it_to_pending(): void
    {
        $this->actingAs($this->owner)
            ->putJson('/api/v1/business/retail-shop', ['description' => str_repeat('Updated shop description. ', 5)])
            ->assertOk();

        $this->shop->refresh();
        $this->assertSame('pending', $this->shop->status);
        $this->assertNull($this->shop->approved_at);
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

        $file1 = \Illuminate\Http\UploadedFile::fake()->image('first.jpg');
        $this->actingAs($this->owner)
            ->post("/api/v1/business/retail-shop/products/{$product->id}", ['_method' => 'PUT', 'image' => $file1])
            ->assertOk();

        $this->assertSame(1, $product->images()->count());

        $file2 = \Illuminate\Http\UploadedFile::fake()->image('second.jpg');
        $this->actingAs($this->owner)
            ->post("/api/v1/business/retail-shop/products/{$product->id}", ['_method' => 'PUT', 'image' => $file2])
            ->assertOk();

        $this->assertSame(1, $product->images()->count());
    }
}

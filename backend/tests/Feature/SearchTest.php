<?php

namespace Tests\Feature;

use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\SearchLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The site-wide search behind the navbar search box. */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    private int $town;

    protected function setUp(): void
    {
        parent::setUp();
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function fields(string $name, string $status = 'approved'): array
    {
        return [
            'name' => $name, 'description' => "{$name} used for automated search testing.",
            'municipality_id' => $this->town, 'address' => 'Test St.', 'latitude' => 12.67, 'longitude' => 123.87,
            'status' => $status,
        ];
    }

    public function test_a_search_is_logged_for_the_reports(): void
    {
        $this->getJson('/api/v1/search?q=pili')->assertOk();

        $this->assertSame(1, SearchLog::count());
        $this->assertSame('pili', SearchLog::first()->query);
    }

    public function test_products_carry_their_shop_so_results_can_link_to_it(): void
    {
        $shop = RetailShop::create($this->fields('Pasalubong Corner'));
        RetailProduct::create($this->fields('Pili Tart') + ['retail_shop_id' => $shop->id, 'product_type' => 'food', 'price' => 100]);

        $products = $this->getJson('/api/v1/search?q=Pili')->assertOk()->json('results.retail_products');

        $this->assertCount(1, $products);
        $this->assertSame($shop->id, $products[0]['retail_shop_id']);
    }

    public function test_listings_not_yet_approved_are_not_found(): void
    {
        RetailShop::create($this->fields('Approved Pili Shop'));
        RetailShop::create($this->fields('Pending Pili Shop', 'pending'));

        $names = array_column($this->getJson('/api/v1/search?q=Pili&type=retail_shop')->assertOk()->json('results.retail_shops'), 'name');

        $this->assertSame(['Approved Pili Shop'], $names);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Opening a listing's page counts a view, which the admin dashboard and reports read. */
class VisitorTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $town;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'role_id' => Role::create(['name' => 'admin', 'description' => 'Admin'])->id,
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
        ]);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Barcelona', 'created_at' => now(), 'updated_at' => now()]);
        $this->category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function destination(string $status = 'published'): Destination
    {
        return Destination::create([
            'name' => 'Test Spot', 'description' => 'Destination used for automated testing.',
            'category_id' => $this->category, 'municipality_id' => $this->town, 'address' => 'Test St.',
            'latitude' => 12.87, 'longitude' => 124.14, 'status' => $status, 'created_by' => $this->admin->id,
        ]);
    }

    public function test_each_view_of_a_listing_adds_to_todays_row_and_is_counted_once(): void
    {
        $destination = $this->destination();

        $this->getJson("/api/v1/destinations/{$destination->id}")->assertOk();
        $this->getJson("/api/v1/destinations/{$destination->id}")->assertOk();

        $rows = DB::table('visitor_statistics')->get();
        $this->assertCount(1, $rows, 'One row per listing per day.');
        $this->assertSame(Destination::class, $rows[0]->trackable_type);
        $this->assertSame(2, (int) $rows[0]->page_views);

        $this->assertSame(2, (int) $destination->fresh()->view_count, 'The daily row is in addition to, not instead of, view_count.');
    }

    public function test_other_listing_types_are_counted_too(): void
    {
        $food = FoodBeverage::create([
            'name' => 'Test Eatery', 'description' => 'Eatery used for automated testing.',
            'municipality_id' => $this->town, 'address' => 'Test St.', 'latitude' => 12.87, 'longitude' => 124.14,
            'status' => 'approved', 'price_range' => 'moderate',
        ]);

        $this->getJson("/api/v1/food-beverages/{$food->id}")->assertOk();

        $this->assertSame(1, (int) $food->fresh()->view_count);
        $this->assertDatabaseHas('visitor_statistics', ['trackable_type' => FoodBeverage::class, 'trackable_id' => $food->id, 'page_views' => 1]);
    }

    public function test_a_hidden_listing_records_no_view(): void
    {
        $draft = $this->destination('draft');

        $this->getJson("/api/v1/destinations/{$draft->id}")->assertNotFound();

        $this->assertDatabaseCount('visitor_statistics', 0);
        $this->assertSame(0, (int) $draft->fresh()->view_count);
    }

    public function test_the_visitors_report_shows_the_views(): void
    {
        $destination = $this->destination();
        $this->getJson("/api/v1/destinations/{$destination->id}")->assertOk();

        $report = $this->actingAs($this->admin)->getJson('/api/v1/admin/reports/visitors')->assertOk()->json();

        $this->assertCount(1, $report);
        $this->assertSame(1, (int) $report[0]['total_views']);
    }
}

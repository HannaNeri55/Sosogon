<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Page views of the public site: what counts, and the admin's visitor trend built from them. */
class PageViewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Destination $spot;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-12 18:30 UTC is 2:30 AM on 13 November in the Philippines.
        Carbon::setTestNow('2026-11-12 18:30:00');

        $this->admin = User::create(['role_id' => Role::create(['name' => 'admin', 'description' => 'Admin'])->id,
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password')]);
        $town = DB::table('municipalities')->insertGetId(['name' => 'Barcelona', 'created_at' => now(), 'updated_at' => now()]);
        $category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $make = fn (string $status) => Destination::create(['name' => "Spot {$status}", 'description' => 'For tests.', 'category_id' => $category,
            'municipality_id' => $town, 'address' => 'Test St.', 'latitude' => 12.87, 'longitude' => 124.14, 'status' => $status, 'created_by' => $this->admin->id]);
        $this->spot = $make('published');
        $make('draft');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function visit(string $path): void
    {
        $this->postJson('/api/v1/page-views', ['path' => $path])->assertNoContent();
    }

    private function views(): array
    {
        return DB::table('page_views')->orderBy('path')->pluck('views', 'path')->map(fn ($n) => (int) $n)->all();
    }

    public function test_each_visit_to_a_public_page_counts_once_on_the_local_day(): void
    {
        $this->visit('/');
        $this->visit('/destinations?municipality_id=3');
        $this->visit('/destinations/');
        $this->visit("/destinations/{$this->spot->id}");

        $this->assertSame(['/' => 1, '/destinations' => 2, "/destinations/{$this->spot->id}" => 1], $this->views());
        $this->assertSame(['2026-11-13'], DB::table('page_views')->distinct()->pluck('date')->map(fn ($d) => substr($d, 0, 10))->all());
    }

    public function test_pages_that_are_not_public_or_do_not_exist_are_not_counted(): void
    {
        $draft = Destination::where('status', 'draft')->value('id');
        foreach (['/admin', '/admin/guest-reviews', '/business', '/login', '/dashboard', '/nope', '/destinations/999999', "/destinations/{$draft}", 'javascript:alert(1)'] as $path) {
            $this->visit($path);
        }
        $this->postJson('/api/v1/page-views', [])->assertUnprocessable();

        $this->assertSame([], $this->views());
    }

    public function test_api_calls_are_not_page_views(): void
    {
        $this->getJson("/api/v1/destinations/{$this->spot->id}")->assertOk();
        $this->getJson('/api/v1/destinations')->assertOk();

        $this->assertSame([], $this->views());
        $this->assertSame(0, DB::table('visitor_statistics')->count());
    }

    public function test_the_dashboard_trend_is_seven_whole_numbers_that_add_up_to_the_total(): void
    {
        DB::table('page_views')->insert([
            ['date' => '2026-11-13', 'path' => '/', 'views' => 12, 'created_at' => now(), 'updated_at' => now()],
            ['date' => '2026-11-13', 'path' => '/destinations', 'views' => 11, 'created_at' => now(), 'updated_at' => now()],
            ['date' => '2026-11-10', 'path' => '/', 'views' => 16, 'created_at' => now(), 'updated_at' => now()],
            ['date' => '2026-11-07', 'path' => '/events', 'views' => 23, 'created_at' => now(), 'updated_at' => now()],
            ['date' => '2026-11-06', 'path' => '/', 'views' => 99, 'created_at' => now(), 'updated_at' => now()], // eight days ago: outside
            ['date' => '2026-10-31', 'path' => '/', 'views' => 5, 'created_at' => now(), 'updated_at' => now()], // last month
        ]);
        $this->visit('/about'); // today, 13 November in Sorsogon

        $trend = $this->actingAs($this->admin)->getJson('/api/v1/admin/dashboard')->assertOk()->json('visitor_trend');

        $this->assertSame(['2026-11-07', '2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13'], array_column($trend, 'date'));
        $this->assertSame([23, 0, 0, 16, 0, 0, 24], array_column($trend, 'views'));
        $this->assertSame(63, array_sum(array_column($trend, 'views')));
        $this->assertSame(162, $this->actingAs($this->admin)->getJson('/api/v1/admin/dashboard')->json('stats.monthly_views')); // November only, not October
    }

    public function test_reports_use_the_same_daily_page_views(): void
    {
        DB::table('page_views')->insert(['date' => '2026-11-12', 'path' => '/', 'views' => 4, 'created_at' => now(), 'updated_at' => now()]);

        $daily = $this->actingAs($this->admin)->getJson('/api/v1/admin/reports/visitors?days=7')->assertOk()->json();
        $this->assertCount(7, $daily);
        $this->assertSame(['date' => '2026-11-12', 'total_views' => 4], $daily[5]);
        $this->assertSame(4, $this->actingAs($this->admin)->getJson('/api/v1/admin/reports/overview')->json('monthly_views'));
        $this->actingAs($this->admin)->get('/api/v1/admin/reports/export')->assertOk();
    }
}

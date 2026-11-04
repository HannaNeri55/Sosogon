<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\BusinessSubmission;
use App\Models\Destination;
use App\Models\Rating;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoBusinessOwnerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DemoBusinessOwnerSeederTest extends TestCase
{
    use RefreshDatabase;

    private const TOWNS = ['Bulan', 'Bulusan', 'Gubat', 'Donsol', 'Irosin', 'Juban', 'Magallanes', 'Santa Magdalena', 'Pilar', 'Matnog', 'Sorsogon City'];

    private array $town = [];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        putenv('DEMO_PREVIEW');
        putenv('DEMO_OWNER_PASSWORD=local-demo-password');

        $adminRole = Role::create(['name' => 'admin', 'description' => 'Administrator'])->id;
        Role::create(['name' => 'business_owner', 'description' => 'Business Owner']);
        Role::create(['name' => 'tourist', 'description' => 'Tourist']);
        $this->admin = User::create(['role_id' => $adminRole, 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x')]);

        foreach (self::TOWNS as $name) {
            $this->town[$name] = DB::table('municipalities')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('barangays')->insert(['municipality_id' => $this->town['Gubat'], 'name' => 'Rizal', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        putenv('DEMO_PREVIEW');
        putenv('DEMO_OWNER_PASSWORD');
        parent::tearDown();
    }

    /** Bulan accounts, listings, destinations and reviews that must never change. */
    private function makeBulanData(): void
    {
        $owner = User::create(['role_id' => Role::where('name', 'business_owner')->value('id'), 'name' => 'Bulan Owner',
            'email' => 'bulan.owner@example.com', 'password' => bcrypt('x'), 'status' => 'active', 'business_type' => 'accommodation']);
        $base = ['description' => 'A Bulan listing that must stay untouched.', 'municipality_id' => $this->town['Bulan'],
            'address' => 'Bulan, Sorsogon', 'latitude' => 12.67, 'longitude' => 123.87, 'status' => 'approved', 'user_id' => $owner->id];
        $inn = Accommodation::create($base + ['name' => 'Bulan Inn', 'type' => 'Inn', 'price_range' => 'budget']);
        RetailShop::create($base + ['name' => 'Bulan Pasalubong']);
        $category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        Destination::create(['name' => 'Bulan Beach', 'description' => 'Bulan beach.', 'category_id' => $category,
            'municipality_id' => $this->town['Bulan'], 'address' => 'Bulan', 'latitude' => 12.6, 'longitude' => 123.8,
            'created_by' => $this->admin->id, 'status' => 'published']);
        Rating::create(['user_id' => $this->admin->id, 'rateable_type' => Accommodation::class, 'rateable_id' => $inn->id, 'score' => 4, 'comment' => 'Fine']);
    }

    private function bulanSnapshot(): string
    {
        $bulan = $this->town['Bulan'];

        return md5(json_encode([
            DB::table('municipalities')->where('id', $bulan)->get(),
            DB::table('accommodations')->where('municipality_id', $bulan)->get(),
            DB::table('food_beverages')->where('municipality_id', $bulan)->get(),
            DB::table('retail_shops')->where('municipality_id', $bulan)->get(),
            DB::table('destinations')->where('municipality_id', $bulan)->get(),
            DB::table('business_submissions')->where('municipality_id', $bulan)->get(),
            DB::table('users')->where('email', 'like', '%bulan%')->get(),
            DB::table('ratings')->get(),
        ]));
    }

    public function test_seeds_24_pending_sample_submissions_once_and_leaves_bulan_unchanged(): void
    {
        $this->makeBulanData();
        $before = $this->bulanSnapshot();
        $listingsBefore = [Accommodation::count(), RetailShop::count()];

        $this->seed(DemoBusinessOwnerSeeder::class);
        $this->seed(DemoBusinessOwnerSeeder::class);

        $owners = User::where('email', 'like', '%@'.DemoBusinessOwnerSeeder::EMAIL_DOMAIN)->get();
        $submissions = BusinessSubmission::where('is_sample', true)->get();
        $this->assertCount(24, $owners);
        $this->assertCount(24, $submissions);
        $this->assertSame(16, $submissions->where('type', 'accommodation')->count());
        $this->assertSame(8, $submissions->where('type', 'retail_product')->count());
        $this->assertSame(0, $submissions->where('type', 'food_beverage')->count());

        // Every owner is pending, has one submission of its own type, and nothing is approved or published.
        foreach ($owners as $owner) {
            $this->assertSame('pending', $owner->status);
            $this->assertStringStartsWith('Demo Owner – ', $owner->name);
            $mine = $submissions->where('user_id', $owner->id);
            $this->assertCount(1, $mine);
            $this->assertSame($owner->business_type, $mine->first()->type);
        }
        $this->assertSame(['pending'], $submissions->pluck('status')->unique()->values()->all());
        $this->assertSame([DemoBusinessOwnerSeeder::CONTACT], $submissions->pluck('contact_number')->unique()->values()->all());
        $this->assertSame($listingsBefore, [Accommodation::count(), RetailShop::count()], 'Seeding must not create listings.');

        // Labels, approximate notes, empty hours and preserved names.
        foreach ($submissions as $s) {
            $this->assertStringEndsWith(DemoBusinessOwnerSeeder::SAMPLE_LABEL, $s->description);
            $this->assertStringStartsWith('SAMPLE DATA', $s->admin_notes);
        }
        $approx = $submissions->filter(fn ($s) => str_contains($s->description, DemoBusinessOwnerSeeder::APPROXIMATE_NOTE))->pluck('business_name')->sort()->values()->all();
        $this->assertSame(['Altarejos Homestay', "Kendi's Beach Garden", 'Modesta Resort', 'Siama Surf Resort'], $approx);
        $this->assertNull($submissions->firstWhere('business_name', "Elizabeth's hostel")->operating_hours);
        $this->assertNull($submissions->firstWhere('business_name', 'Bagol Craft & Souvenir Shop')->operating_hours);
        $this->assertSame($this->town['Gubat'], $submissions->firstWhere('business_name', 'Siama Surf Resort')->municipality_id);
        $this->assertNotNull($submissions->firstWhere('business_name', 'Siama Surf Resort')->barangay_id);
        $this->assertNull($submissions->firstWhere('business_name', "Kendi's Beach Garden")->barangay_id, 'No barangay is invented.');

        // No demo record in Bulan, and Bulan data is byte-for-byte unchanged.
        $this->assertSame(0, $submissions->where('municipality_id', $this->town['Bulan'])->count());
        $this->assertSame($before, $this->bulanSnapshot());
    }

    public function test_permits_are_private_and_only_the_owner_and_admin_can_download_them(): void
    {
        $this->seed(DemoBusinessOwnerSeeder::class);
        [$first, $second] = BusinessSubmission::where('is_sample', true)->orderBy('id')->take(2)->get()->all();

        Storage::disk('local')->assertExists($first->business_permit_path);
        $this->assertStringStartsWith('business-permits/'.$first->user_id.'/', $first->business_permit_path);
        $this->assertStringContainsString('SAMPLE - NOT A REAL BUSINESS PERMIT', Storage::disk('local')->get($first->business_permit_path));

        // Seeded owners are pending: no Business Owner API access until an admin approves them.
        $this->actingAs($first->user)->get("/api/v1/business/submissions/{$first->id}/permit")->assertForbidden();

        // Once approved, an owner can download only their own permit.
        $first->user->forceFill(['status' => 'active'])->save();
        $second->user->forceFill(['status' => 'active'])->save();
        $this->actingAs($first->user->fresh())->get("/api/v1/business/submissions/{$first->id}/permit")->assertOk();
        $this->actingAs($second->user->fresh())->get("/api/v1/business/submissions/{$first->id}/permit")->assertNotFound();
        $this->actingAs($this->admin)->get("/api/v1/admin/submissions/{$first->id}/permit")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/admin/submissions/{$first->id}/permit")->assertUnauthorized();
    }

    public function test_preview_writes_nothing(): void
    {
        putenv('DEMO_PREVIEW=1');
        putenv('DEMO_OWNER_PASSWORD');
        $this->seed(DemoBusinessOwnerSeeder::class);

        $this->assertSame(0, BusinessSubmission::count());
        $this->assertSame(0, User::where('email', 'like', '%@'.DemoBusinessOwnerSeeder::EMAIL_DOMAIN)->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_refuses_production_and_a_missing_password(): void
    {
        $this->app['env'] = 'production';
        try {
            // Called directly: db:seed itself would stop to ask for confirmation in production.
            (new DemoBusinessOwnerSeeder)->run();
            $this->fail('Seeder ran in production.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('production', $e->getMessage());
        } finally {
            $this->app['env'] = 'testing';
        }

        putenv('DEMO_OWNER_PASSWORD');
        $this->expectExceptionMessage('DEMO_OWNER_PASSWORD');
        try {
            $this->seed(DemoBusinessOwnerSeeder::class);
        } finally {
            $this->assertSame(0, BusinessSubmission::count());
        }
    }

    public function test_stops_without_writing_when_bulan_is_ambiguous(): void
    {
        DB::table('municipalities')->insert(['name' => ' bulan ', 'created_at' => now(), 'updated_at' => now()]);

        try {
            $this->seed(DemoBusinessOwnerSeeder::class);
            $this->fail('Seeder ran with two Bulan rows.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('exactly one Bulan', $e->getMessage());
        }
        $this->assertSame(0, BusinessSubmission::count());
        $this->assertSame(0, User::where('email', 'like', '%@'.DemoBusinessOwnerSeeder::EMAIL_DOMAIN)->count());
    }

    public function test_stops_without_writing_when_a_town_is_missing(): void
    {
        DB::table('municipalities')->where('name', 'Matnog')->delete();

        $this->expectExceptionMessage('Matnog');
        try {
            $this->seed(DemoBusinessOwnerSeeder::class);
        } finally {
            $this->assertSame(0, BusinessSubmission::count());
        }
    }

    public function test_skips_businesses_that_already_exist_without_touching_them(): void
    {
        $existing = Accommodation::create(['name' => 'Balay Talisay', 'description' => 'Already listed by someone else.',
            'type' => 'Inn', 'price_range' => 'moderate', 'municipality_id' => $this->town['Sorsogon City'], 'address' => 'x',
            'latitude' => 12.96, 'longitude' => 123.99, 'status' => 'approved']);
        $snapshot = $existing->fresh()->toArray();

        $this->seed(DemoBusinessOwnerSeeder::class);

        $this->assertSame(23, BusinessSubmission::where('is_sample', true)->count());
        $this->assertFalse(BusinessSubmission::where('business_name', 'Balay Talisay')->exists());
        $this->assertSame($snapshot, $existing->fresh()->toArray());
    }
}

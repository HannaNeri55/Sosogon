<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\BusinessSubmission;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Option C: sample listings are hidden from every public path when listings.show_samples is off. */
class SampleListingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private int $town;

    private User $admin;

    private User $tourist;

    private int $ownerRole;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $adminRole = Role::create(['name' => 'admin', 'description' => 'Administrator'])->id;
        $this->ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Business Owner'])->id;
        $touristRole = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        $this->admin = User::create(['role_id' => $adminRole, 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('x')]);
        $this->tourist = User::create(['role_id' => $touristRole, 'name' => 'Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('x')]);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Irosin', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function fields(string $name): array
    {
        return [
            'name' => $name, 'description' => "{$name} used for automated sample-visibility testing.",
            'municipality_id' => $this->town, 'address' => 'Test St.', 'latitude' => 12.70, 'longitude' => 124.03,
            'status' => 'approved',
        ];
    }

    /** One real and one sample approved listing of every kind, plus a product under each shop. */
    private function seedPairs(): array
    {
        $made = [];
        foreach ([true, false] as $sample) {
            $tag = $sample ? 'Sample' : 'Real';
            $acc = Accommodation::create($this->fields("{$tag} Inn") + ['type' => 'Accommodation', 'price_range' => 'moderate']);
            $food = FoodBeverage::create($this->fields("{$tag} Eatery") + ['price_range' => 'moderate']);
            $shop = RetailShop::create($this->fields("{$tag} Shop"));
            foreach ([$acc, $food, $shop] as $model) {
                $model->forceFill(['is_sample' => $sample])->save();
            }
            $product = RetailProduct::create($this->fields("{$tag} Pili Tart") + ['retail_shop_id' => $shop->id, 'product_type' => 'food', 'price' => 100]);
            $made[$tag] = compact('acc', 'food', 'shop', 'product');
        }

        return $made;
    }

    private function names(string $url, string $key = 'data'): array
    {
        return array_column($this->getJson($url)->assertOk()->json($key), 'name');
    }

    public function test_hidden_samples_are_missing_from_every_public_path_and_real_listings_stay(): void
    {
        config(['listings.show_samples' => false]);
        $p = $this->seedPairs();

        // Lists.
        $this->assertSame(['Real Inn'], $this->names('/api/v1/accommodations'));
        $this->assertSame(['Real Eatery'], $this->names('/api/v1/food-beverages'));
        $this->assertSame(['Real Shop'], $this->names('/api/v1/retail-shops'));
        $this->assertSame(['Real Pili Tart'], $this->names('/api/v1/retail-products'));

        // Detail pages.
        $this->getJson("/api/v1/accommodations/{$p['Sample']['acc']->id}")->assertNotFound();
        $this->getJson("/api/v1/food-beverages/{$p['Sample']['food']->id}")->assertNotFound();
        $this->getJson("/api/v1/retail-shops/{$p['Sample']['shop']->id}")->assertNotFound();
        $this->getJson("/api/v1/retail-products/{$p['Sample']['product']->id}")->assertNotFound();
        $this->getJson("/api/v1/accommodations/{$p['Real']['acc']->id}")->assertOk();

        // Search.
        $search = $this->getJson('/api/v1/search?q=testing&type=all')->assertOk()->json('results');
        $found = collect($search)->flatten(1)->pluck('name')->all();
        $this->assertEmpty(array_filter($found, fn ($n) => str_starts_with($n, 'Sample')));
        foreach (['Real Inn', 'Real Eatery', 'Real Shop', 'Real Pili Tart'] as $real) {
            $this->assertContains($real, $found);
        }

        // Nearby on accommodation and destination pages.
        $nearby = $this->getJson("/api/v1/accommodations/{$p['Real']['acc']->id}")->json();
        $this->assertNotContains('Sample Eatery', array_column($nearby['nearby_food_beverages'] ?? [], 'name'));
        $this->assertNotContains('Sample Shop', array_column($nearby['nearby_retail_shops'] ?? [], 'name'));

        $category = DB::table('categories')->insertGetId(['name' => 'Spring', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $spot = Destination::create(['name' => 'Hot Spring', 'description' => 'Test spring.', 'category_id' => $category,
            'municipality_id' => $this->town, 'address' => 'Test', 'latitude' => 12.70, 'longitude' => 124.03,
            'created_by' => $this->admin->id, 'status' => 'published']);
        $dest = $this->getJson("/api/v1/destinations/{$spot->id}")->assertOk()->json();
        $this->assertSame(['Real Inn'], array_column($dest['nearby_accommodations'], 'name'));
        $this->assertSame(['Real Eatery'], array_column($dest['nearby_food_beverages'], 'name'));
        $this->assertSame(['Real Shop'], array_column($dest['nearby_retail_shops'], 'name'));

        // Itinerary candidates.
        $trip = Itinerary::create(['user_id' => $this->tourist->id, 'municipality_id' => $this->town, 'title' => 'Trip',
            'num_days' => 1, 'interests' => ['nature'], 'travel_pace' => 'moderate', 'budget' => 'moderate']);
        $eligible = $this->actingAs($this->tourist)->getJson("/api/v1/itineraries/{$trip->id}/eligible-listings")->assertOk()->json();
        $this->assertSame(['Real Inn'], array_column($eligible['accommodations'], 'name'));
        $this->assertSame(['Real Eatery'], array_column($eligible['food_beverages'], 'name'));
        $this->assertSame(['Real Shop'], array_column($eligible['shops'], 'name'));

        // Admin dashboard totals count real listings only.
        $stats = $this->actingAs($this->admin)->getJson('/api/v1/admin/dashboard')->assertOk()->json();
        $this->assertSame(1, data_get($stats, 'stats.total_accommodations') ?? data_get($stats, 'total_accommodations'));
    }

    public function test_samples_show_with_their_flag_when_enabled(): void
    {
        config(['listings.show_samples' => true]);
        $p = $this->seedPairs();

        $rows = $this->getJson('/api/v1/accommodations')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['Real Inn', 'Sample Inn'], array_column($rows, 'name'));
        $this->assertTrue(collect($rows)->firstWhere('name', 'Sample Inn')['is_sample']);
        $this->assertFalse(collect($rows)->firstWhere('name', 'Real Inn')['is_sample']);
        $this->getJson("/api/v1/retail-products/{$p['Sample']['product']->id}")->assertOk();
    }

    public function test_hidden_samples_cannot_be_reviewed_but_real_listings_can(): void
    {
        config(['listings.show_samples' => false]);
        $p = $this->seedPairs();
        $review = fn (Accommodation $a) => $this->actingAs($this->tourist)->postJson('/api/v1/ratings',
            ['rateable_type' => 'accommodation', 'rateable_id' => $a->id, 'score' => 4, 'comment' => 'Nice stay']);

        $review($p['Sample']['acc'])->assertNotFound();
        $review($p['Real']['acc'])->assertCreated();
    }

    public function test_requests_cannot_set_or_clear_the_sample_flag(): void
    {
        config(['listings.show_samples' => true]);
        $p = $this->seedPairs();

        $this->actingAs($this->admin)->putJson("/api/v1/admin/accommodations/{$p['Sample']['acc']->id}", ['is_sample' => false, 'name' => 'Sample Inn']);
        $this->actingAs($this->admin)->putJson("/api/v1/admin/accommodations/{$p['Real']['acc']->id}", ['is_sample' => true, 'name' => 'Real Inn']);

        $this->assertTrue($p['Sample']['acc']->fresh()->is_sample);
        $this->assertFalse($p['Real']['acc']->fresh()->is_sample);
        $this->assertFalse(Accommodation::create($this->fields('Mass Assigned') + ['type' => 'Accommodation', 'price_range' => 'moderate', 'is_sample' => true])->fresh()->is_sample);
    }

    private function owner(string $email): User
    {
        return User::create(['role_id' => $this->ownerRole, 'name' => 'Owner', 'email' => $email, 'password' => bcrypt('x'),
            'status' => 'active', 'business_type' => 'accommodation']);
    }

    private function submission(User $owner, string $name, bool $sample): BusinessSubmission
    {
        $s = BusinessSubmission::create(['user_id' => $owner->id, 'business_name' => $name, 'type' => 'accommodation',
            'description' => "{$name} used for automated approval-workflow testing.", 'address' => 'Test St.',
            'municipality_id' => $this->town, 'latitude' => 12.70, 'longitude' => 124.03,
            'contact_number' => 'Not verified', 'business_permit_path' => 'business-permits/test.pdf', 'status' => 'pending']);
        $s->forceFill(['is_sample' => $sample])->save();

        return $s;
    }

    public function test_existing_approval_and_publication_workflow_still_gates_a_sample(): void
    {
        config(['listings.show_samples' => true]);
        $owner = $this->owner('owner.demo@demo.sosogon.test');
        $sample = $this->submission($owner, 'Sample Lodge', true);
        $real = $this->submission($this->owner('real@example.com'), 'Real Lodge', false);

        // Submission approval copies the flag and still creates an unpublished draft.
        $this->actingAs($this->admin)->putJson("/api/v1/admin/submissions/{$sample->id}/approve")->assertOk();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/submissions/{$real->id}/approve")->assertOk();
        $listing = Accommodation::findOrFail($sample->fresh()->listing_id);
        $this->assertTrue($listing->is_sample);
        $this->assertSame(['pending', 'draft'], [$listing->status, $listing->publication_review_status]);
        $this->assertFalse(Accommodation::findOrFail($real->fresh()->listing_id)->is_sample);
        $this->getJson("/api/v1/accommodations/{$listing->id}")->assertNotFound();

        // Without 5 photos the owner can't submit and the admin can't publish.
        $this->actingAs($owner)->postJson('/api/v1/business/accommodation/submit-for-review')->assertUnprocessable();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/accommodations/{$listing->id}", ['status' => 'approved'])->assertUnprocessable();
        $this->assertSame('pending', $listing->fresh()->status);

        // With 5 photos: owner submits for review, admin publishes, and it appears (badged) while samples are shown.
        foreach (range(1, 5) as $i) {
            DB::table('accommodation_images')->insert(['accommodation_id' => $listing->id, 'image_path' => "test/{$i}.webp",
                'thumbnail_path' => "test/{$i}.webp", 'is_primary' => $i === 1, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->actingAs($owner)->postJson('/api/v1/business/accommodation/submit-for-review')->assertOk();
        $this->actingAs($this->admin)->putJson("/api/v1/admin/accommodations/{$listing->id}", ['status' => 'approved'])->assertOk();
        $this->assertSame(['approved', 'published'], [$listing->fresh()->status, $listing->fresh()->publication_review_status]);
        $this->assertTrue($this->getJson("/api/v1/accommodations/{$listing->id}")->assertOk()->json('accommodation.is_sample')
            ?? $this->getJson("/api/v1/accommodations/{$listing->id}")->json('is_sample'));

        // Even fully published, it disappears once samples are hidden.
        config(['listings.show_samples' => false]);
        $this->getJson("/api/v1/accommodations/{$listing->id}")->assertNotFound();
    }
}

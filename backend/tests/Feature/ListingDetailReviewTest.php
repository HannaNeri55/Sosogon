<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Detail endpoints, reviews and owner replies for destinations, food & beverage and shops. */
class ListingDetailReviewTest extends TestCase
{
    use RefreshDatabase;

    private int $town;

    private int $category;

    private User $admin;

    private User $tourist;

    private User $owner;

    private User $otherOwner;

    protected function setUp(): void
    {
        parent::setUp();

        $roles = collect(['admin', 'tourist', 'business_owner'])
            ->mapWithKeys(fn ($name) => [$name => Role::create(['name' => $name, 'description' => $name])->id]);

        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('barangays')->insert(['municipality_id' => $this->town, 'name' => 'Zone 1', 'created_at' => now(), 'updated_at' => now()]);
        $this->category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);

        $user = fn (string $role, string $email, array $extra = []) => User::create([
            'role_id' => $roles[$role], 'name' => ucfirst($role).' '.$email, 'email' => $email, 'password' => bcrypt('password'),
        ] + $extra);

        $this->admin = $user('admin', 'admin@example.com');
        $this->tourist = $user('tourist', 'tourist@example.com');
        $this->owner = $user('business_owner', 'owner@example.com', ['business_type' => 'food_beverage']);
        $this->otherOwner = $user('business_owner', 'other@example.com', ['business_type' => 'food_beverage']);
    }

    private function fields(string $name, float $lat, float $lng): array
    {
        return [
            'name' => $name,
            'description' => "{$name} used for automated testing purposes only.",
            'municipality_id' => $this->town,
            'barangay_id' => DB::table('barangays')->value('id'),
            'address' => 'Test St.',
            'latitude' => $lat,
            'longitude' => $lng,
        ];
    }

    private function destination(string $name, float $lat, string $status = 'published'): Destination
    {
        return Destination::create($this->fields($name, $lat, 123.870) + [
            'category_id' => $this->category, 'created_by' => $this->admin->id, 'status' => $status,
        ]);
    }

    private function review(User $user, $listing, int $score = 4, string $comment = 'Nice.'): Rating
    {
        return Rating::create([
            'user_id' => $user->id, 'rateable_type' => $listing::class, 'rateable_id' => $listing->id,
            'score' => $score, 'comment' => $comment,
        ]);
    }

    public function test_destination_detail_lists_nearest_places_and_excludes_itself(): void
    {
        $spot = $this->destination('Main Beach', 12.670);
        $this->destination('Far Falls', 12.700);
        $this->destination('Near Park', 12.672);
        $this->destination('Draft Spot', 12.6705, 'draft');

        Accommodation::create($this->fields('Far Inn', 12.690, 123.870) + ['type' => 'Inn', 'price_range' => 'budget', 'status' => 'approved']);
        Accommodation::create($this->fields('Near Inn', 12.671, 123.870) + ['type' => 'Inn', 'price_range' => 'budget', 'status' => 'approved']);
        Accommodation::create($this->fields('Pending Inn', 12.6702, 123.870) + ['type' => 'Inn', 'price_range' => 'budget', 'status' => 'pending']);

        $response = $this->getJson("/api/v1/destinations/{$spot->id}")->assertOk();

        $this->assertSame(['Near Park', 'Far Falls'], array_column($response->json('nearby_destinations'), 'name'));
        $this->assertSame(['Near Inn', 'Far Inn'], array_column($response->json('nearby_accommodations'), 'name'));
        $this->assertEqualsWithDelta(0.1, $response->json('nearby_accommodations.0.distance_km'), 0.05);
    }

    public function test_tourist_can_review_a_destination_but_nobody_can_post_an_owner_reply(): void
    {
        $spot = $this->destination('Main Beach', 12.670);

        $this->postJson('/api/v1/ratings', ['rateable_type' => 'destination', 'rateable_id' => $spot->id, 'score' => 5])
            ->assertUnauthorized();

        $this->actingAs($this->tourist)
            ->postJson('/api/v1/ratings', ['rateable_type' => 'destination', 'rateable_id' => $spot->id, 'score' => 5, 'comment' => 'Beautiful!'])
            ->assertCreated();

        $detail = $this->getJson("/api/v1/destinations/{$spot->id}")->assertOk();
        $this->assertSame('Beautiful!', $detail->json('destination.ratings.0.comment'));
        $this->assertSame(['id', 'name'], array_keys($detail->json('destination.ratings.0.user')));
        $this->assertEquals(1, $detail->json('destination.total_reviews'));

        // Destinations are admin-managed and have no owner, so replies are refused for everyone.
        $ratingId = $detail->json('destination.ratings.0.id');
        $this->actingAs($this->owner)
            ->postJson("/api/v1/business/reviews/{$ratingId}/reply", ['body' => 'Thanks'])
            ->assertForbidden();
    }

    public function test_food_beverage_detail_has_nearby_places_and_owner_can_reply(): void
    {
        $place = FoodBeverage::create($this->fields('Main Eatery', 12.670, 123.870) + ['user_id' => $this->owner->id, 'price_range' => 'budget', 'status' => 'approved']);
        FoodBeverage::create($this->fields('Next Door Cafe', 12.6705, 123.870) + ['price_range' => 'budget', 'status' => 'approved']);
        $this->destination('Near Park', 12.672);

        $detail = $this->getJson("/api/v1/food-beverages/{$place->id}")->assertOk();
        $this->assertSame('Main Eatery', $detail->json('name'));
        $this->assertSame(['Next Door Cafe'], array_column($detail->json('nearby_food_beverages'), 'name'));
        $this->assertSame(['Near Park'], array_column($detail->json('nearby_destinations'), 'name'));

        $rating = $this->review($this->tourist, $place);
        $url = "/api/v1/business/reviews/{$rating->id}/reply";

        $this->actingAs($this->otherOwner)->postJson($url, ['body' => 'Not my place'])->assertForbidden();
        $this->actingAs($this->owner)->postJson($url, ['body' => 'Salamat!'])->assertCreated();

        $this->assertSame('Salamat!', $this->getJson("/api/v1/food-beverages/{$place->id}")->json('ratings.0.reply.body'));
        $this->assertDatabaseHas('ratings', ['id' => $rating->id, 'comment' => 'Nice.']);
    }

    public function test_shop_detail_has_nearby_places_and_owner_can_reply(): void
    {
        $shop = RetailShop::create($this->fields('Main Shop', 12.670, 123.870) + ['user_id' => $this->owner->id, 'status' => 'approved']);
        RetailShop::create($this->fields('Other Shop', 12.671, 123.870) + ['status' => 'approved']);
        RetailShop::create($this->fields('Hidden Shop', 12.6701, 123.870) + ['status' => 'pending']);

        $detail = $this->getJson("/api/v1/retail-shops/{$shop->id}")->assertOk();
        $this->assertSame(['Other Shop'], array_column($detail->json('nearby_retail_shops'), 'name'));

        $rating = $this->review($this->tourist, $shop, 5);
        $this->actingAs($this->tourist)
            ->postJson("/api/v1/business/reviews/{$rating->id}/reply", ['body' => 'Fake owner'])
            ->assertForbidden();
        $this->actingAs($this->owner)
            ->postJson("/api/v1/business/reviews/{$rating->id}/reply", ['body' => 'Thank you!'])
            ->assertCreated();

        $detail = $this->getJson("/api/v1/retail-shops/{$shop->id}")->assertOk();
        $this->assertSame('Thank you!', $detail->json('ratings.0.reply.body'));
        $this->assertEquals(5, $detail->json('average_rating'));
    }

    public function test_every_detail_page_lists_all_four_nearby_types_only_within_the_radius(): void
    {
        // 0.1 to 0.3 km north of 12.670 is "near"; 12.900 is about 25 km away, beyond the 10 km radius.
        $spot = $this->destination('Near Park', 12.671);
        $this->destination('Far Falls', 12.900);
        $inn = Accommodation::create($this->fields('Near Inn', 12.672, 123.870) + ['type' => 'Inn', 'price_range' => 'budget', 'status' => 'approved']);
        Accommodation::create($this->fields('Far Inn', 12.900, 123.870) + ['type' => 'Inn', 'price_range' => 'budget', 'status' => 'approved']);
        $eatery = FoodBeverage::create($this->fields('Near Eatery', 12.673, 123.870) + ['price_range' => 'budget', 'status' => 'approved']);
        FoodBeverage::create($this->fields('Far Eatery', 12.900, 123.870) + ['price_range' => 'budget', 'status' => 'approved']);
        $shop = RetailShop::create($this->fields('Near Shop', 12.670, 123.870) + ['status' => 'approved']);
        RetailShop::create($this->fields('Far Shop', 12.900, 123.870) + ['status' => 'approved']);

        $pages = [
            "/api/v1/destinations/{$spot->id}" => 'Near Park',
            "/api/v1/accommodations/{$inn->id}" => 'Near Inn',
            "/api/v1/food-beverages/{$eatery->id}" => 'Near Eatery',
            "/api/v1/retail-shops/{$shop->id}" => 'Near Shop',
        ];
        foreach ($pages as $url => $self) {
            $detail = $this->getJson($url)->assertOk();
            $this->assertEquals(10, $detail->json('nearby_radius_km'));
            $names = [];
            foreach (['nearby_destinations', 'nearby_accommodations', 'nearby_food_beverages', 'nearby_retail_shops'] as $key) {
                foreach ($detail->json($key) as $place) {
                    $this->assertLessThanOrEqual(10, $place['distance_km'], "{$url} {$key}");
                    $names[] = $place['name'];
                }
            }
            $expected = array_values(array_diff(['Near Park', 'Near Inn', 'Near Eatery', 'Near Shop'], [$self]));
            $this->assertEqualsCanonicalizing($expected, $names, "{$url} shows every nearby type, never itself or a far place");
        }
    }

    public function test_admin_review_overview_is_read_only_and_admin_only(): void
    {
        $spot = $this->destination('Main Beach', 12.670);
        $shop = RetailShop::create($this->fields('Main Shop', 12.670, 123.870) + ['user_id' => $this->owner->id, 'status' => 'approved']);
        $this->review($this->tourist, $spot, 5, 'Demo Review: Sample text.');
        $genuine = $this->review($this->tourist, $shop, 4, 'Real feedback.');
        $this->actingAs($this->owner)->postJson("/api/v1/business/reviews/{$genuine->id}/reply", ['body' => 'Thanks!'])->assertCreated();

        $this->actingAs($this->tourist)->getJson('/api/v1/admin/guest-reviews')->assertForbidden();
        $this->actingAs($this->owner)->getJson('/api/v1/admin/guest-reviews')->assertForbidden();

        $all = $this->actingAs($this->admin)->getJson('/api/v1/admin/guest-reviews')->assertOk();
        $all->assertJsonPath('total', 2)
            ->assertJsonPath('counts.destination', 1)
            ->assertJsonPath('counts.retail_shop', 1);

        $this->actingAs($this->admin)->getJson('/api/v1/admin/guest-reviews?source=demo')
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.listing_type', 'destination');

        $replied = $this->actingAs($this->admin)->getJson('/api/v1/admin/guest-reviews?type=retail_shop&filter=replied')->assertOk();
        $replied->assertJsonPath('total', 1)->assertJsonPath('data.0.reply.body', 'Thanks!')->assertJsonPath('data.0.rateable.name', 'Main Shop');
        $this->assertSame(['id', 'name'], array_keys($replied->json('data.0.user')));

        $this->actingAs($this->admin)->getJson('/api/v1/admin/guest-reviews?type=bogus')->assertStatus(422);
    }
}

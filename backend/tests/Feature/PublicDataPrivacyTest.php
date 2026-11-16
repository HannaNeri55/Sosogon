<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\Announcement;
use App\Models\Destination;
use App\Models\Event;
use App\Models\FoodBeverage;
use App\Models\HeroSlide;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Rating;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use App\Support\PublicData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The public API never sends a listing owner's account (email, phone, status…) or admin-only
 * fields (who created or approved a record, admin notes, workflow status, view counts, the
 * planner's ranking, review moderation). Fields the public pages use stay, and the admin API
 * still has everything.
 */
class PublicDataPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_EMAIL = 'owner.private@example.com';

    private const OWNER_PHONE = '09170001234';

    private const ADMIN_NOTE = 'Rejected once: blurry cover photo';

    private User $owner;

    private User $admin;

    private User $tourist;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $roles = collect(['admin', 'tourist', 'business_owner'])->mapWithKeys(fn ($name) => [$name => Role::create(['name' => $name, 'description' => $name])->id]);
        $this->owner = User::create(['role_id' => $roles['business_owner'], 'name' => 'Owner Person', 'email' => self::OWNER_EMAIL, 'password' => bcrypt('password'),
            'phone' => self::OWNER_PHONE, 'business_type' => 'accommodation']);
        $this->admin = User::create(['role_id' => $roles['admin'], 'name' => 'Admin Person', 'email' => 'admin@example.com', 'password' => bcrypt('password'), 'status' => 'active']);
        $this->admin->forceFill(['admin_scope' => 'provincial'])->save();
        $this->tourist = User::create(['role_id' => $roles['tourist'], 'name' => 'Reviewing Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password')]);

        $town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $barangay = DB::table('barangays')->insertGetId(['municipality_id' => $town, 'name' => 'Zone 1', 'created_at' => now(), 'updated_at' => now()]);
        $category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $place = fn (string $name, float $lat) => ['name' => $name, 'description' => "{$name} is used for automated testing.", 'municipality_id' => $town,
            'barangay_id' => $barangay, 'address' => 'Test St.', 'latitude' => $lat, 'longitude' => 123.870];
        $approved = ['user_id' => $this->owner->id, 'status' => 'approved', 'approved_by' => $this->admin->id, 'approved_at' => now()];

        $destination = Destination::create($place('Test Cove', 12.670) + ['category_id' => $category, 'created_by' => $this->admin->id, 'status' => 'published']);
        $destination->forceFill(['tourism_priority' => 'popular'])->save();
        $inn = Accommodation::create($place('Test Inn', 12.671) + ['type' => 'Inn', 'price_range' => 'budget', 'email' => 'reservations@testinn.example.com',
            'contact_number' => '0917 555 0101'] + $approved);
        AccommodationRoom::create(['accommodation_id' => $inn->id, 'room_number' => 'Sea View Room', 'notes' => 'Faces the sunrise.', 'status' => 'approved',
            'approved_at' => now(), 'approved_by' => $this->admin->id, 'admin_notes' => self::ADMIN_NOTE]);
        $eatery = FoodBeverage::create($place('Test Eatery', 12.672) + ['price_range' => 'budget'] + $approved);
        $shop = RetailShop::create($place('Test Shop', 12.673) + $approved);
        $shop->forceFill(['admin_notes' => self::ADMIN_NOTE])->save();
        $product = RetailProduct::create(['retail_shop_id' => $shop->id, 'name' => 'Pili Tarts', 'description' => 'A test product.', 'product_type' => 'Souvenir',
            'price' => 100, 'municipality_id' => $town, 'user_id' => $this->owner->id, 'status' => 'approved', 'approved_by' => $this->admin->id]);
        $product->forceFill(['admin_notes' => self::ADMIN_NOTE])->save();
        foreach ([$destination, $inn, $eatery, $shop, $product] as $listing) {
            Rating::create(['user_id' => $this->tourist->id, 'rateable_type' => $listing::class, 'rateable_id' => $listing->id, 'score' => 5, 'comment' => 'Lovely.']);
        }
        $event = Event::forceCreate(['title' => 'Test Festival', 'description' => 'A test event.', 'start_date' => now()->addDays(5)->toDateString(),
            'municipality_id' => $town, 'location' => 'Plaza', 'status' => 'published', 'created_by' => $this->admin->id]);
        Announcement::forceCreate(['title' => 'Test notice', 'content' => 'A test announcement.', 'status' => 'published', 'created_by' => $this->admin->id]);
        HeroSlide::forceCreate(['title' => 'Test slide', 'image_path' => 'hero/test.jpg', 'is_active' => true, 'sort_order' => 1, 'created_by' => $this->admin->id]);

        $this->ids = ['destination' => $destination->id, 'accommodation' => $inn->id, 'food' => $eatery->id, 'shop' => $shop->id, 'product' => $product->id, 'event' => $event->id];
    }

    /** Every key anywhere in a JSON response. */
    private function keys(mixed $value, array &$found = []): array
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $found[$key] = true;
                }
                $this->keys($item, $found);
            }
        }

        return $found;
    }

    private function assertPublicSafe(TestResponse $response, string $label): void
    {
        $response->assertOk();
        $keys = $this->keys($response->json());
        foreach (PublicData::HIDDEN as $field) {
            $this->assertArrayNotHasKey($field, $keys, "{$label} sends {$field}");
        }
        foreach ([self::OWNER_EMAIL, self::OWNER_PHONE, self::ADMIN_NOTE] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent(), "{$label} sends private data");
        }
    }

    private function publicEndpoints(): array
    {
        return [
            'destinations' => '/api/v1/destinations',
            'destination' => "/api/v1/destinations/{$this->ids['destination']}",
            'accommodations' => '/api/v1/accommodations?with_rooms=1',
            'accommodation' => "/api/v1/accommodations/{$this->ids['accommodation']}",
            'food-beverages' => '/api/v1/food-beverages',
            'food-beverage' => "/api/v1/food-beverages/{$this->ids['food']}",
            'retail-shops' => '/api/v1/retail-shops',
            'retail-shop' => "/api/v1/retail-shops/{$this->ids['shop']}",
            'retail-products' => '/api/v1/retail-products',
            'retail-product' => "/api/v1/retail-products/{$this->ids['product']}",
            'events' => '/api/v1/events',
            'events-calendar' => '/api/v1/events/calendar?'.http_build_query(['month' => now()->addDays(5)->month, 'year' => now()->addDays(5)->year]),
            'event' => "/api/v1/events/{$this->ids['event']}",
            'announcements' => '/api/v1/announcements',
            'hero-slides' => '/api/v1/hero-slides',
        ];
    }

    public function test_public_endpoints_never_send_owner_accounts_or_admin_only_fields(): void
    {
        foreach ($this->publicEndpoints() as $label => $uri) {
            $this->assertPublicSafe($this->getJson($uri), $label);
        }
    }

    public function test_detail_pages_still_count_views_without_loading_the_owner(): void
    {
        foreach (['destination' => Destination::class, 'accommodation' => Accommodation::class, 'food' => FoodBeverage::class, 'shop' => RetailShop::class, 'product' => RetailProduct::class] as $key => $model) {
            $before = (int) $model::find($this->ids[$key])->view_count;
            $uri = $this->publicEndpoints()[['destination' => 'destination', 'accommodation' => 'accommodation', 'food' => 'food-beverage', 'shop' => 'retail-shop', 'product' => 'retail-product'][$key]];

            $response = $this->getJson($uri)->assertOk();

            $this->assertSame($before + 1, (int) $model::find($this->ids[$key])->view_count, "{$key} view counted");
            $response->assertJsonMissingPath('user');
            $response->assertJsonMissingPath('shop.user');
        }
    }

    public function test_fields_the_public_pages_use_are_kept(): void
    {
        $inn = $this->getJson("/api/v1/accommodations/{$this->ids['accommodation']}")->assertOk();
        $inn->assertJsonPath('user_id', $this->owner->id)
            ->assertJsonPath('email', 'reservations@testinn.example.com')
            ->assertJsonPath('contact_number', '0917 555 0101')
            ->assertJsonPath('rooms.0.notes', 'Faces the sunrise.')
            ->assertJsonPath('ratings.0.user_id', $this->tourist->id)
            ->assertJsonPath('ratings.0.user.name', 'Reviewing Tourist');
        $this->getJson("/api/v1/destinations/{$this->ids['destination']}")->assertOk()
            ->assertJsonPath('destination.ratings.0.user.name', 'Reviewing Tourist');
    }

    public function test_a_shared_itinerary_sends_no_owner_account_or_admin_only_fields(): void
    {
        $itinerary = Itinerary::create(['user_id' => $this->tourist->id, 'municipality_id' => Destination::first()->municipality_id, 'title' => 'Shared trip', 'num_days' => 1,
            'interests' => ['nature'], 'travel_pace' => 'moderate', 'budget' => 'moderate', 'start_date' => '2026-12-05', 'end_date' => '2026-12-05', 'total_distance_km' => 1]);
        $itinerary->forceFill(['share_token' => str_repeat('a', 48)])->save();
        foreach ([['destination', 'destination_id', $this->ids['destination']], ['accommodation', 'accommodation_id', $this->ids['accommodation']]] as $n => [$type, $column, $id]) {
            ItineraryItem::create(['itinerary_id' => $itinerary->id, 'item_type' => $type, $column => $id, 'day_number' => 1, 'sequence' => $n + 1, 'time_slot' => '9:00 AM']);
        }

        $this->assertPublicSafe($this->getJson('/api/v1/shared-itineraries/'.str_repeat('a', 48)), 'shared itinerary');
    }

    public function test_the_admin_api_still_has_the_admin_only_fields(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/admin/destinations')->assertOk()
            ->assertJsonPath('data.0.status', 'published')
            ->assertJsonPath('data.0.tourism_priority', 'popular')
            ->assertJsonPath('data.0.created_by', $this->admin->id);
    }
}

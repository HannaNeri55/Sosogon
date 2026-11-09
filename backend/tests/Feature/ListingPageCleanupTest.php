<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\DestinationFee;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\ListingEstimate;
use App\Models\MenuItem;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ListingEstimateSeeder;
use Database\Seeders\ListingPageCleanupSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The listing page clean-up (demo labels, placeholder contacts, a mismatched menu photo, the
 * test listing) and fee lists taking precedence over the entrance_fee column and estimates.
 */
class ListingPageCleanupTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private int $town;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'test-model', 'services.gemini.fallback_models' => [], 'services.google_maps.api_key' => '']);

        $roleId = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        $this->tourist = User::create(['role_id' => $roleId, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password')]);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Testville', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('categories')->insert(['id' => 1, 'name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);

        $place = fn (string $name, array $extra) => FoodBeverage::create(array_merge([
            'name' => $name, 'description' => "{$name} serves local food.", 'municipality_id' => $this->town, 'address' => 'Test St.',
            'latitude' => 12.67, 'longitude' => 123.88, 'price_range' => 'moderate', 'status' => 'approved',
        ], $extra))->id;
        $this->ids = [
            'bentornato' => $place('Bentornato', ['contact_number' => '0900 000 0101']),
            'pending' => $place('Pending Cafe', ['status' => 'pending', 'contact_number' => '0900 000 0111',
                'description' => "A cosy cafe.\n\nDemo details: the contact number, opening hours, price range and menu prices are placeholders that have not been confirmed with the business."]),
            'realNumber' => $place('Real Number Diner', ['contact_number' => '09102969672']),
            'test' => $place('Test Restaurant - Revision 2', ['contact_number' => '09102969672']),
            'inn' => Accommodation::create(['name' => 'Quiet Inn', 'description' => 'An inn.', 'municipality_id' => $this->town, 'address' => 'Test St.',
                'latitude' => 12.671, 'longitude' => 123.881, 'type' => 'Inn', 'price_range' => 'budget', 'status' => 'approved', 'contact_number' => 'Not verified'])->id,
        ];

        Storage::disk('public')->put('menu-items/1/pancit.webp', 'PANCIT-AND-MUSSELS-PHOTO');
        Storage::disk('public')->put('menu-items/1/thumbnails/pancit.webp', 'PANCIT-THUMB');
        Storage::disk('public')->put('menu-items/2/desserts.webp', 'DESSERT-TABLE-PHOTO');
        $item = fn (int $place, string $name, string $description, ?string $image = null) => MenuItem::create([
            'food_beverage_id' => $place, 'name' => $name, 'description' => $description, 'price' => 150, 'status' => 'approved',
            'image_path' => $image, 'thumbnail_path' => $image ? str_replace('/pancit', '/thumbnails/pancit', $image) : null,
        ])->id;
        $this->ids['pancit'] = $item($this->ids['bentornato'], 'Pancit Canton', 'Stir-fried noodles with vegetables.', 'menu-items/1/pancit.webp');
        $this->ids['mussels'] = $item($this->ids['bentornato'], 'Baked Mussels', 'Topped with garlic and cheese.', 'menu-items/2/desserts.webp');
        $this->ids['kangkong'] = $item($this->ids['bentornato'], 'Kangkong with Tofu', 'Water spinach sautéed with fried tofu. (Demo price.)');
        $this->ids['pendingDish'] = $item($this->ids['pending'], 'Iced Latte', 'Espresso over ice. (Demo price.)');
    }

    private function snapshot(): array
    {
        return collect(['destinations', 'accommodations', 'food_beverages', 'menu_items', 'destination_fees'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()])->all();
    }

    public function test_demo_labels_and_notes_go_and_prices_are_kept_as_estimates(): void
    {
        $this->seed(ListingPageCleanupSeeder::class);

        $kangkong = MenuItem::find($this->ids['kangkong']);
        $this->assertSame('Water spinach sautéed with fried tofu.', $kangkong->description);
        $this->assertEquals(150, $kangkong->price, 'The price is kept…');
        $this->assertSame('150', $kangkong->estimate('price'), '…and recorded as an estimate.');
        $this->assertSame('Espresso over ice.', MenuItem::find($this->ids['pendingDish'])->description);
        $this->assertSame('A cosy cafe.', FoodBeverage::find($this->ids['pending'])->description);
        $this->assertSame('pending', FoodBeverage::find($this->ids['pending'])->status, 'Pending listings stay pending.');
        $this->assertNull(MenuItem::find($this->ids['pancit'])->estimate('price'), 'Unlabelled prices are not marked.');
        $this->assertSame(0, MenuItem::where('description', 'like', '%Demo price%')->count());
    }

    public function test_placeholder_contacts_are_cleared_and_the_unconfirmed_real_number_is_kept(): void
    {
        $this->seed(ListingPageCleanupSeeder::class);

        $this->assertNull(FoodBeverage::find($this->ids['bentornato'])->contact_number);
        $this->assertNull(Accommodation::find($this->ids['inn'])->contact_number);
        $this->assertSame('09102969672', FoodBeverage::find($this->ids['realNumber'])->contact_number);
        $this->assertSame('0900 000 0111', FoodBeverage::find($this->ids['pending'])->contact_number, 'Only approved listings were in scope.');
    }

    public function test_the_mussels_get_their_own_copy_of_the_right_photo_and_no_file_is_lost(): void
    {
        $this->seed(ListingPageCleanupSeeder::class);

        $mussels = MenuItem::find($this->ids['mussels']);
        $this->assertNotSame('menu-items/2/desserts.webp', $mussels->image_path);
        $this->assertSame('PANCIT-AND-MUSSELS-PHOTO', Storage::disk('public')->get($mussels->image_path));
        $this->assertSame('PANCIT-THUMB', Storage::disk('public')->get($mussels->thumbnail_path));
        $this->assertSame('menu-items/1/pancit.webp', MenuItem::find($this->ids['pancit'])->image_path, 'The pancit keeps its photo.');
        foreach (['menu-items/1/pancit.webp', 'menu-items/1/thumbnails/pancit.webp', 'menu-items/2/desserts.webp'] as $file) {
            Storage::disk('public')->assertExists($file);
        }
    }

    public function test_the_test_listing_is_unpublished_not_deleted(): void
    {
        $this->seed(ListingPageCleanupSeeder::class);

        $test = FoodBeverage::find($this->ids['test']);
        $this->assertNotNull($test);
        $this->assertSame(['pending', 'draft'], [$test->status, $test->publication_review_status]);
        $this->assertSame('09102969672', $test->contact_number);
        $this->assertSame('approved', FoodBeverage::find($this->ids['bentornato'])->status, 'No other status changes.');
        $this->getJson("/api/v1/food-beverages/{$this->ids['test']}")->assertNotFound();
    }

    public function test_running_it_again_changes_nothing_and_coordinates_never_change(): void
    {
        $coordinates = fn () => collect(['accommodations', 'food_beverages'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get(['id', 'latitude', 'longitude'])->toArray()])->all();
        $before = $coordinates();

        $this->seed(ListingPageCleanupSeeder::class);
        $afterFirst = $this->snapshot();
        $files = Storage::disk('public')->allFiles();
        $this->seed(ListingPageCleanupSeeder::class);

        $this->assertSame($afterFirst, $this->snapshot());
        $this->assertSame($files, Storage::disk('public')->allFiles(), 'No extra copies.');
        $this->assertEquals($before, $coordinates());
        $this->assertSame(1, ListingEstimate::where('field', 'price')->where('estimable_id', $this->ids['kangkong'])->count());
    }

    public function test_the_menu_shows_which_prices_are_estimates(): void
    {
        $this->seed(ListingPageCleanupSeeder::class);

        $menu = collect($this->getJson("/api/v1/food-beverages/{$this->ids['bentornato']}")->assertOk()->json('menu_items'))->keyBy('id');
        $this->assertSame([['id' => $menu[$this->ids['kangkong']]['estimates'][0]['id'], 'field' => 'price', 'value' => '150']], $menu[$this->ids['kangkong']]['estimates']);
        $this->assertSame([], $menu[$this->ids['pancit']]['estimates']);
    }

    public function test_a_fee_list_beats_the_fee_column_and_estimates(): void
    {
        $listed = Destination::create(['name' => 'Listed Cove', 'description' => 'Cove.', 'category_id' => 1, 'municipality_id' => $this->town, 'address' => 'Test St.',
            'latitude' => 12.672, 'longitude' => 123.882, 'operating_hours' => '6:00 AM - 6:00 PM', 'entrance_fee' => null, 'created_by' => $this->tourist->id, 'status' => 'published']);
        foreach ([['Motorcycle Parking', 20], ['Kids (below 7)', 15], ['Adult', 40], ['Overnight', 120]] as $i => [$label, $amount]) {
            DestinationFee::create(['destination_id' => $listed->id, 'label' => $label, 'amount' => $amount, 'sort_order' => $i]);
        }

        $this->seed(ListingEstimateSeeder::class);
        $this->assertSame(0, ListingEstimate::where('estimable_id', $listed->id)->where('field', 'entrance_fee')->count(), 'No estimate next to a real fee list.');

        // Even a conflicting column loses to the list the page shows.
        $listed->update(['entrance_fee' => 99]);
        $reply = ['title' => 'Cove', 'days' => [['day_number' => 1, 'destinations' => [['destination_id' => $listed->id, 'reason' => 'Sea.']]]]];
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($reply)]]]]]])]);
        $start = now()->addDays(3);
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', [
            'municipality_id' => $this->town, 'num_days' => 1, 'interests' => ['nature'], 'travel_pace' => 'moderate', 'budget' => 'budget',
            'travelers' => 2, 'start_date' => $start->toDateString(), 'end_date' => $start->toDateString(),
        ])->assertCreated();

        $visit = Itinerary::latest('id')->firstOrFail()->items->firstWhere('destination_id', $listed->id);
        $this->assertSame('Entrance fee (Adult) × 2', $visit->cost_note);
        $this->assertEquals(80, (float) $visit->cost_max);
        $this->assertEquals(99, (float) $listed->fresh()->entrance_fee, 'The column itself is left alone.');
    }
}

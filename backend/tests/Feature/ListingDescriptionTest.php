<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ListingDescriptionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The rewritten listing descriptions (data file + seeder) and how descriptions reach the
 * itinerary AI.
 */
class ListingDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private int $town;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'test-model', 'services.gemini.fallback_models' => [], 'services.google_maps.api_key' => '']);

        $roleId = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        $this->tourist = User::create(['role_id' => $roleId, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password')]);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Testville', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('categories')->insert(['id' => 2, 'name' => 'Mountain', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function data(): array
    {
        return require database_path('seeders/data/listing_descriptions.php');
    }

    public function test_every_description_is_specific_clean_and_unique(): void
    {
        foreach ($this->data() as $table => $rows) {
            $this->assertNotEmpty($rows, $table);
            $descriptions = array_column($rows, 1);
            $this->assertSame(count($descriptions), count(array_unique($descriptions)), "{$table}: duplicated descriptions.");
            foreach ($rows as $id => [$name, $description]) {
                $this->assertGreaterThanOrEqual(20, str_word_count($description), "{$table} #{$id} is too short.");
                $this->assertDoesNotMatchRegularExpression('/demo|sample data|placeholder|ai-generated|unverified|perfect destination for everyone/i', $description, "{$table} #{$id}");
                $this->assertDoesNotMatchRegularExpression('/\b0?9\d{2}[ -]?\d{3}[ -]?\d{4}\b|https?:\/\/|www\./', $description, "{$table} #{$id} has a contact or link.");
            }
        }
        $this->assertCount(87, $this->data()['destinations']);
    }

    public function test_the_seeder_only_changes_descriptions_on_matching_rows_and_is_repeatable(): void
    {
        [$destName, $destText] = $this->data()['destinations'][3];
        $coords = ['latitude' => 12.6712345, 'longitude' => 123.8812345];
        DB::table('destinations')->insert(['id' => 3, 'name' => $destName, 'description' => 'Old text.', 'category_id' => 2, 'municipality_id' => $this->town, 'address' => 'x',
            'operating_hours' => '6:00 AM - 6:00 PM', 'entrance_fee' => 40, 'status' => 'published', 'created_by' => $this->tourist->id, 'created_at' => now(), 'updated_at' => now()] + $coords);
        // Same id as an entry but a different name: must be left alone.
        DB::table('accommodations')->insert(['id' => 16, 'name' => 'Someone Else Inn', 'description' => 'Keep me.', 'municipality_id' => $this->town, 'address' => 'x',
            'type' => 'Inn', 'price_range' => 'budget', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()] + $coords);
        $before = DB::table('destinations')->where('id', 3)->first();

        $this->seed(ListingDescriptionSeeder::class);
        $after = DB::table('destinations')->where('id', 3)->first();
        $this->seed(ListingDescriptionSeeder::class);

        $this->assertSame($destText, $after->description);
        foreach ((array) $before as $column => $value) {
            if ($column !== 'description') {
                $this->assertSame($value, $after->$column, "destinations.{$column} changed.");
            }
        }
        $this->assertSame('Keep me.', DB::table('accommodations')->where('id', 16)->value('description'));
        $this->assertEquals($after, DB::table('destinations')->where('id', 3)->first(), 'A second run changes nothing.');
    }

    public function test_the_ai_gets_destination_descriptions_and_nearby_places_as_context_only(): void
    {
        $listing = fn (string $name, string $description, float $lat) => [
            'name' => $name, 'description' => $description, 'municipality_id' => $this->town, 'address' => 'x', 'latitude' => $lat, 'longitude' => 123.88,
        ];
        $hill = Destination::create($listing('Lookout Hill', 'Lookout Hill is a grassy ridge with sunrise views. Bring water for the climb.', 12.672)
            + ['category_id' => 2, 'operating_hours' => '6:00 AM - 6:00 PM', 'created_by' => $this->tourist->id, 'status' => 'published']);
        Accommodation::create($listing('Harbor Inn', 'Harbor Inn is a small inn by the pier with air-conditioned rooms.', 12.670) + ['type' => 'Inn', 'price_range' => 'budget', 'status' => 'approved']);
        FoodBeverage::create($listing('Lola Grill', 'Lola Grill serves Bicolano grilled dishes by the bay.', 12.671) + ['cuisine_type' => 'Filipino', 'price_range' => 'budget', 'status' => 'approved']);
        $shop = RetailShop::create($listing('Pili Corner', 'Pili Corner sells pili nut pasalubong and native crafts.', 12.6705) + ['status' => 'approved']);
        RetailShop::create($listing('Hidden Shop', 'Pasalubong shop still waiting for approval.', 12.6706) + ['status' => 'pending']);

        $reply = ['title' => 'Hill', 'days' => [['day_number' => 1, 'destinations' => [['destination_id' => $hill->id, 'reason' => 'Views.']]]]];
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($reply)]]]]]])]);
        $start = now()->addDays(3);
        $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', [
            'municipality_id' => $this->town, 'num_days' => 1, 'interests' => ['nature', 'shopping'], 'travel_pace' => 'moderate', 'budget' => 'budget',
            'start_date' => $start->toDateString(), 'end_date' => $start->toDateString(),
        ])->assertCreated();

        $prompt = Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage'))->last()[0]->data()['contents'][0]['parts'][0]['text'];
        $this->assertMatchesRegularExpression('/ID:'.$hill->id.' \| Lookout Hill[^\n]*\| About: Lookout Hill is a grassy ridge with sunrise views\./', $prompt);
        $this->assertStringContainsString('OTHER VERIFIED PLACES NEARBY (context only', $prompt);
        $this->assertStringContainsString('- Harbor Inn (Testville; Inn, budget): Harbor Inn is a small inn by the pier', $prompt);
        $this->assertStringContainsString('- Lola Grill (Testville; Filipino, budget): Lola Grill serves Bicolano grilled dishes', $prompt);
        $this->assertStringContainsString('- Pili Corner (Testville; souvenirs): Pili Corner sells pili nut pasalubong', $prompt);
        $this->assertStringNotContainsString('Hidden Shop', $prompt, 'Unapproved listings never reach the AI.');

        // The shop has no product records, yet it is the day's souvenir stop.
        $this->assertSame(0, $shop->products()->count());
        $items = Itinerary::latest('id')->firstOrFail()->items;
        $this->assertTrue($items->contains('retail_shop_id', $shop->id));
        $this->assertSame([$hill->id], $items->pluck('destination_id')->filter()->values()->all(), 'Only real destinations are visited.');
    }
}

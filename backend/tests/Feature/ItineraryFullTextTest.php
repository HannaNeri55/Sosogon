<?php

namespace Tests\Feature;

use App\Contracts\ItineraryAI;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\Role;
use App\Models\User;
use App\Services\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What the tourist reads in a generated itinerary is never cut off mid-sentence: a place's
 * dining details reach the "Food:" reminder in full (hours are often at the end), and an
 * overlong reason from the AI ends at its last whole sentence, never with "...".
 */
class ItineraryFullTextTest extends TestCase
{
    use RefreshDatabase;

    private const DINING = 'A family-run canteen beside the cove serves grilled fish, pinangat and fresh coconut juice, '
        .'with set meals for groups that should be ordered on arrival so they are ready after a swim. Open from 7:00 AM to 9:00 PM daily.';

    public function test_dining_details_and_long_reasons_are_never_cut_mid_sentence(): void
    {
        config(['services.google_maps.api_key' => '', 'services.itinerary.daily_limit' => 1000]);
        Http::preventStrayRequests();
        $role = Role::create(['name' => 'tourist', 'description' => 'Tourist']);
        $tourist = User::create(['role_id' => $role->id, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password')]);
        $town = DB::table('municipalities')->insertGetId(['name' => 'Covetown', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('categories')->insert(['id' => 1, 'name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $cove = Destination::create([
            'name' => 'Long Cove', 'description' => 'Long Cove is a test listing.', 'category_id' => 1, 'municipality_id' => $town,
            'address' => 'Test St.', 'latitude' => 12.67, 'longitude' => 123.88, 'operating_hours' => '6:00 AM - 6:00 PM',
            'dining_details' => self::DINING, 'created_by' => $tourist->id, 'status' => 'published',
        ])->id;
        DB::table('activities')->insert(['destination_id' => $cove, 'name' => 'Swimming', 'type' => 'outdoor', 'created_at' => now(), 'updated_at' => now()]);

        // Eleven sentences of about 50 characters: over the 500-character cap.
        $reason = str_repeat('The water is calm and clear for a long swim here. ', 11);
        $this->app->instance(ItineraryAI::class, new class($cove, $reason) implements ItineraryAI
        {
            public function __construct(private int $cove, private string $reason) {}

            public function generate(string $prompt): string
            {
                return json_encode(['title' => 'Cove trip', 'days' => [['day_number' => 1, 'destinations' => [['destination_id' => $this->cove, 'reason' => $this->reason]]]]]);
            }
        });
        $this->app->forgetInstance(ItineraryService::class);

        $start = now()->addDays(3)->toDateString();
        $id = $this->actingAs($tourist)->postJson('/api/v1/itineraries/generate', [
            'municipality_ids' => [$town], 'num_days' => 1, 'interests' => ['beaches'], 'accommodation_needed' => false,
            'start_date' => $start, 'end_date' => $start,
        ])->assertCreated()->json('itinerary.id');
        $stop = Itinerary::with('items')->find($id)->items->firstWhere('destination_id', $cove);

        $this->assertStringContainsString('Food: '.self::DINING, (string) $stop->preference_note, 'In full, hours included.');
        $this->assertStringNotContainsString('...', (string) $stop->reason);
        $this->assertStringEndsWith('long swim here.', (string) $stop->reason, 'Ends at a whole sentence.');
        $this->assertLessThanOrEqual(500, mb_strlen((string) $stop->reason));
    }
}

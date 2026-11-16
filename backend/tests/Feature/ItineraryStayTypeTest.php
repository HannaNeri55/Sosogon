<?php

namespace Tests\Feature;

use App\Contracts\ItineraryAI;
use App\Models\Accommodation;
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
 * The stay type the tourist asks for is matched by each stay's listed categories, before
 * distance. Baytown's stays, nearest to the day's places first (all moderately priced):
 * - Lagoon Resort (Resort), 0.2 km
 * - Bay Hotel and Resort (listed only as a Hotel, with a pool and garden), 0.3 km
 * - Bay Inn (Inn), 0.4 km
 * - Garden Hotel & Resort (Hotel + Resort), 0.5 km
 * - City Pool Hotel (Hotel, with a pool), about 6 km
 */
class ItineraryStayTypeTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private int $town;

    private array $ids = [];

    private object $ai;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_maps.api_key' => '', 'services.itinerary.daily_limit' => 1000]);
        Http::preventStrayRequests();
        $this->ai = new class implements ItineraryAI
        {
            public array $prompts = [];

            public array $reply = [];

            public function generate(string $prompt): string
            {
                $this->prompts[] = $prompt;

                return json_encode(['title' => 'Bay trip', 'days' => $this->reply]);
            }
        };
        $this->app->instance(ItineraryAI::class, $this->ai);
        $this->app->forgetInstance(ItineraryService::class);

        $role = Role::create(['name' => 'tourist', 'description' => 'Tourist']);
        $this->tourist = User::create(['role_id' => $role->id, 'name' => 'Test Tourist', 'email' => 'tourist@example.com', 'password' => bcrypt('password')]);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Baytown', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([1 => 'Beach', 6 => 'Park'] as $id => $name) {
            DB::table('categories')->insert(['id' => $id, 'name' => $name, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->ids['park'] = $this->destination('Bay Park', 6, 12.670, 123.880, 'Strolling');
        $this->ids['beach'] = $this->destination('Bay Beach', 1, 12.670, 123.8805, 'Swimming');
        $this->stay('resortOnly', 'Lagoon Resort', ['Resort'], 12.6718, ['Swimming Pool', 'Beach Access']);
        $this->stay('namedResort', 'Bay Hotel and Resort', ['Hotel'], 12.6727, ['Swimming Pool', 'Garden', 'Air Conditioning'],
            'Bay Hotel and Resort combines hotel rooms with a resort pool and garden.');
        $this->stay('inn', 'Bay Inn', ['Inn'], 12.6736, ['Free Wi-Fi']);
        $this->stay('hotelResort', 'Garden Hotel & Resort', ['Hotel', 'Resort'], 12.6745, ['Swimming Pool', 'Restaurant']);
        $this->stay('poolHotel', 'City Pool Hotel', ['Hotel'], 12.724, ['Swimming Pool', '24-Hour Front Desk', 'Restaurant']);

        $this->ai->reply = [
            ['day_number' => 1, 'title' => 'Park', 'destinations' => [['destination_id' => $this->ids['park'], 'reason' => 'A stroll.']]],
            ['day_number' => 2, 'title' => 'Beach', 'destinations' => [['destination_id' => $this->ids['beach'], 'reason' => 'A swim.']]],
        ];
    }

    private function destination(string $name, int $category, float $lat, float $lng, string $activity): int
    {
        $id = Destination::create([
            'name' => $name, 'description' => "{$name} is a test listing.", 'category_id' => $category, 'municipality_id' => $this->town,
            'address' => 'Test St.', 'latitude' => $lat, 'longitude' => $lng, 'operating_hours' => '6:00 AM - 6:00 PM',
            'created_by' => $this->tourist->id, 'status' => 'published',
        ])->id;
        DB::table('activities')->insert(['destination_id' => $id, 'name' => $activity, 'type' => 'outdoor', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function stay(string $key, string $name, array $categories, float $lat, array $amenities, ?string $description = null): void
    {
        $this->ids[$key] = Accommodation::create([
            'name' => $name, 'description' => $description ?? "{$name} is a test listing.", 'municipality_id' => $this->town, 'address' => 'Test St.',
            'latitude' => $lat, 'longitude' => 123.880, 'type' => $categories[0], 'classifications' => $categories, 'amenities' => $amenities,
            'price_range' => 'moderate', 'status' => 'approved',
        ])->id;
    }

    private function generate(?string $type): Itinerary
    {
        $start = now()->addDays(3);
        $id = $this->actingAs($this->tourist)->postJson('/api/v1/itineraries/generate', array_filter([
            'municipality_ids' => [$this->town], 'num_days' => 2, 'interests' => ['nature', 'relaxation'], 'travel_pace' => 'moderate',
            'budget' => 'moderate', 'travelers' => 2, 'accommodation_needed' => true, 'accommodation_type' => $type,
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDay()->toDateString(),
        ], fn ($v) => $v !== null))->assertCreated()->json('itinerary.id');

        return Itinerary::find($id);
    }

    private function draft(string ...$keys): void
    {
        Accommodation::whereIn('id', array_map(fn ($key) => $this->ids[$key], $keys))->update(['status' => 'draft']);
    }

    private function match(?string $type, string $key): ?string
    {
        return (new \ReflectionMethod(ItineraryService::class, 'stayMatch'))
            ->invoke(app(ItineraryService::class), ['accommodation_type' => $type], Accommodation::find($this->ids[$key]));
    }

    public function test_a_hotel_preference_gets_an_actual_hotel_even_a_farther_one(): void
    {
        $itinerary = $this->generate('hotel');

        // Not the nearer resort, Hotel & Resort or hotel-named-resort: the hotel, pool and all.
        $this->assertSame($this->ids['poolHotel'], (int) $itinerary->accommodation_id);
        $this->assertNull($itinerary->accommodation_note, 'An exact match needs no apology.');
    }

    public function test_hotel_matching_is_strict(): void
    {
        $this->assertSame('exact', $this->match('hotel', 'poolHotel'), 'A pool alone does not make a hotel a resort.');
        $this->assertSame('close', $this->match('hotel', 'hotelResort'), 'A Hotel & Resort is an alternative, not a hotel.');
        $this->assertSame('close', $this->match('hotel', 'namedResort'), 'Listed as a hotel, but its own name says resort.');
        $this->assertNull($this->match('hotel', 'resortOnly'));
        $this->assertNull($this->match('hotel', 'inn'));
    }

    public function test_a_resort_preference_never_takes_an_ordinary_hotel_as_a_resort(): void
    {
        $this->assertSame('exact', $this->match('resort', 'resortOnly'));
        $this->assertSame('exact', $this->match('resort', 'hotelResort'), 'A Hotel & Resort is a resort too.');
        $this->assertNull($this->match('resort', 'poolHotel'), 'A hotel with a pool is not a resort.');
        $this->assertSame($this->ids['resortOnly'], (int) $this->generate('resort')->accommodation_id);

        // With the resort gone, the Hotel & Resort is an exact match.
        $this->draft('resortOnly');
        $itinerary = $this->generate('resort');
        $this->assertSame($this->ids['hotelResort'], (int) $itinerary->accommodation_id);
        $this->assertNull($itinerary->accommodation_note);
    }

    public function test_a_hotel_and_resort_preference_gets_a_place_that_is_both(): void
    {
        $this->assertSame('exact', $this->match('hotel_resort', 'hotelResort'));
        $this->assertSame('close', $this->match('hotel_resort', 'resortOnly'));
        $this->assertSame('close', $this->match('hotel_resort', 'poolHotel'));

        $itinerary = $this->generate('hotel_resort');
        $this->assertSame($this->ids['hotelResort'], (int) $itinerary->accommodation_id, 'Over the nearer resort-only and hotel-only stays.');
        $this->assertNull($itinerary->accommodation_note);
    }

    public function test_without_a_hotel_the_alternative_is_said_to_be_one(): void
    {
        $this->draft('poolHotel');
        $itinerary = $this->generate('hotel');
        $note = (string) $itinerary->accommodation_note;

        // The nearest close kind, never the resort-only or the inn, and the note says what it is.
        $this->assertSame($this->ids['namedResort'], (int) $itinerary->accommodation_id);
        $this->assertStringContainsString('None of the verified stays in Baytown is an exact match for a Hotel.', $note);
        $this->assertStringContainsString("It's the closest alternative to a Hotel, not an exact match: it is listed as a hotel, but its name says it is also a resort", $note);

        $this->draft('namedResort');
        $note = (string) $this->generate('hotel')->accommodation_note;
        $this->assertStringContainsString('Garden Hotel & Resort instead: a Hotel & Resort (moderate price)', $note);
        $this->assertStringContainsString('resort facilities are part of the stay', $note);
    }

    public function test_without_any_related_kind_another_stay_is_offered_and_labelled(): void
    {
        $this->draft('poolHotel', 'namedResort', 'hotelResort');
        $note = (string) $this->generate('hotel')->accommodation_note;

        $this->assertStringContainsString('None of the verified stays in Baytown is listed as a Hotel yet.', $note);
        $this->assertStringContainsString('Lagoon Resort instead: a Resort (moderate price)', $note);
        $this->assertStringNotContainsString('closest alternative', $note, 'Only a related kind is called an alternative.');
    }

    public function test_a_stay_without_a_listed_type_is_never_an_exact_match(): void
    {
        $unknown = Accommodation::create([
            'name' => 'Mystery Lodging', 'description' => 'A test listing.', 'municipality_id' => $this->town, 'address' => 'Test St.',
            'latitude' => 12.670, 'longitude' => 123.880, 'type' => 'Accommodation', 'price_range' => 'moderate', 'status' => 'approved',
        ]);
        $call = fn (?string $type) => (new \ReflectionMethod(ItineraryService::class, 'stayMatch'))->invoke(app(ItineraryService::class), ['accommodation_type' => $type], $unknown);

        foreach (['hotel', 'resort', 'hotel_resort', 'homestay', 'budget'] as $type) {
            $this->assertNull($call($type), $type);
        }
        $this->assertSame('exact', $call(null), 'Any stay suits a tourist with no preference.');
        $this->assertSame('exact', $call('no_preference'));
    }

    public function test_no_preference_still_takes_the_nearest_stay(): void
    {
        $this->assertSame($this->ids['resortOnly'], (int) $this->generate('no_preference')->accommodation_id);
    }

    public function test_the_ai_sees_each_stay_by_its_listed_kind(): void
    {
        $this->generate('hotel_resort');

        $this->assertStringContainsString('- Garden Hotel & Resort (Baytown; Hotel & Resort, moderate)', $this->ai->prompts[0]);
        $this->assertStringContainsString('- City Pool Hotel (Baytown; Hotel, moderate)', $this->ai->prompts[0]);
    }
}

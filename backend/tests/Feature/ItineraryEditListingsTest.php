<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\Destination;
use App\Models\MenuItem;
use App\Models\RetailProduct;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItineraryEditListingsTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private Itinerary $itinerary;

    private int $bulan;

    private int $otherTown;

    protected function setUp(): void
    {
        parent::setUp();

        $roleId = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        $this->tourist = User::create([
            'role_id' => $roleId,
            'name' => 'Test Tourist',
            'email' => 'tourist@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->bulan = $this->municipality('Bulan');
        $this->otherTown = $this->municipality('Irosin');

        $this->itinerary = Itinerary::create([
            'user_id' => $this->tourist->id,
            'municipality_id' => $this->bulan,
            'title' => 'Test trip',
            'num_days' => 2,
            'interests' => ['nature'],
            'travel_pace' => 'moderate',
            'budget' => 'moderate',
        ]);
    }

    private function municipality(string $name): int
    {
        $id = DB::table('municipalities')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('barangays')->insert(['municipality_id' => $id, 'name' => 'Zone 1', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function listingFields(int $municipalityId, string $name): array
    {
        return [
            'name' => $name,
            'description' => 'A listing used for automated testing.',
            'municipality_id' => $municipalityId,
            'barangay_id' => DB::table('barangays')->where('municipality_id', $municipalityId)->value('id'),
            'address' => 'Test St.',
            'latitude' => 12.67,
            'longitude' => 123.87,
        ];
    }

    private function food(int $municipalityId, string $name, string $status = 'approved'): FoodBeverage
    {
        return FoodBeverage::create($this->listingFields($municipalityId, $name) + ['price_range' => 'budget', 'status' => $status]);
    }

    public function test_eligible_listings_only_include_live_places_in_the_itinerary_municipality(): void
    {
        $categoryId = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        Destination::create($this->listingFields($this->bulan, 'Bulan Beach') + ['category_id' => $categoryId, 'created_by' => $this->tourist->id]);
        Destination::create($this->listingFields($this->otherTown, 'Irosin Falls') + ['category_id' => $categoryId, 'created_by' => $this->tourist->id]);
        $this->food($this->bulan, 'Bulan Eatery');
        $this->food($this->bulan, 'Pending Eatery', 'pending');
        $this->food($this->otherTown, 'Irosin Eatery');
        RetailShop::create($this->listingFields($this->bulan, 'Bulan Pasalubong') + ['status' => 'approved']);

        $response = $this->actingAs($this->tourist)->getJson("/api/v1/itineraries/{$this->itinerary->id}/eligible-listings");

        $response->assertOk()->assertJsonStructure(['destinations', 'accommodations', 'food_beverages', 'shops']);
        $this->assertSame(['Bulan Beach'], array_column($response->json('destinations'), 'name'));
        $this->assertSame(['Bulan Eatery'], array_column($response->json('food_beverages'), 'name'));
        $this->assertSame(['Bulan Pasalubong'], array_column($response->json('shops'), 'name'));
        $this->assertSame([], $response->json('accommodations'));
    }

    public function test_tourist_can_add_a_food_stop_from_the_same_municipality(): void
    {
        $food = $this->food($this->bulan, 'Bulan Eatery');

        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$this->itinerary->id}", [
            'new_items' => [[
                'day_number' => 1, 'sequence' => 1, 'item_type' => 'food_beverage', 'listing_id' => $food->id, 'time_slot' => '12:00 PM',
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('itinerary_items', [
            'itinerary_id' => $this->itinerary->id,
            'item_type' => 'food_beverage',
            'food_beverage_id' => $food->id,
            'destination_id' => null,
        ]);
    }

    private function stop(FoodBeverage $food, int $sequence): ItineraryItem
    {
        return ItineraryItem::create([
            'itinerary_id' => $this->itinerary->id, 'item_type' => 'food_beverage', 'food_beverage_id' => $food->id,
            'day_number' => 1, 'sequence' => $sequence, 'time_slot' => '12:00 PM – 1:00 PM', 'reason' => 'AI pick',
        ]);
    }

    public function test_swapping_a_stop_recalculates_it_and_never_repeats_a_place(): void
    {
        $first = $this->food($this->bulan, 'First Eatery');
        $second = $this->food($this->bulan, 'Second Eatery');
        $third = FoodBeverage::create(['latitude' => 12.70] + $this->listingFields($this->bulan, 'Third Eatery') + ['price_range' => 'moderate', 'status' => 'approved']);
        $lunch = $this->stop($first, 1);
        $dinner = $this->stop($second, 2);
        $url = "/api/v1/itineraries/{$this->itinerary->id}";

        $this->actingAs($this->tourist)->putJson($url, ['edit_items' => [['id' => $lunch->id, 'listing_id' => $second->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('edit_items');

        $this->actingAs($this->tourist)->putJson($url, ['edit_items' => [['id' => $lunch->id, 'listing_id' => $third->id]]])->assertOk();

        $lunch->refresh();
        $this->assertSame($third->id, $lunch->food_beverage_id);
        $this->assertSame('You picked this place.', $lunch->reason);
        $this->assertSame('Est. per person', $lunch->cost_note);
        $this->assertGreaterThan(0, (float) $dinner->refresh()->distance_from_prev_km, 'The next stop is measured from the new place.');
    }

    public function test_times_notes_and_activities_can_be_edited(): void
    {
        $stop = $this->stop($this->food($this->bulan, 'Bulan Eatery'), 1);
        $activities = [
            ['time' => '12:00 PM – 12:30 PM', 'name' => 'Try the house specialty', 'description' => null],
            ['time' => '12:30 PM – 1:30 PM', 'name' => 'Dessert by the window', 'description' => 'Halo-halo'],
        ];

        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$this->itinerary->id}", ['edit_items' => [[
            'id' => $stop->id, 'time_slot' => '12:00 PM – 1:30 PM', 'notes' => 'Book a table.', 'activities' => $activities,
        ]]])->assertOk();

        $stop->refresh();
        $this->assertSame('12:00 PM – 1:30 PM', $stop->time_slot);
        $this->assertSame('Book a table.', $stop->notes);
        $this->assertSame($activities, $stop->activities);
    }

    private function menu(FoodBeverage $place, array $prices): array
    {
        return array_map(fn ($price) => MenuItem::create([
            'food_beverage_id' => $place->id, 'name' => "Dish {$price}", 'price' => $price, 'status' => 'approved',
        ]), $prices);
    }

    public function test_dish_souvenir_and_room_edits_update_the_budget(): void
    {
        $stay = Accommodation::create($this->listingFields($this->bulan, 'Bulan Inn') + ['type' => 'Inn', 'price_range' => 'budget', 'status' => 'approved']);
        $standard = AccommodationRoom::create(['accommodation_id' => $stay->id, 'room_number' => '1', 'category' => 'Standard', 'price' => 1500, 'status' => 'approved']);
        $deluxe = AccommodationRoom::create(['accommodation_id' => $stay->id, 'room_number' => '2', 'category' => 'Deluxe', 'price' => 2500, 'status' => 'approved']);
        $this->itinerary->update(['accommodation_id' => $stay->id]);

        $food = $this->food($this->bulan, 'Bulan Eatery');
        [$a, , , $d] = $this->menu($food, [100, 200, 300, 400]);
        $shop = RetailShop::create($this->listingFields($this->bulan, 'Bulan Pasalubong') + ['status' => 'approved']);
        [$p1, $p2] = array_map(fn ($price) => RetailProduct::create([
            'retail_shop_id' => $shop->id, 'name' => "Souvenir {$price}", 'description' => 'Test souvenir.',
            'product_type' => 'Souvenir', 'municipality_id' => $this->bulan, 'price' => $price, 'status' => 'approved',
        ]), [50, 70]);
        $foodStop = $this->stop($food, 1);
        $shopStop = ItineraryItem::create([
            'itinerary_id' => $this->itinerary->id, 'item_type' => 'retail_shop', 'retail_shop_id' => $shop->id, 'day_number' => 1, 'sequence' => 2,
        ]);

        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$this->itinerary->id}", [
            'edit_items' => [
                ['id' => $foodStop->id, 'dishes' => [['id' => $a->id, 'quantity' => 3], ['id' => $d->id, 'quantity' => 1]]],
                ['id' => $shopStop->id, 'souvenirs' => [['id' => $p1->id, 'quantity' => 1], ['id' => $p2->id, 'quantity' => 2]]],
            ],
            'room_ids' => [$standard->id, $deluxe->id],
        ])->assertOk();

        $this->assertEquals(3 * 100 + 400, (float) $foodStop->refresh()->cost_max);
        $this->assertEquals(50 + 2 * 70, (float) $shopStop->refresh()->cost_max);
        $itinerary = $this->itinerary->refresh();
        $this->assertEquals(1500 + 2500, (float) $itinerary->accommodation_cost_max, '2 days = 1 night.');
        $this->assertEquals(700 + 190 + 4000, (float) $itinerary->estimated_cost_max);
    }

    public function test_swapping_a_food_place_recommends_its_dishes_and_foreign_dishes_are_rejected(): void
    {
        $first = $this->food($this->bulan, 'First Eatery');
        $second = $this->food($this->bulan, 'Second Eatery');
        [$foreign] = $this->menu($first, [90]);
        $this->menu($second, [100, 200, 300, 400, 500]);
        $stop = $this->stop($first, 1);
        $url = "/api/v1/itineraries/{$this->itinerary->id}";

        $this->actingAs($this->tourist)->putJson($url, ['edit_items' => [['id' => $stop->id, 'listing_id' => $second->id]]])->assertOk();

        // Moderate budget: the 3 dishes around the median price.
        $this->assertEqualsCanonicalizing([200.0, 300.0, 400.0], $stop->refresh()->dishes->pluck('price')->map(fn ($p) => (float) $p)->all());
        $this->assertEquals(900, (float) $stop->cost_max);

        $this->actingAs($this->tourist)->putJson($url, ['edit_items' => [['id' => $stop->id, 'dishes' => [['id' => $foreign->id, 'quantity' => 1]]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('edit_items.0.dishes');
    }

    public function test_stop_from_another_municipality_is_rejected(): void
    {
        $food = $this->food($this->otherTown, 'Irosin Eatery');

        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$this->itinerary->id}", [
            'new_items' => [[
                'day_number' => 1, 'sequence' => 1, 'item_type' => 'food_beverage', 'listing_id' => $food->id,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('new_items.0.listing_id');

        $this->assertDatabaseCount('itinerary_items', 0);
    }

    public function test_places_from_every_selected_town_can_be_added(): void
    {
        $this->itinerary->municipalities()->attach([$this->bulan, $this->otherTown]);
        $food = $this->food($this->otherTown, 'Irosin Eatery');

        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$this->itinerary->id}", [
            'new_items' => [['day_number' => 1, 'sequence' => 1, 'item_type' => 'food_beverage', 'listing_id' => $food->id]],
        ])->assertOk();

        $names = array_column($this->actingAs($this->tourist)
            ->getJson("/api/v1/itineraries/{$this->itinerary->id}/eligible-listings")->json('food_beverages'), 'name');
        $this->assertSame(['Irosin Eatery'], $names);
    }

    /** A destination with three listed activities. */
    private function destinationWithActivities(string $name, ?int $town = null): Destination
    {
        $categoryId = DB::table('categories')->value('id')
            ?? DB::table('categories')->insertGetId(['name' => 'Nature', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $destination = Destination::create($this->listingFields($town ?? $this->bulan, $name) + ['category_id' => $categoryId, 'created_by' => $this->tourist->id, 'status' => 'published']);
        foreach (['Nature walk', 'Photo stop', 'Swimming'] as $activity) {
            DB::table('activities')->insert([
                'destination_id' => $destination->id, 'name' => "{$activity} at {$name}", 'best_time_to_visit' => 'Anytime',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $destination;
    }

    private function visit(Destination $destination, string $timeSlot): ItineraryItem
    {
        return ItineraryItem::create([
            'itinerary_id' => $this->itinerary->id, 'item_type' => 'destination', 'destination_id' => $destination->id,
            'day_number' => 1, 'sequence' => 1, 'time_slot' => $timeSlot, 'reason' => 'AI pick',
        ]);
    }

    public function test_a_second_destination_is_placed_by_time_with_its_listed_activities(): void
    {
        $morning = $this->visit($this->destinationWithActivities('Hill'), '8:00 AM – 12:00 PM');
        $beach = $this->destinationWithActivities('Beach');

        // Sent first, but it's later in the day.
        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$this->itinerary->id}", [
            'new_items' => [['day_number' => 1, 'sequence' => 1, 'item_type' => 'destination', 'listing_id' => $beach->id, 'time_slot' => '1:00 PM – 4:00 PM']],
        ])->assertOk();

        $added = ItineraryItem::where('destination_id', $beach->id)->firstOrFail();
        $this->assertSame(1, $morning->refresh()->sequence);
        $this->assertSame(2, $added->sequence);
        $this->assertEqualsCanonicalizing(
            ['Nature walk at Beach', 'Photo stop at Beach', 'Swimming at Beach'],
            collect($added->activities)->pluck('name')->reject(fn ($n) => str_starts_with($n, 'Free time'))->values()->all()
        );
    }

    public function test_retiming_a_destination_lays_out_its_activities_again(): void
    {
        $visit = $this->visit($this->destinationWithActivities('Hill'), '8:00 AM – 12:00 PM');

        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$this->itinerary->id}", [
            'edit_items' => [['id' => $visit->id, 'time_slot' => '2:00 PM – 5:00 PM']],
        ])->assertOk();

        $first = collect($visit->refresh()->activities)->first();
        $this->assertStringStartsWith('2:00 PM', $first['time']);
    }

    public function test_overlapping_destinations_are_rejected(): void
    {
        $this->visit($this->destinationWithActivities('Hill'), '8:00 AM – 12:00 PM');
        $beach = $this->destinationWithActivities('Beach');

        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$this->itinerary->id}", [
            'new_items' => [['day_number' => 1, 'sequence' => 2, 'item_type' => 'destination', 'listing_id' => $beach->id, 'time_slot' => '11:00 AM – 2:00 PM']],
        ])->assertUnprocessable()->assertJsonValidationErrors('edit_items');

        $this->assertDatabaseMissing('itinerary_items', ['destination_id' => $beach->id]);
    }

    public function test_a_lunch_stop_may_sit_inside_a_visit(): void
    {
        $this->visit($this->destinationWithActivities('Hill'), '8:00 AM – 4:00 PM');
        $food = $this->food($this->bulan, 'Hill Eatery');

        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$this->itinerary->id}", [
            'new_items' => [['day_number' => 1, 'sequence' => 2, 'item_type' => 'food_beverage', 'listing_id' => $food->id, 'time_slot' => '12:00 PM – 1:00 PM']],
        ])->assertOk();
    }

    public function test_a_day_stays_in_one_municipality(): void
    {
        $this->itinerary->municipalities()->attach([$this->bulan, $this->otherTown]);
        $this->visit($this->destinationWithActivities('Hill'), '8:00 AM – 11:00 AM');
        $falls = $this->destinationWithActivities('Falls', $this->otherTown);

        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$this->itinerary->id}", [
            'new_items' => [['day_number' => 1, 'sequence' => 2, 'item_type' => 'destination', 'listing_id' => $falls->id, 'time_slot' => '1:00 PM – 4:00 PM']],
        ])->assertUnprocessable()->assertJsonValidationErrors('new_items');

        // The same place on a day of its own is fine.
        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$this->itinerary->id}", [
            'new_items' => [['day_number' => 2, 'sequence' => 1, 'item_type' => 'destination', 'listing_id' => $falls->id, 'time_slot' => '9:00 AM – 12:00 PM']],
        ])->assertOk();
    }

    public function test_a_destination_needs_a_time_range(): void
    {
        $beach = $this->destinationWithActivities('Beach');

        $this->actingAs($this->tourist)->putJson("/api/v1/itineraries/{$this->itinerary->id}", [
            'new_items' => [['day_number' => 1, 'sequence' => 1, 'item_type' => 'destination', 'listing_id' => $beach->id, 'time_slot' => '']],
        ])->assertUnprocessable()->assertJsonValidationErrors('new_items.0.time_slot');
    }
}

<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Tourist\ItineraryController;
use App\Models\Destination;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Role;
use App\Models\User;
use App\Services\ItineraryDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ItinerarySaveShareTest extends TestCase
{
    use RefreshDatabase;

    private User $tourist;

    private User $stranger;

    private Itinerary $itinerary;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $roleId = Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id;
        $this->tourist = $this->user($roleId, 'tourist@example.com');
        $this->stranger = $this->user($roleId, 'stranger@example.com');

        $town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $this->categoryId = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $this->itinerary = Itinerary::create([
            'user_id' => $this->tourist->id,
            'municipality_id' => $town,
            'title' => 'Beach weekend',
            'num_days' => 2,
            'interests' => ['nature'],
            'travel_pace' => 'moderate',
            'budget' => 'moderate',
            'start_date' => '2026-12-05',
            'end_date' => '2026-12-06',
            'additional_preferences' => 'My mother uses a wheelchair',
            'ai_response_raw' => 'raw model output',
            'total_distance_km' => 12.5,
        ]);

        $this->item($this->destination('Paguriran Lagoon', $town, 12.70, 123.90), 1, '8:00 AM – 10:00 AM', null, null);
        $this->item($this->destination('Dancalan Beach', $town, 12.72, 123.92), 2, '11:00 AM – 1:00 PM', 7.5, 25);
    }

    private function user(int $roleId, string $email): User
    {
        return User::create(['role_id' => $roleId, 'name' => 'Person', 'email' => $email, 'password' => bcrypt('password')]);
    }

    private function destination(string $name, int $town, float $lat, float $lng): Destination
    {
        return Destination::forceCreate([
            'name' => $name,
            'description' => "{$name} is a place to visit.",
            'municipality_id' => $town,
            'category_id' => $this->categoryId,
            'address' => 'Bulan, Sorsogon',
            'created_by' => $this->tourist->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'status' => 'published',
        ]);
    }

    private function item(Destination $place, int $sequence, string $slot, ?float $km, ?int $minutes): void
    {
        ItineraryItem::create([
            'itinerary_id' => $this->itinerary->id,
            'item_type' => 'destination',
            'destination_id' => $place->id,
            'day_number' => 1,
            'sequence' => $sequence,
            'time_slot' => $slot,
            'distance_from_prev_km' => $km,
            'travel_time_from_prev' => $minutes,
            'activities' => [['time' => $slot, 'name' => 'Swim at the shore', 'description' => 'Bring water shoes']],
        ]);
    }

    private function url(string $path = ''): string
    {
        return "/api/v1/itineraries/{$this->itinerary->id}{$path}";
    }

    private function shareLink(): string
    {
        return $this->actingAs($this->tourist)->postJson($this->url('/share'))->assertOk()->json('share_token');
    }

    public function test_saving_twice_keeps_one_record_and_the_first_saved_time(): void
    {
        $count = Itinerary::count();
        $this->travelTo('2026-11-09 08:00:00');
        $first = $this->actingAs($this->tourist)->postJson($this->url('/save'))->assertOk()->json('saved_at');

        $this->travelTo('2026-11-09 09:00:00');
        $second = $this->actingAs($this->tourist)->postJson($this->url('/save'))->assertOk()->json('saved_at');

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $this->assertSame($count, Itinerary::count());
    }

    public function test_a_saved_itinerary_reopens_from_the_account(): void
    {
        $this->actingAs($this->tourist)->postJson($this->url('/save'))->assertOk();

        $this->actingAs($this->tourist)->getJson('/api/v1/itineraries')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->itinerary->id)
            ->assertJsonMissingPath('data.0.share_token');

        $this->actingAs($this->tourist)->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'items');
    }

    public function test_only_the_owner_can_save_print_or_share(): void
    {
        foreach ([['post', '/save'], ['get', '/document'], ['get', '/pdf'], ['get', '/share'], ['post', '/share'], ['delete', '/share']] as [$method, $path]) {
            $this->actingAs($this->stranger)->{$method.'Json'}($this->url($path))->assertNotFound();
        }

        $this->assertNull($this->itinerary->fresh()->saved_at);
        $this->assertNull($this->itinerary->fresh()->share_token);
    }

    public function test_guests_cannot_reach_the_owner_actions(): void
    {
        $this->postJson($this->url('/save'))->assertUnauthorized();
        $this->getJson($this->url('/pdf'))->assertUnauthorized();
    }

    public function test_the_document_has_the_whole_plan_and_no_site_interface(): void
    {
        $html = $this->actingAs($this->tourist)->getJson($this->url('/document'))->assertOk()->json('html');

        foreach (['SOSOGON', 'Beach weekend', 'December 5, 2026', 'Day 1', 'Paguriran Lagoon', 'Dancalan Beach', '8:00 AM – 10:00 AM', 'Swim at the shore', '7.5 km apart (straight line)', 'google.com/maps/dir', 'My mother uses a wheelchair'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringNotContainsString('<nav', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_the_document_escapes_what_a_tourist_typed(): void
    {
        $this->itinerary->update(['title' => '<script>alert(1)</script>']);

        $html = $this->actingAs($this->tourist)->getJson($this->url('/document'))->assertOk()->json('html');

        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_the_pdf_downloads(): void
    {
        $response = $this->actingAs($this->tourist)->get($this->url('/pdf'))->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringContainsString('beach-weekend-sosogon.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_an_itinerary_is_private_until_its_owner_shares_it(): void
    {
        $this->actingAs($this->tourist)->getJson($this->url('/share'))->assertOk()->assertJsonPath('share_token', null);

        $this->getJson('/api/v1/shared-itineraries/'.str_repeat('a', 48))->assertNotFound();
    }

    public function test_asking_for_the_link_again_returns_the_same_link(): void
    {
        $this->assertSame($this->shareLink(), $this->shareLink());
    }

    public function test_a_shared_itinerary_shows_the_plan_without_private_details(): void
    {
        $response = $this->getJson('/api/v1/shared-itineraries/'.$this->shareLink())->assertOk();

        $response->assertJsonPath('title', 'Beach weekend')->assertJsonCount(2, 'items');
        foreach (['user_id', 'additional_preferences', 'ai_response_raw', 'claim_token', 'share_token', 'saved_at'] as $field) {
            $response->assertJsonMissingPath($field);
        }
        $this->assertStringNotContainsString('wheelchair', $response->getContent());
    }

    public function test_the_shared_pdf_leaves_out_the_owners_notes(): void
    {
        $token = $this->shareLink();
        $itinerary = Itinerary::where('share_token', $token)->with(ItineraryController::relations())->first();

        $this->assertStringNotContainsString('wheelchair', app(ItineraryDocumentService::class)->html($itinerary, shared: true));

        $response = $this->get("/api/v1/shared-itineraries/{$token}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_stopping_sharing_kills_the_link(): void
    {
        $token = $this->shareLink();

        $this->actingAs($this->tourist)->deleteJson($this->url('/share'))->assertOk();

        $this->getJson("/api/v1/shared-itineraries/{$token}")->assertNotFound();
        $this->getJson("/api/v1/shared-itineraries/{$token}/pdf")->assertNotFound();
    }

    public function test_a_shared_link_cannot_change_the_itinerary(): void
    {
        $token = $this->shareLink();

        $this->putJson("/api/v1/shared-itineraries/{$token}", ['title' => 'Changed'])->assertStatus(405);
        $this->deleteJson("/api/v1/shared-itineraries/{$token}")->assertStatus(405);

        $this->assertSame('Beach weekend', $this->itinerary->fresh()->title);
    }
}

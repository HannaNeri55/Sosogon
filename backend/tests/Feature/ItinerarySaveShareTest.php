<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Tourist\ItineraryController;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Itinerary;
use App\Models\ItineraryItem;
use App\Models\Role;
use App\Models\User;
use App\Services\ItineraryDocumentService;
use App\Services\ItineraryPdfDownloads;
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

    public function test_a_lunch_break_during_a_visit_appears_in_that_visits_schedule_at_its_time(): void
    {
        $lagoon = ItineraryItem::where('itinerary_id', $this->itinerary->id)->where('sequence', 1)->firstOrFail();
        $lagoon->update(['time_slot' => '8:00 AM – 1:00 PM', 'activities' => [
            ['time' => '8:00 AM – 11:00 AM', 'name' => 'Morning swim', 'description' => ''],
            ['time' => '12:30 PM – 1:00 PM', 'name' => 'Sunset photos', 'description' => ''],
        ]]);
        $grill = FoodBeverage::create([
            'user_id' => $this->tourist->id, 'name' => 'Sea Grill', 'description' => 'A grill.', 'municipality_id' => $lagoon->destination->municipality_id,
            'address' => 'Bulan', 'latitude' => 12.71, 'longitude' => 123.91, 'price_range' => 'moderate', 'status' => 'approved',
        ]);
        ItineraryItem::create([
            'itinerary_id' => $this->itinerary->id, 'item_type' => 'food_beverage', 'food_beverage_id' => $grill->id,
            'day_number' => 1, 'sequence' => 1, 'time_slot' => '11:30 AM – 12:30 PM',
            'notes' => 'Lunch break during your visit to Paguriran Lagoon.',
        ]);

        $html = $this->actingAs($this->tourist)->getJson($this->url('/document'))->assertOk()->json('html');
        $schedule = substr($html, strpos($html, 'Your schedule here'));

        $swim = strpos($schedule, 'Morning swim');
        $lunch = strpos($schedule, 'Lunch break · 11:30 AM – 12:30 PM');
        $photos = strpos($schedule, 'Sunset photos');
        $this->assertTrue($swim !== false && $lunch !== false && $photos !== false && $swim < $lunch && $lunch < $photos);
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
        $this->assertStringContainsString('SOSOGON-Itinerary-Bulan-2026-12-05.pdf', (string) $response->headers->get('content-disposition'));
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

    /** Download PDF: the prepared file, with the two links the page opens for it. */
    private function preparedPdf(): array
    {
        $prepared = $this->actingAs($this->tourist)->postJson($this->url('/pdf-download'))->assertOk()->json();

        return $prepared + [
            'download_url' => "/api/v1/itinerary-files/{$prepared['ticket']}/download",
            'view_url' => "/api/v1/itinerary-files/{$prepared['ticket']}/view",
        ];
    }

    public function test_download_pdf_prepares_a_file_the_browser_saves_as_an_attachment(): void
    {
        $prepared = $this->preparedPdf();

        $this->assertSame('SOSOGON-Itinerary-Bulan-2026-12-05.pdf', $prepared['filename']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $prepared['ticket']);

        // Requested the way a browser opens a link: no sign-in, the ticket is the key.
        $this->app['auth']->forgetGuards();
        $response = $this->get($prepared['download_url'])->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('SOSOGON-Itinerary-Bulan-2026-12-05.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF-', file_get_contents($response->getFile()->getPathname()));
    }

    public function test_open_pdf_shows_the_same_file_instead_of_saving_it(): void
    {
        $prepared = $this->preparedPdf();

        $response = $this->get($prepared['view_url'])->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('content-disposition'));
    }

    public function test_the_page_learns_the_download_started_only_from_the_browsers_own_request(): void
    {
        $prepared = $this->preparedPdf();
        $status = $this->url('/pdf-download/'.$prepared['ticket']);

        $this->actingAs($this->tourist)->getJson($status)->assertOk()->assertJsonPath('started', false);

        // The page reading its offline copy, and Open PDF, are not the download.
        $this->get($prepared['download_url'], ['X-Sosogon-Copy' => '1'])->assertOk();
        $this->get($prepared['view_url'])->assertOk();
        $this->actingAs($this->tourist)->getJson($status)->assertOk()->assertJsonPath('started', false);

        $this->get($prepared['download_url'])->assertOk();
        $this->actingAs($this->tourist)->getJson($status)->assertOk()->assertJsonPath('started', true);
    }

    public function test_only_the_owner_can_prepare_a_download_or_follow_it(): void
    {
        $this->actingAs($this->stranger)->postJson($this->url('/pdf-download'))->assertNotFound();

        $prepared = $this->preparedPdf();
        $this->actingAs($this->stranger)->getJson($this->url('/pdf-download/'.$prepared['ticket']))->assertNotFound();
    }

    public function test_preparing_a_download_needs_a_signed_in_tourist(): void
    {
        $this->postJson($this->url('/pdf-download'))->assertUnauthorized();
    }

    public function test_an_unknown_or_expired_link_explains_itself_instead_of_downloading(): void
    {
        $this->get('/api/v1/itinerary-files/'.str_repeat('a', 40).'/download')
            ->assertStatus(410)
            ->assertSee('This download link has expired');

        $prepared = $this->preparedPdf();
        $this->travel(ItineraryPdfDownloads::MINUTES + 1)->minutes();

        $this->get($prepared['download_url'])->assertStatus(410);
    }
}

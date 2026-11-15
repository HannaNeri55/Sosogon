<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Municipality;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\MunicipalityProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The About page's municipality profiles: every town's card and photo, its contact details,
 * geography and history, kept by provincial admins only.
 */
class MunicipalityProfileTest extends TestCase
{
    use RefreshDatabase;

    private Municipality $bulan;

    private Municipality $matnog;

    private User $provincial;

    private User $bulanAdmin;

    private array $roles;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->roles = collect(['admin', 'business_owner', 'tourist'])
            ->mapWithKeys(fn ($name) => [$name => Role::create(['name' => $name, 'description' => $name])->id])->all();
        $this->bulan = Municipality::create(['name' => 'Bulan']);
        $this->matnog = Municipality::create(['name' => 'Matnog']);

        $this->provincial = $this->user('admin', 'Provincial Admin');
        $this->provincial->forceFill(['admin_scope' => User::ADMIN_SCOPE_PROVINCIAL])->save();
        $this->bulanAdmin = $this->user('admin', 'Bulan Admin');
        $this->bulanAdmin->forceFill(['admin_scope' => User::ADMIN_SCOPE_MUNICIPAL, 'municipality_id' => $this->bulan->id])->save();
    }

    private function user(string $role, string $name): User
    {
        return User::create(['role_id' => $this->roles[$role], 'name' => $name, 'email' => str_replace(' ', '.', strtolower($name)).'@example.com',
            'password' => 'Secret123!', 'status' => 'active', 'business_type' => $role === 'business_owner' ? 'accommodation' : null]);
    }

    private function destinationWithPhoto(Municipality $town, string $name, string $category, bool $featured = false): Destination
    {
        $categoryId = DB::table('categories')->where('name', $category)->value('id')
            ?? DB::table('categories')->insertGetId(['name' => $category, 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $destination = Destination::create(['name' => $name, 'description' => "{$name}.", 'category_id' => $categoryId, 'municipality_id' => $town->id,
            'address' => 'Shore Rd.', 'latitude' => 12.6, 'longitude' => 123.9, 'created_by' => $this->provincial->id, 'status' => 'published', 'is_featured' => $featured]);
        $destination->images()->create(['image_path' => 'destinations/'.str($name)->slug().'.webp', 'thumbnail_path' => 'destinations/thumbnails/'.str($name)->slug().'.webp', 'is_primary' => true]);

        return $destination;
    }

    public function test_every_town_has_a_card_with_a_photo_of_a_public_place_in_it(): void
    {
        $this->destinationWithPhoto($this->bulan, 'Bulan Beach Resort', 'Resort', featured: true);
        $this->destinationWithPhoto($this->bulan, 'Ilawod Beach', 'Beach');
        $this->destinationWithPhoto($this->matnog, 'Mila Beach Resort', 'Resort');

        $cards = $this->getJson('/api/v1/municipality-profiles')->assertOk()->json();

        $this->assertSame(['Bulan', 'Matnog'], array_column($cards, 'name'));
        // A public place comes before a private resort, even a featured one.
        $this->assertSame('Ilawod Beach', $cards[0]['photo']['caption']);
        $this->assertSame('destinations/ilawod-beach.webp', $cards[0]['photo']['image_path']);
        // A town with only resorts still gets a photo of a place in it.
        $this->assertSame('Mila Beach Resort', $cards[1]['photo']['caption']);
    }

    public function test_a_card_opens_that_towns_own_profile_without_the_admin_notes(): void
    {
        $this->bulan->forceFill(['email' => 'lgubulan@gmail.com', 'geography' => 'Bulan geography.', 'history' => 'Bulan history.', 'sources' => 'https://example.org'])->save();
        $this->matnog->forceFill(['history' => 'Matnog history.'])->save();

        $profile = $this->getJson("/api/v1/municipality-profiles/{$this->bulan->id}")->assertOk();
        $profile->assertJson(['name' => 'Bulan', 'lgu_label' => 'Municipality', 'email' => 'lgubulan@gmail.com', 'geography' => 'Bulan geography.', 'history' => 'Bulan history.', 'contact_number' => null]);
        $profile->assertJsonMissingPath('sources');
        $this->getJson("/api/v1/municipality-profiles/{$this->matnog->id}")->assertJson(['history' => 'Matnog history.']);
        $this->getJson('/api/v1/municipality-profiles/99999')->assertNotFound();

        // Lists of towns used by filters and forms don't carry the profiles.
        $this->getJson('/api/v1/municipalities')->assertOk()->assertJsonMissingPath('0.history')->assertJsonMissingPath('0.email');
    }

    public function test_a_provincial_admin_edits_a_profile_and_its_photo_and_the_about_page_shows_it(): void
    {
        $this->destinationWithPhoto($this->bulan, 'Ilawod Beach', 'Beach');

        $response = $this->actingAs($this->provincial)->post("/api/v1/admin/municipality-profiles/{$this->bulan->id}", [
            '_method' => 'PUT',
            'contact_number' => '(056) 123 4567',
            'email' => 'tourism@bulan.example.org',
            'address' => '  ',
            'geography' => 'At the south-western tip of the Bicol Peninsula.',
            'boundaries' => 'Magallanes to the north.',
            'history' => 'Became a pueblo civil in 1801.',
            'name_origin' => 'From the Bikol word for moon.',
            'sources' => 'sorsogon.gov.ph',
            'image' => UploadedFile::fake()->image('bulan.jpg', 1600, 900),
        ], ['Accept' => 'application/json'])->assertOk();

        $path = $response->json('profile.photo.image_path');
        $this->assertStringStartsWith('municipalities/', $path);
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertExists($response->json('profile.photo.thumbnail_path'));
        $response->assertJson(['profile' => ['sources' => 'sorsogon.gov.ph', 'updated_by' => 'Provincial Admin', 'address' => null]]);

        $this->getJson("/api/v1/municipality-profiles/{$this->bulan->id}")->assertJson([
            'contact_number' => '(056) 123 4567', 'email' => 'tourism@bulan.example.org', 'address' => null,
            'history' => 'Became a pueblo civil in 1801.', 'photo' => ['image_path' => $path, 'uploaded' => true],
        ]);
        $this->assertSame($path, $this->getJson('/api/v1/municipality-profiles')->json('0.photo.image_path'));

        // Removing the uploaded photo deletes it and the card goes back to a place in the town.
        $this->actingAs($this->provincial)->putJson("/api/v1/admin/municipality-profiles/{$this->bulan->id}", ['remove_image' => true])->assertOk();
        Storage::disk('public')->assertMissing($path);
        $this->getJson("/api/v1/municipality-profiles/{$this->bulan->id}")->assertJson(['photo' => ['caption' => 'Ilawod Beach', 'uploaded' => false], 'history' => 'Became a pueblo civil in 1801.']);
    }

    public function test_profiles_are_validated(): void
    {
        $this->actingAs($this->provincial)->putJson("/api/v1/admin/municipality-profiles/{$this->bulan->id}", ['email' => 'not an email', 'history' => str_repeat('a', 10001)])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'history']);
        $this->actingAs($this->provincial)->post("/api/v1/admin/municipality-profiles/{$this->bulan->id}", ['_method' => 'PUT', 'image' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['image']);
    }

    public function test_only_provincial_admins_can_manage_profiles(): void
    {
        $url = "/api/v1/admin/municipality-profiles/{$this->bulan->id}";

        // Not even a municipal office for its own town: the About page is the province's.
        $this->actingAs($this->bulanAdmin)->getJson('/api/v1/admin/municipality-profiles')->assertForbidden();
        $this->actingAs($this->bulanAdmin)->putJson($url, ['history' => 'Changed'])->assertForbidden();
        $this->actingAs($this->user('business_owner', 'Shop Owner'))->putJson($url, ['history' => 'Changed'])->assertForbidden();
        $this->actingAs($this->user('tourist', 'Ana Tourist'))->putJson($url, ['history' => 'Changed'])->assertForbidden();
        $this->assertNull($this->bulan->fresh()->history);

        $this->actingAs($this->provincial)->getJson('/api/v1/admin/municipality-profiles')->assertOk()
            ->assertJsonPath('0.name', 'Bulan')->assertJsonPath('0.missing', ['contact', 'geography', 'history']);
    }

    public function test_guests_cannot_manage_profiles(): void
    {
        $this->putJson("/api/v1/admin/municipality-profiles/{$this->bulan->id}", ['history' => 'Changed'])->assertUnauthorized();
    }

    public function test_the_researched_profiles_fill_only_empty_fields(): void
    {
        $this->bulan->forceFill(['history' => 'Written by the provincial office.'])->save();
        Municipality::create(['name' => 'Sorsogon City']);

        (new MunicipalityProfileSeeder)->run();

        $bulan = $this->bulan->fresh();
        $this->assertSame('Written by the provincial office.', $bulan->history);
        $this->assertSame('lgubulan@gmail.com', $bulan->email);
        $this->assertNotEmpty($bulan->geography);
        $this->assertNull($bulan->contact_number); // not confirmed by an official source
        $this->assertSame('city', Municipality::where('name', 'Sorsogon City')->value('lgu_type'));
    }
}

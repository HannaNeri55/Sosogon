<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admin event cover images: upload on create, replace or remove on edit, files
 * cleaned up on delete, and the stored image is what tourists get back.
 */
class AdminEventImageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tourist;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $roles = collect(['admin', 'tourist'])
            ->mapWithKeys(fn ($name) => [$name => Role::create(['name' => $name, 'description' => $name])->id]);
        $user = fn (string $role) => User::create([
            'role_id' => $roles[$role], 'name' => ucfirst($role), 'email' => "{$role}@example.com", 'password' => bcrypt('password'),
        ]);
        $this->admin = $user('admin');
        $this->tourist = $user('tourist');
    }

    private function eventFields(array $extra = []): array
    {
        return [
            'title' => 'Kasanggayahan Festival',
            'description' => 'Provincial founding anniversary celebration.',
            'start_date' => now()->addMonth()->toDateString(),
            'is_featured' => '0',
            'status' => 'published',
        ] + $extra;
    }

    public function test_admin_can_create_an_event_with_a_cover_image_that_tourists_see(): void
    {
        $response = $this->actingAs($this->admin)
            ->post('/api/v1/admin/events', $this->eventFields(['image' => UploadedFile::fake()->image('cover.jpg', 1600, 900)]), ['Accept' => 'application/json'])
            ->assertCreated();

        $path = $response->json('event.image_path');
        $this->assertStringStartsWith('events/', $path);
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertExists('events/thumbnails/'.basename($path));

        $this->getJson('/api/v1/events/'.$response->json('event.id'))->assertOk()->assertJsonPath('image_path', $path);
    }

    public function test_an_event_can_still_be_created_without_an_image(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/events', $this->eventFields())
            ->assertCreated()
            ->assertJsonPath('event.image_path', null);
    }

    public function test_replacing_and_removing_the_cover_deletes_the_old_files_and_keeps_other_fields(): void
    {
        $id = $this->actingAs($this->admin)
            ->post('/api/v1/admin/events', $this->eventFields(['image' => UploadedFile::fake()->image('old.jpg')]), ['Accept' => 'application/json'])
            ->json('event.id');
        $old = Event::find($id)->image_path;
        Event::whereKey($id)->update(['what_to_wear' => 'Light clothes']);

        $new = $this->actingAs($this->admin)
            ->post("/api/v1/admin/events/{$id}", ['_method' => 'PUT', 'image' => UploadedFile::fake()->image('new.png')], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('event.image_path');
        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertMissing('events/thumbnails/'.basename($old));
        Storage::disk('public')->assertExists($new);

        $this->actingAs($this->admin)
            ->post("/api/v1/admin/events/{$id}", ['_method' => 'PUT', 'remove_image' => '1'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('event.image_path', null);
        Storage::disk('public')->assertMissing($new);
        $this->assertSame('Light clothes', Event::find($id)->what_to_wear, 'Fields the form does not send are left alone.');
    }

    public function test_editing_without_an_image_keeps_the_current_cover_and_saves_the_municipality(): void
    {
        $town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $id = $this->actingAs($this->admin)
            ->post('/api/v1/admin/events', $this->eventFields(['image' => UploadedFile::fake()->image('keep.jpg')]), ['Accept' => 'application/json'])
            ->json('event.id');
        $path = Event::find($id)->image_path;

        $this->actingAs($this->admin)
            ->post("/api/v1/admin/events/{$id}", ['_method' => 'PUT', 'title' => 'Renamed', 'municipality_id' => $town], ['Accept' => 'application/json'])
            ->assertOk();

        $event = Event::find($id);
        $this->assertSame([$path, 'Renamed', $town], [$event->image_path, $event->title, $event->municipality_id]);
        Storage::disk('public')->assertExists($path);
    }

    public function test_deleting_an_event_deletes_its_cover_files(): void
    {
        $id = $this->actingAs($this->admin)
            ->post('/api/v1/admin/events', $this->eventFields(['image' => UploadedFile::fake()->image('gone.jpg')]), ['Accept' => 'application/json'])
            ->json('event.id');
        $path = Event::find($id)->image_path;

        $this->actingAs($this->admin)->deleteJson("/api/v1/admin/events/{$id}")->assertOk();

        Storage::disk('public')->assertMissing($path);
        Storage::disk('public')->assertMissing('events/thumbnails/'.basename($path));
    }

    public function test_non_images_and_oversized_files_are_rejected_and_only_admins_may_upload(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/v1/admin/events', $this->eventFields(['image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf')]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->actingAs($this->admin)
            ->post('/api/v1/admin/events', $this->eventFields(['image' => UploadedFile::fake()->image('huge.jpg')->size(6000)]), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');

        $this->actingAs($this->tourist)
            ->post('/api/v1/admin/events', $this->eventFields(['image' => UploadedFile::fake()->image('x.jpg')]), ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertSame(0, Event::count());
    }
}

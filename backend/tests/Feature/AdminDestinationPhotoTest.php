<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\DestinationImage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Staged photo removal and cover selection in the admin destination form. */
class AdminDestinationPhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Destination $destination;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = User::create([
            'role_id' => Role::create(['name' => 'admin', 'description' => 'Admin'])->id,
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
        ]);
        Role::create(['name' => 'tourist', 'description' => 'Tourist']);

        $town = DB::table('municipalities')->insertGetId(['name' => 'Barcelona', 'created_at' => now(), 'updated_at' => now()]);
        $category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);

        $this->destination = Destination::create([
            'name' => 'Test Spot', 'description' => 'Destination used for automated testing.',
            'category_id' => $category, 'municipality_id' => $town, 'address' => 'Test St.',
            'latitude' => 12.87, 'longitude' => 124.14, 'created_by' => $this->admin->id, 'status' => 'published',
        ]);
    }

    /** Three saved photos (sort 0-2); the first is the cover. */
    private function savedPhotos(): array
    {
        return collect(range(0, 2))->map(function ($i) {
            Storage::disk('public')->put("destinations/{$this->destination->id}/p{$i}.webp", 'x');
            Storage::disk('public')->put("destinations/{$this->destination->id}/thumbnails/p{$i}.webp", 'x');

            return DestinationImage::create([
                'destination_id' => $this->destination->id,
                'image_path' => "destinations/{$this->destination->id}/p{$i}.webp",
                'thumbnail_path' => "destinations/{$this->destination->id}/thumbnails/p{$i}.webp",
                'sort_order' => $i,
                'is_primary' => $i === 0,
            ]);
        })->all();
    }

    private function save(array $data)
    {
        return $this->actingAs($this->admin)->post(
            "/api/v1/admin/destinations/{$this->destination->id}",
            $data + ['_method' => 'PUT'],
            ['Accept' => 'application/json']
        );
    }

    private function coverId(): ?int
    {
        return $this->destination->images()->where('is_primary', true)->value('id');
    }

    public function test_cover_can_be_changed_to_another_saved_photo_on_save(): void
    {
        [$a, $b] = $this->savedPhotos();

        $this->save(['cover_image_id' => $b->id])->assertOk();

        $this->assertSame($b->id, $this->coverId());
        $this->assertSame(1, $this->destination->images()->where('is_primary', true)->count());
        $this->assertSame(3, $this->destination->images()->count());
    }

    public function test_a_new_upload_can_be_the_cover(): void
    {
        $this->savedPhotos();

        $this->save([
            'images' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.jpg')],
            'cover_new_index' => 1,
        ])->assertOk();

        $images = $this->destination->images()->get();
        $this->assertCount(5, $images);
        $this->assertSame($images->last()->id, $this->coverId());
        $this->assertSame(1, $images->where('is_primary', true)->count());
    }

    public function test_removed_photos_are_deleted_with_their_files_and_others_kept(): void
    {
        [$a, $b, $c] = $this->savedPhotos();

        $this->save(['remove_image_ids' => [$b->id], 'cover_image_id' => $a->id])->assertOk();

        $this->assertSame([$a->id, $c->id], $this->destination->images()->pluck('id')->all());
        Storage::disk('public')->assertMissing($b->image_path);
        Storage::disk('public')->assertMissing($b->thumbnail_path);
        Storage::disk('public')->assertExists($a->image_path);
        $this->assertSame($a->id, $this->coverId());
    }

    public function test_removing_the_cover_falls_back_to_a_remaining_photo(): void
    {
        [$a, $b, $c] = $this->savedPhotos();

        $this->save(['remove_image_ids' => [$a->id], 'cover_image_id' => $a->id])->assertOk();

        $this->assertSame($b->id, $this->coverId());
    }

    public function test_photos_of_another_destination_cannot_be_removed_or_made_cover(): void
    {
        $this->savedPhotos();
        $other = Destination::create($this->destination->only([
            'description', 'category_id', 'municipality_id', 'address', 'latitude', 'longitude', 'created_by', 'status',
        ]) + ['name' => 'Other Spot']);
        $foreign = DestinationImage::create([
            'destination_id' => $other->id, 'image_path' => 'destinations/x.webp', 'thumbnail_path' => 'destinations/t.webp',
            'sort_order' => 0, 'is_primary' => true,
        ]);

        $this->save(['remove_image_ids' => [$foreign->id], 'cover_image_id' => $foreign->id])->assertOk();

        $this->assertDatabaseHas('destination_images', ['id' => $foreign->id, 'is_primary' => true]);
        $this->assertNotSame($foreign->id, $this->coverId());
    }

    public function test_saving_without_photo_changes_keeps_photos_and_other_fields(): void
    {
        [$a] = $this->savedPhotos();

        $this->save(['name' => 'Renamed Spot'])->assertOk();

        $this->assertSame(3, $this->destination->images()->count());
        $this->assertSame($a->id, $this->coverId());
        $this->assertSame('Renamed Spot', $this->destination->fresh()->name);
        $this->assertSame('Destination used for automated testing.', $this->destination->fresh()->description);
    }

    public function test_new_destination_uses_the_chosen_upload_as_cover(): void
    {
        $response = $this->actingAs($this->admin)->post('/api/v1/admin/destinations', [
            'name' => 'Brand New', 'description' => 'New destination for testing.', 'category_id' => $this->destination->category_id,
            'municipality_id' => $this->destination->municipality_id, 'address' => 'Test St.',
            'latitude' => 12.8, 'longitude' => 124.1, 'status' => 'published',
            'images' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg'), UploadedFile::fake()->image('c.jpg')],
            'cover_new_index' => 2,
        ], ['Accept' => 'application/json'])->assertCreated();

        $images = Destination::find($response->json('destination.id'))->images()->get();
        $this->assertSame([false, false, true], $images->pluck('is_primary')->all());
    }

    public function test_non_admins_cannot_change_photos(): void
    {
        [$a, $b] = $this->savedPhotos();
        $tourist = User::create([
            'role_id' => Role::where('name', 'tourist')->value('id'),
            'name' => 'Tourist', 'email' => 't@example.com', 'password' => bcrypt('password'),
        ]);

        $this->actingAs($tourist)
            ->post("/api/v1/admin/destinations/{$this->destination->id}", ['_method' => 'PUT', 'remove_image_ids' => [$b->id]], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertSame(3, $this->destination->images()->count());
    }

    public function test_repeated_saves_without_new_photos_keep_the_photo_count(): void
    {
        [$a] = $this->savedPhotos();
        $files = count(Storage::disk('public')->allFiles());

        foreach (range(1, 3) as $i) {
            $this->save(['name' => "Spot {$i}", 'cover_image_id' => $a->id, 'save_token' => "token-{$i}"])->assertOk();
        }

        $this->assertSame(3, $this->destination->images()->count());
        $this->assertSame($files, count(Storage::disk('public')->allFiles()));
        $this->assertSame($a->id, $this->coverId());
    }

    public function test_a_repeated_submit_of_the_same_save_is_rejected_and_uploads_once(): void
    {
        $this->savedPhotos();
        $payload = fn () => ['images' => [UploadedFile::fake()->image('new.jpg')], 'save_token' => 'same-attempt'];

        $this->save($payload())->assertOk();
        $this->save($payload())->assertStatus(409);

        $this->assertSame(4, $this->destination->images()->count());
        // 3 seeded photos + 1 upload, each with a thumbnail.
        $this->assertCount(8, Storage::disk('public')->allFiles());
    }

    public function test_identical_files_in_separate_saves_are_still_allowed(): void
    {
        $this->savedPhotos();

        $this->save(['images' => [UploadedFile::fake()->image('same.jpg')], 'save_token' => 'first'])->assertOk();
        $this->save(['images' => [UploadedFile::fake()->image('same.jpg')], 'save_token' => 'second'])->assertOk();

        $this->assertSame(5, $this->destination->images()->count());
    }

    public function test_changing_the_cover_or_removing_a_photo_never_adds_records(): void
    {
        [$a, $b, $c] = $this->savedPhotos();

        $this->save(['cover_image_id' => $c->id, 'save_token' => 't1'])->assertOk();
        $this->save(['cover_image_id' => $b->id, 'save_token' => 't2'])->assertOk();
        $this->assertSame(3, $this->destination->images()->count());

        $this->save(['remove_image_ids' => [$a->id], 'cover_image_id' => $b->id, 'save_token' => 't3'])->assertOk();
        $this->assertSame([$b->id, $c->id], $this->destination->images()->pluck('id')->all());
        $this->assertSame($b->id, $this->coverId());
    }
}

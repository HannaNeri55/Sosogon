<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Destination;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Managing a destination's optional activities from the admin destination form. */
class AdminDestinationActivityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $town;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = User::create([
            'role_id' => Role::create(['name' => 'admin', 'description' => 'Admin'])->id,
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
        ]);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Barcelona', 'created_at' => now(), 'updated_at' => now()]);
        $this->category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function fields(array $extra = []): array
    {
        return $extra + [
            'name' => 'Test Spot', 'description' => 'Destination used for automated testing.',
            'category_id' => $this->category, 'municipality_id' => $this->town, 'address' => 'Test St.',
            'latitude' => 12.87, 'longitude' => 124.14, 'status' => 'published',
        ];
    }

    private function create(array $extra = [])
    {
        return $this->actingAs($this->admin)->post('/api/v1/admin/destinations', $this->fields($extra), ['Accept' => 'application/json']);
    }

    private function update(Destination $destination, array $extra)
    {
        return $this->actingAs($this->admin)->post(
            "/api/v1/admin/destinations/{$destination->id}",
            $this->fields($extra) + ['_method' => 'PUT'],
            ['Accept' => 'application/json']
        );
    }

    private function seededDestination(): Destination
    {
        $destination = Destination::create($this->fields(['created_by' => $this->admin->id]));
        Storage::disk('public')->put('activities/old.webp', 'x');
        Storage::disk('public')->put('activities/thumbnails/old.webp', 'x');
        $destination->activities()->createMany([
            ['name' => 'Kayaking', 'type' => 'outdoor', 'price' => 100, 'duration_minutes' => 30,
                'image_path' => 'activities/old.webp', 'thumbnail_path' => 'activities/thumbnails/old.webp'],
            ['name' => 'Museum Tour', 'type' => 'indoor'],
        ]);

        return $destination;
    }

    public function test_destination_can_be_created_without_activities(): void
    {
        $id = $this->create(['activities_present' => '1'])->assertCreated()->json('destination.id');
        $this->assertSame(0, Activity::where('destination_id', $id)->count());

        // Older callers that don't send the section at all also work.
        $this->create(['name' => 'Second Spot'])->assertCreated();
    }

    public function test_destination_can_be_created_with_several_activities_and_an_image(): void
    {
        $response = $this->create([
            'activities_present' => '1',
            'activities' => [
                ['name' => 'Snorkeling', 'type' => 'outdoor', 'price' => '250', 'duration_minutes' => '120', 'capacity' => '10',
                    'description' => 'See the reef.', 'image' => UploadedFile::fake()->image('snorkel.jpg')],
                ['name' => 'Chapel Visit', 'type' => 'indoor', 'price' => '', 'duration_minutes' => '', 'capacity' => ''],
            ],
        ])->assertCreated();

        $activities = Activity::where('destination_id', $response->json('destination.id'))->orderBy('id')->get();
        $this->assertSame(['Snorkeling', 'Chapel Visit'], $activities->pluck('name')->all());
        $this->assertEquals(250, $activities[0]->price);
        $this->assertSame(120, $activities[0]->duration_minutes);
        $this->assertSame(10, $activities[0]->capacity);
        $this->assertNotNull($activities[0]->image_path);
        Storage::disk('public')->assertExists($activities[0]->image_path);
        $this->assertNull($activities[1]->price);
        $this->assertCount(2, $response->json('destination.activities'));
    }

    public function test_editing_updates_in_place_replaces_images_adds_and_removes(): void
    {
        $destination = $this->seededDestination();
        [$kayak, $museum] = $destination->activities()->orderBy('id')->get()->all();

        $this->update($destination, [
            'activities_present' => '1',
            'activities' => [
                ['id' => $kayak->id, 'name' => 'Kayaking (guided)', 'type' => 'outdoor', 'price' => '150',
                    'duration_minutes' => '60', 'image' => UploadedFile::fake()->image('new.jpg')],
                ['name' => 'Sunset Watching', 'type' => 'outdoor'],
            ],
        ])->assertOk();

        $kayak->refresh();
        $this->assertSame('Kayaking (guided)', $kayak->name);
        $this->assertEquals(150, $kayak->price);
        $this->assertNotSame('activities/old.webp', $kayak->image_path);
        Storage::disk('public')->assertMissing('activities/old.webp');
        Storage::disk('public')->assertExists($kayak->image_path);

        $this->assertDatabaseMissing('activities', ['id' => $museum->id]);
        $this->assertSame(['Kayaking (guided)', 'Sunset Watching'], $destination->activities()->orderBy('id')->pluck('name')->all());
    }

    public function test_saved_image_can_be_removed(): void
    {
        $destination = $this->seededDestination();
        $kayak = $destination->activities()->where('name', 'Kayaking')->first();
        $museum = $destination->activities()->where('name', 'Museum Tour')->first();

        $this->update($destination, [
            'activities_present' => '1',
            'activities' => [
                ['id' => $kayak->id, 'name' => 'Kayaking', 'type' => 'outdoor', 'remove_image' => '1'],
                ['id' => $museum->id, 'name' => 'Museum Tour', 'type' => 'indoor'],
            ],
        ])->assertOk();

        $this->assertNull($kayak->fresh()->image_path);
        Storage::disk('public')->assertMissing('activities/old.webp');
    }

    public function test_saving_without_the_activities_section_leaves_activities_untouched(): void
    {
        $destination = $this->seededDestination();
        $before = $destination->activities()->orderBy('id')->get(['id', 'name', 'price', 'image_path'])->toArray();

        $this->update($destination, ['name' => 'Renamed Spot'])->assertOk();

        $this->assertSame($before, $destination->activities()->orderBy('id')->get(['id', 'name', 'price', 'image_path'])->toArray());
        $this->assertSame('Renamed Spot', $destination->fresh()->name);
    }

    public function test_invalid_activities_are_rejected_without_partial_changes(): void
    {
        $destination = $this->seededDestination();
        $other = Destination::create($this->fields(['name' => 'Other Spot', 'created_by' => $this->admin->id]));
        $foreign = $other->activities()->create(['name' => 'Not Yours', 'type' => 'outdoor']);

        $this->update($destination, ['activities_present' => '1', 'activities' => [['name' => '', 'type' => 'outdoor']]])
            ->assertStatus(422)->assertJsonValidationErrors('activities.0.name');
        $this->update($destination, ['activities_present' => '1', 'activities' => [['name' => 'Bad', 'type' => 'underwater']]])
            ->assertStatus(422)->assertJsonValidationErrors('activities.0.type');
        $this->update($destination, ['activities_present' => '1', 'activities' => [['name' => 'Bad', 'type' => 'outdoor', 'capacity' => '0']]])
            ->assertStatus(422)->assertJsonValidationErrors('activities.0.capacity');
        $this->update($destination, ['activities_present' => '1', 'activities' => [['id' => $foreign->id, 'name' => 'Hijack', 'type' => 'outdoor']]])
            ->assertStatus(422)->assertJsonValidationErrors('activities.0.id');

        $this->assertSame(2, $destination->activities()->count());
        $this->assertSame('Not Yours', $foreign->fresh()->name);
        $this->assertSame('Test Spot', $destination->fresh()->name);
    }

    public function test_saved_activities_show_on_the_public_destination_page(): void
    {
        $destination = $this->seededDestination();
        $other = Destination::create($this->fields(['name' => 'Other Spot', 'created_by' => $this->admin->id]));
        $other->activities()->create(['name' => 'Elsewhere', 'type' => 'outdoor']);

        $names = collect($this->getJson("/api/v1/destinations/{$destination->id}")->assertOk()->json('destination.activities'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Kayaking', 'Museum Tour'], $names);
    }
}

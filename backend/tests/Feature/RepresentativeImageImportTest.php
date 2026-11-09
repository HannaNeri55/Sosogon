<?php

namespace Tests\Feature;

use App\Console\Commands\ImportRepresentativeImages;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** images:representative fills missing activity and room type images, never touching ones that exist. */
class RepresentativeImageImportTest extends TestCase
{
    use RefreshDatabase;

    private string $dataFile;

    private int $lookups = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::preventStrayRequests();
        ImportRepresentativeImages::$pauseMs = 0;

        $userId = User::create([
            'role_id' => Role::create(['name' => 'admin', 'description' => 'Admin'])->id,
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
        ])->id;
        $town = DB::table('municipalities')->insertGetId(['name' => 'Testville', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('categories')->insert(['id' => 1, 'name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $place = fn (array $fields) => $fields + ['description' => 'A test listing.', 'municipality_id' => $town, 'address' => 'Test St.',
            'latitude' => 12.67, 'longitude' => 123.88, 'created_at' => now(), 'updated_at' => now()];

        DB::table('destinations')->insert($place(['id' => 1, 'name' => 'Test Park', 'category_id' => 1, 'created_by' => $userId, 'status' => 'published']));
        DB::table('destinations')->insert($place(['id' => 2, 'name' => 'Test Museum', 'category_id' => 1, 'created_by' => $userId, 'status' => 'published']));
        foreach ([1 => 'Park stroll', 2 => 'Kayaking', 3 => 'Museum visit', 4 => 'Wrong gallery', 5 => 'Has a photo', 6 => 'Unlicensed'] as $id => $name) {
            DB::table('activities')->insert(['id' => $id, 'destination_id' => 1, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('activities')->where('id', 5)->update(['image_path' => 'activities/5/own.webp']);

        foreach ([10 => [1, 'park'], 20 => [2, 'museum']] as $id => [$destination, $file]) {
            Storage::disk('public')->put("destinations/{$destination}/{$file}.webp", 'img');
            Storage::disk('public')->put("destinations/{$destination}/thumbnails/{$file}.webp", 'thumb');
            DB::table('destination_images')->insert(['id' => $id, 'destination_id' => $destination,
                'image_path' => "destinations/{$destination}/{$file}.webp", 'thumbnail_path' => "destinations/{$destination}/thumbnails/{$file}.webp",
                'credit' => "Photo of {$file}", 'source_url' => "https://example.org/{$file}", 'sort_order' => 0, 'is_primary' => true,
                'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('accommodations')->insert($place(['id' => 7, 'name' => 'Test Inn', 'type' => 'Inn', 'price_range' => 'moderate', 'status' => 'approved']));
        DB::table('accommodation_room_types')->insert(['id' => 3, 'accommodation_id' => 7, 'name' => 'Family Room', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->dataFile = tempnam(sys_get_temp_dir(), 'rep').'.php';
        file_put_contents($this->dataFile, '<?php return '.var_export([
            'activities' => [
                1 => ['name' => 'Park stroll', 'destination_image' => 10],
                2 => ['name' => 'Kayaking', 'commons' => 'File:Kayak.jpg'],
                3 => ['name' => 'Museum visit', 'destination_image' => 20, 'destination' => 2],
                4 => ['name' => 'Wrong gallery', 'destination_image' => 20],
                5 => ['name' => 'Has a photo', 'destination_image' => 10],
                6 => ['name' => 'Unlicensed', 'commons' => 'File:Copyrighted.jpg'],
                9 => ['name' => 'Missing', 'destination_image' => 10],
            ],
            'room_types' => ['7|Family Room' => ['commons' => 'File:Family room.jpg']],
        ], true).';');

        Http::fake([
            'commons.wikimedia.org/*' => function ($request) {
                $this->lookups++;
                // The family room photo is only 1200 px wide, so Commons' "thumbnail" is the original itself.
                $pages = array_map(fn ($title) => ['title' => $title, 'imageinfo' => [$title === 'File:Family room.jpg' ? [
                    'url' => 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Family_room.jpg', 'width' => 1200,
                    'thumburl' => 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Family_room.jpg?utm_content=thumbnail_unscaled',
                    'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Family_room.jpg',
                    'extmetadata' => ['LicenseShortName' => ['value' => 'CC0'], 'Artist' => ['value' => 'Sam Roe']],
                ] : [
                    'url' => 'https://upload.wikimedia.org/full.jpg', 'thumburl' => 'https://upload.wikimedia.org/1600px.jpg',
                    'descriptionurl' => 'https://commons.wikimedia.org/wiki/'.str_replace(' ', '_', $title),
                    'extmetadata' => [
                        'LicenseShortName' => ['value' => $title === 'File:Copyrighted.jpg' ? 'All rights reserved' : 'CC BY-SA 4.0'],
                        'Artist' => ['value' => '<a href="#">Jane Doe</a>'],
                    ],
                ]]], explode('|', $request->data()['titles']));

                return Http::response(['query' => ['pages' => $pages]]);
            },
            'upload.wikimedia.org/*' => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->dataFile);
        parent::tearDown();
    }

    private function jpeg(): string
    {
        $image = imagecreatetruecolor(1200, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagejpeg($image);

        return ob_get_clean();
    }

    public function test_it_copies_gallery_photos_downloads_credited_commons_photos_and_keeps_existing_ones(): void
    {
        $this->artisan('images:representative', ['--data' => $this->dataFile])->assertSuccessful();

        $disk = Storage::disk('public');
        $stroll = DB::table('activities')->find(1);
        $this->assertSame('activities/1/park.webp', $stroll->image_path);
        $this->assertSame('activities/1/thumbnails/park.webp', $stroll->thumbnail_path);
        $this->assertSame('Photo of park', $stroll->image_credit);
        $this->assertSame('https://example.org/park', $stroll->image_source_url);
        $disk->assertExists(['activities/1/park.webp', 'activities/1/thumbnails/park.webp', 'destinations/1/park.webp']);

        // A neighbouring destination's photo is allowed only when the entry names that destination.
        $this->assertSame('activities/3/museum.webp', DB::table('activities')->find(3)->image_path);
        $this->assertNull(DB::table('activities')->find(4)->image_path);

        $kayak = DB::table('activities')->find(2);
        $this->assertSame('Representative photo: Jane Doe / Wikimedia Commons, CC BY-SA 4.0', $kayak->image_credit);
        $this->assertSame('https://commons.wikimedia.org/wiki/File:Kayak.jpg', $kayak->image_source_url);
        $this->assertStringStartsWith('activities/2/', $kayak->image_path);
        $disk->assertExists([$kayak->image_path, $kayak->thumbnail_path]);

        $this->assertSame('activities/5/own.webp', DB::table('activities')->find(5)->image_path);
        $this->assertNull(DB::table('activities')->find(6)->image_path, 'files without an open licence are not used');

        $room = DB::table('accommodation_room_types')->find(3);
        $this->assertStringStartsWith('accommodation-room-types/3/', $room->image_path);
        $this->assertStringStartsWith('Representative photo:', $room->image_credit);
        $disk->assertExists([$room->image_path, $room->thumbnail_path]);

        $this->assertSame(1, $this->lookups, 'all Commons files are looked up in one request');
        Http::assertSent(fn ($request) => $request->url() === 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Family_room.jpg/960px-Family_room.jpg');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'thumbnail_unscaled'));
        $this->getJson('/api/v1/activities/2')->assertOk()->assertJsonPath('image_credit', $kayak->image_credit);
    }

    public function test_running_again_changes_nothing_and_dry_run_writes_nothing(): void
    {
        $this->artisan('images:representative', ['--data' => $this->dataFile, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('activities')->whereNotNull('image_credit')->count());
        $this->assertFalse(Storage::disk('public')->exists('activities/1/park.webp'));

        $this->artisan('images:representative', ['--data' => $this->dataFile])->assertSuccessful();
        $before = DB::table('activities')->orderBy('id')->get()->toArray();
        $files = Storage::disk('public')->allFiles();

        $this->artisan('images:representative', ['--data' => $this->dataFile])
            ->expectsOutputToContain('0 copied, 0 downloaded, 5 kept')
            ->assertSuccessful();
        $this->assertEquals($before, DB::table('activities')->orderBy('id')->get()->toArray());
        $this->assertSame($files, Storage::disk('public')->allFiles());
    }
}

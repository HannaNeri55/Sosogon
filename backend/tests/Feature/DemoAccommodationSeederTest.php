<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\AccommodationImage;
use Database\Seeders\DemoAccommodationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The demo accommodations seeder, with Wikimedia Commons faked (CC0 metadata, small JPEGs). */
class DemoAccommodationSeederTest extends TestCase
{
    use RefreshDatabase;

    private array $data;

    private int $downloads = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        DemoAccommodationSeeder::$pauseMs = 0;
        $this->data = require database_path('seeders/data/demo_accommodations.php');

        foreach (collect($this->data)->groupBy('town') as $town => $listings) {
            $id = DB::table('municipalities')->insertGetId(['name' => $town, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($listings->pluck('barangay')->unique() as $barangay) {
                DB::table('barangays')->insert(['municipality_id' => $id, 'name' => $barangay, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $image = imagecreatetruecolor(64, 48);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($jpeg) {
            if (str_contains($request->url(), 'upload.wikimedia.org')) {
                $this->downloads++;

                return Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']);
            }
            $pages = array_map(fn ($title) => ['title' => $title, 'imageinfo' => [[
                'url' => 'https://upload.wikimedia.org/original/'.md5($title).'.jpg',
                'thumburl' => 'https://upload.wikimedia.org/thumb/'.md5($title).'.jpg',
                'descriptionurl' => 'https://commons.wikimedia.org/wiki/'.rawurlencode($title),
                'extmetadata' => ['LicenseShortName' => ['value' => 'CC0'], 'Artist' => ['value' => '<a href="#">Test Photographer</a>']],
            ]]], explode('|', $request->data()['titles']));

            return Http::response(['query' => ['pages' => $pages]]);
        });
    }

    public function test_it_creates_every_listing_with_rooms_and_photos(): void
    {
        $this->seed(DemoAccommodationSeeder::class);

        $listings = Accommodation::with(['images', 'rooms.images'])->get();
        $this->assertCount(12, $listings);
        foreach ($listings as $listing) {
            $this->assertSame('approved', $listing->status, $listing->name);
            $this->assertSame('published', $listing->publication_review_status);
            $this->assertFalse($listing->is_sample);
            $this->assertStringEndsWith('.example.com', $listing->email);
            $this->assertNotNull($listing->barangay_id);
            $this->assertCount(5, $listing->images, "{$listing->name} has a 5-photo gallery.");
            $this->assertTrue($listing->images->first()->is_primary);
            $this->assertGreaterThanOrEqual(3, $listing->rooms->count());
            $this->assertLessThanOrEqual(5, $listing->rooms->count());
            foreach ($listing->rooms as $room) {
                $this->assertSame('approved', $room->status);
                $this->assertGreaterThan(0, (float) $room->price, $room->name);
                $this->assertCount(2, $room->images, "{$listing->name} / {$room->name} has 2 photos.");
                $this->assertStringStartsWith('Representative photo: Test Photographer / Wikimedia Commons, CC0', $room->images->first()->image_credit);
            }
            Storage::disk('public')->assertExists($listing->images->first()->image_path);
        }

        // Every photo is stored once: no file is shared between listings or rooms.
        $paths = DB::table('accommodation_images')->pluck('image_path')->merge(DB::table('accommodation_room_images')->pluck('image_path'));
        $this->assertSame($paths->count(), $paths->unique()->count());
        $this->assertSame(148, $this->downloads);

        // Shown on the public detail page with its rooms.
        $first = $listings->first();
        $this->getJson("/api/v1/accommodations/{$first->id}")->assertOk()
            ->assertJsonPath('name', $first->name)
            ->assertJsonCount($first->rooms->count(), 'rooms');
    }

    public function test_running_it_again_adds_nothing_and_downloads_nothing(): void
    {
        $this->seed(DemoAccommodationSeeder::class);
        $this->downloads = 0;
        $counts = fn () => [Accommodation::count(), DB::table('accommodation_rooms')->count(), DB::table('accommodation_images')->count(), DB::table('accommodation_room_images')->count()];
        $before = $counts();

        $this->seed(DemoAccommodationSeeder::class);

        $this->assertSame($before, $counts());
        $this->assertSame(0, $this->downloads);
    }

    public function test_running_it_again_fills_in_a_room_photo_that_failed_before(): void
    {
        $this->seed(DemoAccommodationSeeder::class);
        $missing = DB::table('accommodation_room_images')->where('is_primary', false)->first();
        DB::table('accommodation_room_images')->where('id', $missing->id)->delete();

        $this->seed(DemoAccommodationSeeder::class);

        $this->assertSame(2, DB::table('accommodation_room_images')->where('room_id', $missing->room_id)->count());
        $this->assertSame(1, DB::table('accommodation_room_images')->where('room_id', $missing->room_id)->where('is_primary', true)->count());
        $this->assertSame(88, DB::table('accommodation_room_images')->count());
    }

    public function test_running_it_again_brings_an_existing_gallery_in_line_with_the_data_file(): void
    {
        $this->seed(DemoAccommodationSeeder::class);
        $listing = Accommodation::where('name', 'Dancalan Whaleside Beach Resort')->firstOrFail();
        $cover = $listing->images()->where('is_primary', true)->value('image_path');
        // As if it was seeded before the cover changed: another photo first, an old photo still there,
        // and a photo an admin added later.
        $listing->images()->update(['is_primary' => false]);
        $listing->images()->where('sort_order', 4)->update(['is_primary' => true]);
        $old = AccommodationImage::create(['accommodation_id' => $listing->id, 'image_path' => 'accommodations/demo-dancalan-whaleside-beach-resort/old-sea.webp',
            'thumbnail_path' => 'accommodations/demo-dancalan-whaleside-beach-resort/thumbnails/old-sea.webp', 'sort_order' => 5]);
        $admin = AccommodationImage::create(['accommodation_id' => $listing->id, 'image_path' => 'accommodations/30123/admin-upload.webp',
            'thumbnail_path' => 'accommodations/30123/thumbnails/admin-upload.webp', 'sort_order' => 6]);
        $this->downloads = 0;

        $this->seed(DemoAccommodationSeeder::class);

        $images = $listing->images()->orderByDesc('is_primary')->orderBy('sort_order')->get();
        $this->assertSame($cover, $images->first()->image_path);
        $this->assertSame(1, $images->where('is_primary', true)->count());
        $this->assertModelMissing($old);
        $this->assertModelExists($admin);
        $this->assertSame([0, 1, 2, 3, 4], $images->where('id', '!=', $admin->id)->pluck('sort_order')->map(fn ($n) => (int) $n)->values()->all());
        $this->assertSame(0, $this->downloads);
    }

    public function test_photos_already_on_disk_are_reused_for_another_database(): void
    {
        $this->seed(DemoAccommodationSeeder::class);
        // A fresh database (as on the live site) with the image files already in place.
        DB::table('accommodations')->delete();
        $this->downloads = 0;

        $this->seed(DemoAccommodationSeeder::class);

        $this->assertSame(12, Accommodation::count());
        $this->assertSame(0, $this->downloads, 'Files on disk are reused, not downloaded again.');
    }

    public function test_remove_deletes_only_the_demo_listings_and_their_folders(): void
    {
        $this->seed(DemoAccommodationSeeder::class);
        $town = DB::table('municipalities')->value('id');
        $real = Accommodation::create(['name' => 'A Real Inn', 'description' => 'Not demo data.', 'type' => 'Inn', 'municipality_id' => $town,
            'address' => 'Somewhere', 'latitude' => 12.7, 'longitude' => 123.9, 'price_range' => 'budget', 'status' => 'approved']);

        putenv('DEMO_ACCOMMODATIONS=remove');
        $_ENV['DEMO_ACCOMMODATIONS'] = $_SERVER['DEMO_ACCOMMODATIONS'] = 'remove';
        try {
            $this->seed(DemoAccommodationSeeder::class);
        } finally {
            putenv('DEMO_ACCOMMODATIONS');
            unset($_ENV['DEMO_ACCOMMODATIONS'], $_SERVER['DEMO_ACCOMMODATIONS']);
        }

        $this->assertSame([$real->id], Accommodation::pluck('id')->all());
        $this->assertSame(0, DB::table('accommodation_rooms')->count());
        Storage::disk('public')->assertMissing('accommodations/demo-pantalan-bay-hotel');
    }
}

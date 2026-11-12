<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\AccommodationImage;
use App\Models\AccommodationRoom;
use App\Models\AccommodationRoomImage;
use App\Models\ListingEstimate;
use App\Models\Rating;
use App\Models\Role;
use App\Models\User;
use App\Services\ImageOptimizationService;
use App\Support\AmenityList;
use Illuminate\Database\Seeder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The 12 demonstration accommodations in data/demo_accommodations.php: published listings with
 * their rooms, a 5-photo gallery each and 2 photos per room, from openly licensed Wikimedia
 * Commons files (data/demo_accommodation_photos.php). They are made-up businesses; that data file
 * is the record of which listings are demo data.
 *
 * Idempotent: listings are matched by name and town, rooms by name. A listing's gallery is kept in
 * line with the data file (its order and cover too); room photos are only added to a room that
 * lacks them. Photos are stored in fixed folders (accommodations/demo-<slug>,
 * accommodation-rooms/demo-<slug>-<room>), so a file already on disk is reused instead of being
 * downloaded again (running it against another database needs no new downloads). Other
 * accommodations are never touched.
 *
 * Remove them all: DEMO_ACCOMMODATIONS=remove php artisan db:seed --class=DemoAccommodationSeeder
 */
class DemoAccommodationSeeder extends Seeder
{
    private const COMMONS_API = 'https://commons.wikimedia.org/w/api.php';

    /** Pause between Commons downloads, in milliseconds (as images:representative). */
    public static int $pauseMs = 1500;

    /** Commons file details by title: url, page, author, licence. */
    private array $commons = [];

    private array $counts = ['listings' => 0, 'rooms' => 0, 'gallery' => 0, 'gallery reordered' => 0, 'gallery removed' => 0, 'room photos' => 0, 'downloaded' => 0, 'reused' => 0];

    public function __construct(private ImageOptimizationService $images) {}

    public function run(): void
    {
        $listings = require database_path('seeders/data/demo_accommodations.php');
        $photos = require database_path('seeders/data/demo_accommodation_photos.php');

        if (env('DEMO_ACCOMMODATIONS') === 'remove') {
            $this->remove($listings);

            return;
        }

        // Commons first: it can take a while, and a hosted database (TiDB) drops a connection
        // left idle that long. The database is first used after it.
        $this->lookUpCommons(array_values(array_unique(array_merge(...array_map(
            fn ($p) => [...$p['gallery'], ...array_merge(...array_values($p['rooms']))],
            array_values($photos)
        )))));

        $adminRole = Role::where('name', 'admin')->value('id');
        $adminId = $adminRole ? User::where('role_id', $adminRole)->orderBy('id')->value('id') : null;

        foreach ($listings as $data) {
            $this->seedListing($data, $photos[$data['slug']] ?? throw new RuntimeException("No photos for {$data['slug']}."), $adminId);
        }

        $this->command?->info('Demo accommodations: '.collect($this->counts)->map(fn ($n, $k) => "{$n} {$k}")->implode(', ').'.');
    }

    private function seedListing(array $data, array $photos, ?int $adminId): void
    {
        $town = DB::table('municipalities')->where('name', $data['town'])->value('id')
            ?? throw new RuntimeException("Unknown municipality {$data['town']}.");
        $barangay = DB::table('barangays')->where('municipality_id', $town)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($data['barangay']))])->value('id')
            ?? throw new RuntimeException("Unknown barangay {$data['barangay']} in {$data['town']}.");

        // Photos first, so a listing is only published with its whole gallery in place. Files already
        // on disk are reused, so this costs nothing for a listing that has them.
        $listing = Accommodation::where('name', $data['name'])->where('municipality_id', $town)->first();
        $gallery = $this->storeAll($photos['gallery'], "accommodations/demo-{$data['slug']}");
        if (count($gallery) < count($photos['gallery'])) {
            if (! $listing) {
                throw new RuntimeException("{$data['name']}: only ".count($gallery).' of '.count($photos['gallery']).' gallery photos could be stored; run the seeder again.');
            }
            $this->command?->warn("{$data['name']}: some gallery photos couldn't be stored, so its gallery was left as it is; run the seeder again.");
            $gallery = null;
        }

        DB::transaction(function () use ($data, $photos, $town, $barangay, $adminId, &$listing, $gallery) {
            if (! $listing) {
                $listing = Accommodation::create([
                    'name' => $data['name'],
                    'description' => $data['description'],
                    'type' => $data['classifications'][0],
                    'classifications' => $data['classifications'],
                    'offering_arrangement' => $data['arrangement'],
                    'municipality_id' => $town,
                    'barangay_id' => $barangay,
                    'address' => $data['address'],
                    'latitude' => $data['lat'],
                    'longitude' => $data['lng'],
                    'price_range' => $data['price_range'],
                    'contact_number' => $data['phone'],
                    'email' => "reservations@{$data['slug']}.example.com",
                    'website' => "https://{$data['slug']}.example.com",
                    'operating_hours' => $data['hours'],
                    'check_in_time' => $data['check_in'],
                    'check_out_time' => $data['check_out'],
                    'amenities' => AmenityList::clean($data['amenities']),
                    'dining_details' => $data['dining'],
                    'status' => 'approved',
                    'publication_review_status' => 'published',
                    'approved_at' => now(),
                    'approved_by' => $adminId,
                ]);
                $this->counts['listings']++;
            }

            if ($gallery !== null) {
                $this->syncGallery($listing, $gallery, $data['slug']);
            }

            foreach ($data['rooms'] as $order => $room) {
                $this->seedRoom($listing, $data['slug'], $room, $photos['rooms'][$room['name']] ?? [], $order, $adminId);
            }
        });
    }

    /**
     * Makes the listing's demo gallery match the data file: photos it lacks are added, the order and
     * the cover (the first photo) are set, and photos since taken out of the data file are deleted.
     * Photos an admin added later (stored outside the demo folder) are kept as they are.
     */
    private function syncGallery(Accommodation $listing, array $gallery, string $slug): void
    {
        $existing = $listing->images()->get()->keyBy('image_path');

        foreach ($gallery as $i => $stored) {
            $place = ['is_primary' => $i === 0, 'sort_order' => $i];
            $image = $existing->get($stored['image_path']);
            if (! $image) {
                AccommodationImage::create(Arr::only($stored, ['image_path', 'thumbnail_path']) + $place + ['accommodation_id' => $listing->id]);
                $this->counts['gallery']++;
            } elseif ((bool) $image->is_primary !== $place['is_primary'] || (int) $image->sort_order !== $i) {
                $image->update($place);
                $this->counts['gallery reordered']++;
            }
        }

        $keep = array_column($gallery, 'image_path');
        foreach ($existing as $path => $image) {
            if (str_starts_with($path, "accommodations/demo-{$slug}/") && ! in_array($path, $keep, true)) {
                $image->delete();
                $this->images->delete($image->image_path, $image->thumbnail_path);
                $this->counts['gallery removed']++;
            } elseif (! str_starts_with($path, "accommodations/demo-{$slug}/") && $image->is_primary) {
                $image->update(['is_primary' => false]);
            }
        }
    }

    private function seedRoom(Accommodation $listing, string $slug, array $room, array $titles, int $order, ?int $adminId): void
    {
        $record = $listing->rooms()->where('name', $room['name'])->first();
        if (! $record) {
            $record = AccommodationRoom::create([
                'accommodation_id' => $listing->id,
                'unit_type' => $room['unit_type'],
                'name' => $room['name'],
                'category' => $room['category'],
                'unit_count' => $room['units'],
                'price' => $room['price'],
                'price_max' => $room['price_max'] ?? null,
                'pricing_mode' => 'indicative',
                'description' => $room['description'],
                'max_guests' => $room['guests'],
                'bedrooms' => $room['bedrooms'] ?? null,
                'bed_configuration' => $room['beds'],
                'size_sqm' => $room['size'],
                'room_features' => AmenityList::clean($room['features']),
                'breakfast_included' => $room['breakfast'],
                'notes' => $room['notes'] ?? null,
                'occupancy_status' => 'unoccupied',
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $adminId,
                'sort_order' => $order,
            ]);
            $this->counts['rooms']++;
        }

        // Adds only the photos a room is missing (e.g. a download that failed on an earlier run).
        $existing = $record->images()->pluck('image_path');
        if ($existing->count() >= count($titles)) {
            return;
        }
        foreach ($this->storeAll($titles, 'accommodation-rooms/demo-'.$slug.'-'.Str::slug($room['name'])) as $stored) {
            if ($existing->contains($stored['image_path'])) {
                continue;
            }
            AccommodationRoomImage::create($stored + ['room_id' => $record->id, 'is_primary' => $existing->isEmpty(), 'sort_order' => $existing->count()]);
            $existing->push($stored['image_path']);
            $this->counts['room photos']++;
        }
    }

    /** Stores each Commons file in $directory (reusing one already there); skips any that fail. */
    private function storeAll(array $titles, string $directory): array
    {
        return array_values(array_filter(array_map(fn ($title) => $this->store($title, $directory), $titles)));
    }

    /** @return array{image_path: string, thumbnail_path: string, image_credit: string, image_source_url: ?string}|null */
    private function store(string $title, string $directory): ?array
    {
        $file = $this->commons[$title] ?? null;
        if ($file === null) {
            $this->command?->warn("{$title} wasn't found on Commons or isn't openly licensed; skipped.");

            return null;
        }
        $credit = 'Representative photo: '.($file['author'] !== '' ? $file['author'] : 'Unknown author')." / Wikimedia Commons, {$file['licence']}";
        // Shortened titles can clash, so a hash of the full title keeps each file name unique.
        $name = Str::slug(Str::limit(pathinfo(Str::after($title, 'File:'), PATHINFO_FILENAME), 50, '')).'-'.substr(md5($title), 0, 6);
        $disk = Storage::disk('public');

        if ($disk->exists("{$directory}/{$name}.webp") && $disk->exists("{$directory}/thumbnails/{$name}.webp")) {
            $this->counts['reused']++;

            return ['image_path' => "{$directory}/{$name}.webp", 'thumbnail_path' => "{$directory}/thumbnails/{$name}.webp",
                'image_credit' => $credit, 'image_source_url' => $file['page']];
        }

        usleep(self::$pauseMs * 1000);
        $response = $this->commonsRequest()->timeout(60)->get($file['url']);
        if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            $this->command?->warn("Download of {$title} failed (HTTP {$response->status()}); skipped.");

            return null;
        }
        $temp = storage_path('app/tmp-demo-accommodations');
        if (! is_dir($temp)) {
            mkdir($temp, 0775, true);
        }
        $local = "{$temp}/{$name}.img";
        file_put_contents($local, $response->body());
        try {
            $stored = $this->images->storeFromPath($local, $directory);
        } finally {
            @unlink($local);
        }
        $this->counts['downloaded']++;

        return $stored + ['image_credit' => $credit, 'image_source_url' => $file['page']];
    }

    /** Commons details for each title, 50 per request; files without an open licence are left out. */
    private function lookUpCommons(array $titles): void
    {
        $clean = fn ($html) => trim(preg_replace('/\s+/', ' ', strip_tags((string) $html)));
        foreach (array_chunk($titles, 50) as $chunk) {
            $response = $this->commonsRequest()->timeout(30)->get(self::COMMONS_API, [
                'action' => 'query', 'titles' => implode('|', $chunk), 'prop' => 'imageinfo', 'iiprop' => 'url|size|extmetadata',
                'iiurlwidth' => 1280, 'format' => 'json', 'formatversion' => 2,
            ]);
            if (! $response->successful()) {
                throw new RuntimeException('Commons lookup failed (HTTP '.$response->status().'); run the seeder again.');
            }
            $asked = collect($response->json('query.normalized', []))->pluck('from', 'to')->all();
            foreach ($response->json('query.pages', []) as $page) {
                $info = $page['imageinfo'][0] ?? null;
                $licence = $clean($info['extmetadata']['LicenseShortName']['value'] ?? '');
                if (! $info || ! preg_match('/^(CC0|Public domain|PD|CC BY(-SA)? \d)/i', $licence)) {
                    continue;
                }
                $this->commons[$asked[$page['title']] ?? $page['title']] = [
                    'url' => $info['thumburl'] ?? $info['url'],
                    'page' => $info['descriptionurl'] ?? null,
                    'author' => Str::limit($clean($info['extmetadata']['Artist']['value'] ?? ''), 120, ''),
                    'licence' => $licence,
                ];
            }
        }
    }

    /** A Commons request that waits and retries when rate limited (as images:representative). */
    private function commonsRequest(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => config('services.wikimedia.user_agent')])->retry(
            4,
            fn (int $attempt, \Throwable $e) => $e instanceof RequestException && $e->response->header('Retry-After') !== ''
                ? min(60, (int) $e->response->header('Retry-After')) * 1000
                : self::$pauseMs * 4 * $attempt,
            fn (\Throwable $e) => ! $e instanceof RequestException || in_array($e->response->status(), [429, 500, 502, 503, 504], true),
            throw: false,
        );
    }

    /** Deletes exactly the listings in the data file (rooms and photos cascade) and their photo folders. */
    private function remove(array $listings): void
    {
        $removed = 0;
        foreach ($listings as $data) {
            $town = DB::table('municipalities')->where('name', $data['town'])->value('id');
            $listing = Accommodation::where('name', $data['name'])->where('municipality_id', $town)->first();
            if ($listing) {
                DB::transaction(function () use ($listing) {
                    Rating::where('rateable_type', Accommodation::class)->where('rateable_id', $listing->id)->delete();
                    ListingEstimate::where('estimable_type', Accommodation::class)->where('estimable_id', $listing->id)->delete();
                    $listing->delete();
                });
                $removed++;
            }
            $disk = Storage::disk('public');
            $disk->deleteDirectory("accommodations/demo-{$data['slug']}");
            foreach ($data['rooms'] as $room) {
                $disk->deleteDirectory('accommodation-rooms/demo-'.$data['slug'].'-'.Str::slug($room['name']));
            }
        }
        $this->command?->info("Demo accommodations removed: {$removed}.");
    }
}

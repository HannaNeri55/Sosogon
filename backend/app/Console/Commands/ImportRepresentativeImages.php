<?php

namespace App\Console\Commands;

use App\Services\ImageOptimizationService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gives activities and room types without an image one from database/seeders/data/
 * representative_images.php: either a photo from the destination's own gallery (copied, so
 * removing it from the gallery never breaks the activity), or an openly licensed Wikimedia
 * Commons photo that represents the activity or room category, downloaded and optimized like
 * any upload. Commons photos are credited "Representative photo: author / Wikimedia Commons,
 * licence" with their source page. Records that already have an image are never changed, so
 * running it again only fills what's still missing.
 */
class ImportRepresentativeImages extends Command
{
    protected $signature = 'images:representative
        {--dry-run : List what would be stored without downloading or writing}
        {--data= : Mapping file to use instead of database/seeders/data/representative_images.php}';

    protected $description = 'Fill missing activity and room type images (destination photos or credited representative Commons photos)';

    private const COMMONS_API = 'https://commons.wikimedia.org/w/api.php';

    /** Pause between Commons downloads, in milliseconds, to stay well inside its rate limits. */
    public static int $pauseMs = 1500;

    /** Commons file details by title, looked up 50 at a time before anything is stored. */
    private array $commons = [];

    public function handle(ImageOptimizationService $images): int
    {
        $data = require $this->option('data') ?: database_path('seeders/data/representative_images.php');
        $dry = (bool) $this->option('dry-run');
        $stats = ['copied' => 0, 'downloaded' => 0, 'kept' => 0, 'skipped' => 0];
        $this->lookUpCommons(collect($data['activities'])->merge($data['room_types'])->pluck('commons')->filter()->unique()->values()->all());

        foreach ($data['activities'] as $id => $entry) {
            $activity = DB::table('activities')->where('id', $id)->first();
            if (! $activity || $activity->name !== $entry['name']) {
                $this->warn("Activity #{$id} ({$entry['name']}): not found or renamed, skipped.");
                $stats['skipped']++;

                continue;
            }
            if (! empty($activity->image_path)) {
                $stats['kept']++;

                continue;
            }
            $stored = $this->store($entry, "activities/{$id}", (int) $activity->destination_id, $images, $dry);
            if ($stored === null) {
                $stats['skipped']++;

                continue;
            }
            $stats[$stored['kind']]++;
            if (! $dry) {
                DB::table('activities')->where('id', $id)->update(Arr::only($stored, ['image_path', 'thumbnail_path', 'image_credit', 'image_source_url']) + ['updated_at' => now()]);
            }
            $this->line("Activity #{$id} {$entry['name']}: {$stored['kind']} ".($stored['image_path'] ?? ''));
        }

        foreach ($data['room_types'] as $key => $entry) {
            [$accommodationId, $name] = explode('|', $key, 2);
            $type = DB::table('accommodation_room_types')->where('accommodation_id', (int) $accommodationId)->where('name', $name)->first();
            if (! $type) {
                $this->warn("Room type {$key}: not found, skipped.");
                $stats['skipped']++;

                continue;
            }
            if (! empty($type->image_path)) {
                $stats['kept']++;

                continue;
            }
            $stored = $this->store($entry, "accommodation-room-types/{$type->id}", null, $images, $dry);
            if ($stored === null) {
                $stats['skipped']++;

                continue;
            }
            $stats[$stored['kind']]++;
            if (! $dry) {
                DB::table('accommodation_room_types')->where('id', $type->id)->update(Arr::only($stored, ['image_path', 'thumbnail_path', 'image_credit', 'image_source_url']) + ['updated_at' => now()]);
                // The option copied from this room type is what tourists see.
                $optionId = DB::table('accommodation_rooms')->where('legacy_room_type_id', $type->id)->value('id');
                if ($optionId && ! DB::table('accommodation_room_images')->where('room_id', $optionId)->exists()) {
                    DB::table('accommodation_room_images')->insert(['room_id' => $optionId, 'is_primary' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()] + Arr::only($stored, ['image_path', 'thumbnail_path', 'image_credit', 'image_source_url']));
                }
            }
            $this->line("Room type {$key}: {$stored['kind']} ".($stored['image_path'] ?? ''));
        }

        $this->info(collect($stats)->map(fn ($n, $k) => "{$n} {$k}")->implode(', '));

        return self::SUCCESS;
    }

    /** Stores the entry's image under $directory; null when it can't (reason already printed). */
    private function store(array $entry, string $directory, ?int $destinationId, ImageOptimizationService $images, bool $dry): ?array
    {
        if (isset($entry['destination_image'])) {
            // A neighbouring place's photo (e.g. the museum inside a park) names its destination explicitly.
            $destinationId = $entry['destination'] ?? $destinationId;
            $source = DB::table('destination_images')->where('id', $entry['destination_image'])->first();
            if (! $source || ($destinationId !== null && (int) $source->destination_id !== $destinationId)) {
                $this->warn("{$directory}: destination image #{$entry['destination_image']} isn't in destination #{$destinationId}'s gallery, skipped.");

                return null;
            }
            $disk = Storage::disk('public');
            if (! $disk->exists($source->image_path)) {
                $this->warn("{$directory}: gallery file {$source->image_path} is missing, skipped.");

                return null;
            }
            $imagePath = "{$directory}/".basename($source->image_path);
            $thumbnailPath = $source->thumbnail_path && $disk->exists($source->thumbnail_path)
                ? "{$directory}/thumbnails/".basename($source->thumbnail_path)
                : null;
            if (! $dry) {
                $disk->copy($source->image_path, $imagePath);
                if ($thumbnailPath) {
                    $disk->copy($source->thumbnail_path, $thumbnailPath);
                }
            }

            return ['kind' => 'copied', 'image_path' => $imagePath, 'thumbnail_path' => $thumbnailPath,
                'image_credit' => $source->credit, 'image_source_url' => $source->source_url];
        }

        $file = $this->commons[$entry['commons']] ?? null;
        if ($file === null) {
            $this->warn("{$directory}: Commons file {$entry['commons']} not found or not openly licensed, skipped.");

            return null;
        }
        $credit = 'Representative photo: '.($file['author'] !== '' ? $file['author'] : 'Unknown author')." / Wikimedia Commons, {$file['licence']}";
        if ($dry) {
            return ['kind' => 'downloaded', 'image_path' => "{$directory}/…", 'image_credit' => $credit, 'image_source_url' => $file['page']];
        }

        usleep(self::$pauseMs * 1000);
        $response = $this->commonsRequest()->timeout(60)->get($file['url']);
        if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            $this->warn("{$directory}: download of {$entry['commons']} failed (HTTP {$response->status()}, {$file['url']}), skipped.");

            return null;
        }
        $temp = storage_path('app/tmp-representative');
        if (! is_dir($temp)) {
            mkdir($temp, 0775, true);
        }
        $local = $temp.'/'.Str::slug(Str::limit(pathinfo(Str::after($entry['commons'], 'File:'), PATHINFO_FILENAME), 60, '')).'.img';
        file_put_contents($local, $response->body());
        try {
            $stored = $images->storeFromPath($local, $directory);
        } finally {
            @unlink($local);
        }

        return ['kind' => 'downloaded'] + $stored + ['image_credit' => $credit, 'image_source_url' => $file['page']];
    }

    /**
     * Looks up each Commons file's 1600 px download URL, page, author and licence (50 titles per
     * request). Files without an open licence (CC0, public domain, CC BY, CC BY-SA) are left out.
     */
    private function lookUpCommons(array $titles): void
    {
        $clean = fn ($html) => trim(preg_replace('/\s+/', ' ', strip_tags((string) $html)));
        foreach (array_chunk($titles, 50) as $chunk) {
            $response = $this->commonsRequest()->timeout(30)->get(self::COMMONS_API, [
                'action' => 'query', 'titles' => implode('|', $chunk), 'prop' => 'imageinfo', 'iiprop' => 'url|size|extmetadata',
                'iiurlwidth' => 1600, 'format' => 'json', 'formatversion' => 2,
            ]);
            if (! $response->successful()) {
                $this->warn('Commons lookup failed (HTTP '.$response->status().'); those files are skipped.');

                continue;
            }
            // Commons may normalize a title (e.g. underscores); map results back to the title we asked for.
            $asked = collect($response->json('query.normalized', []))->pluck('from', 'to')->all();
            foreach ($response->json('query.pages', []) as $page) {
                $info = $page['imageinfo'][0] ?? null;
                $licence = $clean($info['extmetadata']['LicenseShortName']['value'] ?? '');
                if (! $info || ! preg_match('/^(CC0|Public domain|PD|CC BY(-SA)? \d)/i', $licence)) {
                    continue;
                }
                $this->commons[$asked[$page['title']] ?? $page['title']] = [
                    'url' => $this->thumbnailUrl($info),
                    'page' => $info['descriptionurl'] ?? null,
                    'author' => Str::limit($clean($info['extmetadata']['Artist']['value'] ?? ''), 120, ''),
                    'licence' => $licence,
                ];
            }
        }
    }

    /**
     * The 1600 px thumbnail, or, when the original is no wider than that (Commons then hands back
     * the original, whose downloads it throttles), the largest standard thumbnail step below it.
     */
    private function thumbnailUrl(array $info): string
    {
        $url = $info['thumburl'] ?? $info['url'];
        if (str_contains($url, '/thumb/')) {
            return $url;
        }
        $original = strtok($info['url'], '?');
        $step = collect([1280, 960, 500])->first(fn ($width) => $width < ($info['width'] ?? 0));
        if ($step === null || ! str_contains($original, '/wikipedia/commons/')) {
            return $original;
        }

        return str_replace('/wikipedia/commons/', '/wikipedia/commons/thumb/', $original)."/{$step}px-".basename($original);
    }

    /** A Commons request that waits and retries when rate limited, honouring Retry-After. */
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
}

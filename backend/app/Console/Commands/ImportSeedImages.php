<?php

namespace App\Console\Commands;

use App\Services\ImageOptimizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ImportSeedImages extends Command
{
    protected $signature = 'images:import-seed {--dry-run : Report matches and new records without writing} {--replace-borrowed : Swap a stand-in photo (see storage/app/borrowed-images.json) for a real one found in a matching folder}';

    protected $description = 'Attach one cover photo per place from storage/app/seed-images, creating records for folders that match nothing';

    // Top-level folders that are not places: seeder sources plus the activity/room/menu/souvenir photo sets.
    private const ROOT_FLAT = ['accommodations', 'destinations', 'events', 'food-beverages', 'retail-products', 'retail-shops', 'activity', 'Menu', 'Room', 'Souviners'];

    private const MUNI_TOKENS = [
        'bulan' => 'Bulan', 'bacon' => 'Sorsogon City', 'sorsogon' => 'Sorsogon City', 'barcelona' => 'Barcelona', 'bulusan' => 'Bulusan',
        'casiguran' => 'Casiguran', 'castilla' => 'Castilla', 'donsol' => 'Donsol', 'gubat' => 'Gubat', 'irosin' => 'Irosin',
        'juban' => 'Juban', 'magallanes' => 'Magallanes', 'magdalena' => 'Santa Magdalena', 'matnog' => 'Matnog',
        'pilar' => 'Pilar', 'prieto diaz' => 'Prieto Diaz',
    ];

    /** table => [image table, fk, image dir, id column type] */
    private const TYPES = [
        'destination' => ['destinations', 'destination_images', 'destination_id', 'destinations'],
        'accommodation' => ['accommodations', 'accommodation_images', 'accommodation_id', 'accommodations'],
        'food' => ['food_beverages', 'food_beverage_images', 'food_beverage_id', 'food-beverages'],
        'retail' => ['retail_products', 'retail_product_images', 'retail_product_id', 'retail-products'],
    ];

    private array $municipalities = [];

    private array $records = [];

    public function handle(ImageOptimizationService $images): int
    {
        $dry = (bool) $this->option('dry-run');
        $root = storage_path('app/seed-images');

        foreach (DB::table('municipalities')->get() as $m) {
            $this->municipalities[Str::lower($m->name)] = $m;
        }
        $borrowed = $this->loadBorrowed();
        foreach (self::TYPES as $type => [$table]) {
            foreach (DB::table($table)->get(['id', 'name', 'municipality_id']) as $r) {
                $hasImage = DB::table(self::TYPES[$type][1])->where(self::TYPES[$type][2], $r->id)->exists();
                $this->records[] = ['type' => $type, 'id' => $r->id, 'name' => $r->name, 'norm' => $this->norm($r->name), 'muni' => (int) $r->municipality_id, 'has' => $hasImage, 'borrowed' => isset($borrowed[$type.':'.$r->id])];
            }
        }

        $folders = $this->scan($root);
        // Folders that name their municipality first, so proper names win over the province-wide ones (RESTAURANTS etc.).
        usort($folders, fn ($a, $b) => ($a['muni'] ? 0 : 1) <=> ($b['muni'] ? 0 : 1) ?: strcmp($a['rel'], $b['rel']));
        $stats = ['attached' => 0, 'has_image' => 0, 'created' => 0, 'no_cover' => 0];

        DB::beginTransaction();
        try {
            foreach ($folders as $f) {
                $muni = $f['muni'];
                if (isset(self::MUNI_OVERRIDE[Str::lower($f['name'])])) {
                    $muni = $this->municipalities[self::MUNI_OVERRIDE[Str::lower($f['name'])]];   // place filed under another town's folder
                }
                if ($muni && $f['type'] === 'retail' && preg_match('/^(seafood|dish|food|kakanin|crafts|handicrafts|products?)$/i', $f['name'])) {
                    $f['name'] = "{$muni->name} ".Str::title($f['name']);   // generic product folders are named per municipality
                }
                $match = $this->match($f['name'], $muni, $f['type']);
                $cover = $this->cover($f['files']);
                if (! $cover) {
                    $this->warn("NOCOVER  {$f['rel']}");
                    $stats['no_cover']++;

                    continue;
                }

                if ($match) {
                    if ($match['has'] && $match['borrowed'] && $this->option('replace-borrowed')) {
                        $this->info("REPLACE  {$f['rel']}  ->  [{$match['type']}] {$match['name']}  (stand-in swapped for real photo)");
                        if (! $dry) {
                            $this->dropImages($images, $match['type'], $match['id']);
                        }
                        $this->attach($images, $match['type'], $match['id'], $match['name'], $cover, $dry);
                        if (! $dry) {
                            $this->forgetBorrowed($match['type'], $match['id']);
                        }
                        $stats['replaced'] = ($stats['replaced'] ?? 0) + 1;

                        continue;
                    }
                    if ($match['has']) {
                        $stats['has_image']++;
                        $this->line("HAS-IMG  {$f['rel']}  ->  {$match['name']}");

                        continue;
                    }
                    $this->info("ATTACH   {$f['rel']}  ->  [{$match['type']}] {$match['name']}");
                    $this->attach($images, $match['type'], $match['id'], $match['name'], $cover, $dry);
                    $this->markHas($match['type'], $match['id']);
                    $stats['attached']++;

                    continue;
                }

                if ($f['type'] === 'any') {
                    $this->warn("NO MATCH {$f['rel']}  (REPLACEMENTS folders only replace existing records; name the folder exactly like the record)");

                    continue;
                }

                $note = '';
                if (! $muni) {
                    // Top-level folders (RESTAURANTS, ACCOMODATIONS, LOCAL PRODUCTS) carry no municipality:
                    // borrow it from a similar record of any type, else fall back to the provincial capital for admin review.
                    $near = isset(self::MUNI_OVERRIDE[Str::lower($f['name'])]) ? null : $this->match($f['name'], null, $f['type'], true);
                    $muni = isset(self::MUNI_OVERRIDE[Str::lower($f['name'])]) ? $this->municipalities[self::MUNI_OVERRIDE[Str::lower($f['name'])]] : ($near ? DB::table('municipalities')->find($near['muni']) : $this->municipalities['sorsogon city']);
                    $note = $near ? "  (municipality from '{$near['name']}')" : (isset(self::MUNI_OVERRIDE[Str::lower($f['name'])]) ? '  (municipality set by override)' : '  (municipality ASSUMED: Sorsogon City)');
                }

                $type = $f['type'];
                $created = $this->createRecord($type, $f, $muni);
                $this->info("CREATE   {$f['rel']}  ->  [{$type}] {$created['name']} / {$muni->name}{$note}");
                $this->attach($images, $type, $created['id'], $created['name'], $cover, $dry);
                $this->records[] = ['type' => $type, 'id' => $created['id'], 'name' => $created['name'], 'norm' => $this->norm($created['name']), 'muni' => $muni->id, 'has' => true, 'borrowed' => false];
                $stats['created']++;
            }
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed, rolled back: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(($dry ? '[dry-run] ' : '').json_encode($stats));

        return self::SUCCESS;
    }

    // ---------- scanning ----------

    private function scan(string $root): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $byDir = [];
        foreach ($it as $file) {
            if (! $file->isFile() || ! preg_match('/\.(jpe?g|png|webp|jfif|avif)$/i', $file->getFilename())) {
                continue;
            }
            $byDir[$file->getPath()][] = $file->getPathname();
        }
        ksort($byDir);

        foreach ($byDir as $dir => $files) {
            $rel = str_replace('\\', '/', substr($dir, strlen($root) + 1));
            $parts = explode('/', $rel);
            if (in_array($parts[0], self::ROOT_FLAT, true)) {
                continue;
            }
            $name = end($parts);
            // A place folder can nest one level deeper (Bantigui/Bistro di bantigui): the deepest folder is the place.
            $lowerPath = Str::lower($rel);
            $muniName = $this->muniFrom($parts[0]);
            natsort($files);
            $type = match (true) {
                Str::lower($parts[0]) === 'replacements' => 'any',   // drop-in folder for real photos: matches a record of any type by name
                str_contains($lowerPath, 'restaurants') => 'food',
                str_contains($lowerPath, 'product') || str_contains($lowerPath, '/food/') => 'retail',
                str_contains($lowerPath, 'hotel') || str_contains($lowerPath, 'accomodations') => 'accommodation',
                default => 'destination',
            };
            if ($type === 'destination' && preg_match('/homestay|\binn\b|lodge|hostel|bed ?& ?breakfast|cottage|hotel/i', $name)) {
                $type = 'accommodation';
            }
            $out[] = [
                'rel' => $rel,
                'name' => $this->cleanName($name),
                'files' => array_values($files),
                'muni' => $muniName ? ($this->municipalities[Str::lower($muniName)] ?? null) : null,
                'type' => $type,
                'path' => $lowerPath,
            ];
        }

        return $out;
    }

    private function muniFrom(string $first): ?string
    {
        $l = Str::lower($first);
        foreach (self::MUNI_TOKENS as $token => $muni) {
            if (str_contains($l, $token)) {
                return $muni;
            }
        }

        return null;
    }

    /** Largest decodable photo in the folder (ties resolved by natural filename order). */
    private function cover(array $files): ?string
    {
        $best = null;
        $bestArea = 0;
        foreach ($files as $f) {
            $size = @getimagesize($f);
            $area = $size ? $size[0] * $size[1] : 0;
            if ($size && $size[0] >= 150 && $size[1] >= 150 && $area > $bestArea) {
                $best = $f;
                $bestArea = $area;
            }
        }

        return $best;
    }

    // ---------- names ----------

    private function cleanName(string $s): string
    {
        // Map Unicode mathematical alphanumerics (bold/italic fancy text) to plain ASCII.
        $s = preg_replace_callback('/[\x{1D400}-\x{1D6A3}]/u', function ($m) {
            $i = (mb_ord($m[0]) - 0x1D400) % 52;

            return chr($i < 26 ? 65 + $i : 97 + $i - 26);
        }, $s);
        $s = str_replace(['’', '‘', '_'], ["'", "'", "'"], $s);
        if (! str_contains($s, ' ')) {
            $s = preg_replace('/([a-z])([A-Z])/', '$1 $2', $s);        // CascadingWater -> Cascading Water
            $s = str_replace('-', ' ', $s);                              // Incarizan-Tree-Tunnel
        }
        $s = trim(preg_replace('/\s+/', ' ', $s));
        $s = mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
        $s = preg_replace_callback('/(?<=\s)(De|Del|Sa|Ni|Na|Of|The|And|In)(?=\s)/', fn ($m) => Str::lower($m[1]), $s);
        $fixes = ['/\bRecid(?:encia|entia)\b/i' => 'Residencia', '/\bBotique\b/i' => 'Boutique', '/\bRresort\b/i' => 'Resort', '/\bLebanon\b/i' => 'Lebanon'];
        $s = preg_replace_callback('/(\d)(St|Nd|Rd|Th)\b/', fn ($m) => $m[1].Str::lower($m[2]), $s);
        foreach ($fixes as $re => $to) {
            $s = preg_replace($re, $to, $s);
        }

        return $s;
    }

    private function norm(string $s): string
    {
        $s = Str::lower(Str::ascii($s));
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        $s = preg_replace('/\b(the|and|of|a|resort|resorts|hotel|beach|inn|restaurant|cafe|shop|store|product|products)\b/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    private const NO_MATCH = ['villa isabel hotel', 'paguriran island'];

    private const ALIASES = ['juban park' => 'Juban Rizal Park'];

    /** Folders whose municipality is known from context, not from the folder path. */
    private const MUNI_OVERRIDE = ['villa luisa' => 'pilar', 'gang-gang highlands' => 'sorsogon city'];

    private const COMPAT = [
        'destination' => ['destination', 'accommodation'],
        'accommodation' => ['accommodation'],
        'food' => ['food'],
        'retail' => ['retail'],
        'any' => ['destination', 'accommodation', 'food', 'retail'],
    ];

    private function sorted(string $n): string
    {
        $t = explode(' ', $n);
        sort($t);

        return implode(' ', $t);
    }

    /** Best existing record for a folder. $any = ignore type/ratio limits (used only to infer a municipality). */
    private function match(string $name, $muni, string $type, bool $any = false): ?array
    {
        $key = Str::lower($name);
        if (in_array($key, self::NO_MATCH, true) && ! $any) {
            foreach ($this->records as $r) {   // only an exact name (a record this command already created) may match
                if (Str::lower($r['name']) === $key) {
                    return $r;
                }
            }

            return null;
        }
        if (isset(self::ALIASES[$key])) {
            $name = self::ALIASES[$key];
        }
        $n = $this->norm($name) ?: Str::lower($name);
        $best = null;
        $bestPct = 0;
        foreach ($this->records as $r) {
            if ($muni && $r['muni'] !== $muni->id || $r['norm'] === '') {
                continue;
            }
            $compat = in_array($r['type'], self::COMPAT[$type], true);
            similar_text($this->sorted($n), $this->sorted($r['norm']), $pct);
            $short = min(strlen($n), strlen($r['norm']));
            $long = max(strlen($n), strlen($r['norm']));
            $contains = $short >= 5 && (str_contains($n, $r['norm']) || str_contains($r['norm'], $n));
            $ratioOk = $any || $short / $long >= 0.45;
            $score = ($contains && $ratioOk) ? max($pct, 90) : $pct;
            $needed = $any ? 88 : ($compat ? 80 : 92);
            if ($score >= $needed && $score > $bestPct) {
                $best = $r;
                $bestPct = $score;
            }
        }

        return $best;
    }
    private const BORROWED_FILE = 'app/borrowed-images.json';

    /** @return array<string, true> "type:id" of records whose current cover is a borrowed stand-in */
    private function loadBorrowed(): array
    {
        $file = storage_path(self::BORROWED_FILE);
        $map = ['destinations' => 'destination', 'food_beverages' => 'food', 'retail_products' => 'retail'];
        $out = [];
        foreach (is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [] as $row) {
            $out[($map[$row['t']] ?? $row['t']).':'.$row['id']] = true;
        }

        return $out;
    }

    private function forgetBorrowed(string $type, int $id): void
    {
        $file = storage_path(self::BORROWED_FILE);
        $map = ['destinations' => 'destination', 'food_beverages' => 'food', 'retail_products' => 'retail'];
        $rows = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        $rows = array_values(array_filter($rows, fn ($r) => ! (($map[$r['t']] ?? $r['t']) === $type && (int) $r['id'] === $id)));
        file_put_contents($file, json_encode($rows, JSON_PRETTY_PRINT));
    }

    private function dropImages(ImageOptimizationService $images, string $type, int $id): void
    {
        [, $imageTable, $fk] = self::TYPES[$type];
        foreach (DB::table($imageTable)->where($fk, $id)->get(['image_path', 'thumbnail_path']) as $img) {
            $images->delete($img->image_path, $img->thumbnail_path);
        }
        DB::table($imageTable)->where($fk, $id)->delete();
    }

    private function markHas(string $type, int $id): void
    {
        foreach ($this->records as &$r) {
            if ($r['type'] === $type && $r['id'] === $id) {
                $r['has'] = true;
            }
        }
    }

    // ---------- writing ----------

    private function attach(ImageOptimizationService $images, string $type, int $id, string $name, string $cover, bool $dry): void
    {
        [, $imageTable, $fk, $dir] = self::TYPES[$type];
        if ($dry) {
            return;
        }
        try {
            $paths = $images->storeFromPath($cover, "{$dir}/{$id}");
        } catch (\Throwable $e) {
            $this->warn("  could not process {$cover}: ".$e->getMessage());

            return;
        }
        $row = [$fk => $id, 'image_path' => $paths['image_path'], 'thumbnail_path' => $paths['thumbnail_path'], 'sort_order' => 0, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()];
        if ($type === 'destination') {
            $row['alt_text'] = $name;
        }
        DB::table($imageTable)->insert($row);
    }

    private function coordinates($muni, string $table): array
    {
        $avg = DB::table($table)->where('municipality_id', $muni->id)->whereNotNull('latitude')
            ->selectRaw('AVG(latitude) a, AVG(longitude) b')->first();
        if ($avg && $avg->a) {
            return [round((float) $avg->a, 7), round((float) $avg->b, 7)];
        }
        if ($muni->latitude && $muni->longitude) {
            return [(float) $muni->latitude, (float) $muni->longitude];
        }

        return [12.9742, 124.0049];
    }

    private function createRecord(string $type, array $f, $muni): array
    {
        [$table] = self::TYPES[$type];
        [$lat, $lng] = $this->coordinates($muni, $table);
        $name = $f['name'];
        if ($type === 'retail' && preg_match('/^(seafood|dish|food|kakanin|crafts|handicrafts|products?)$/i', $name)) {
            $name = "{$muni->name} ".Str::title($name);
        }
        $t = Str::lower($name.' '.$f['path']);
        $base = [
            'name' => $name,
            'municipality_id' => $muni->id,
            'barangay_id' => null,
            'address' => "{$muni->name}, Sorsogon",
            'latitude' => $lat,
            'longitude' => $lng,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        switch ($type) {
            case 'destination':
                $catName = match (true) {
                    (bool) preg_match('/beach|sandbar|point|shore|coast|rompeolas/', $t) => 'Beach',
                    (bool) preg_match('/island/', $t) => 'Island',
                    (bool) preg_match('/falls|cascad/', $t) => 'Waterfall',
                    (bool) preg_match('/hills|mountain|mt\.|deck|tower|watch/', $t) => 'Mountain',
                    (bool) preg_match('/church|parish|shrine|grotto|station of the cross/', $t) => 'Church',
                    (bool) preg_match('/monument|museo|museum|heritage|lighthouse|ruin|bridge|capitol|historic/', $t) => 'Historical',
                    (bool) preg_match('/park|plaza|road/', $t) => 'Park',
                    (bool) preg_match('/resort|camp|farm|villa|casa|homestay/', $t) => 'Resort',
                    default => 'Eco-Tourism',
                };
                $id = DB::table('destinations')->insertGetId($base + [
                    'description' => "{$name} is a ".Str::lower($catName)." destination in {$muni->name}, Sorsogon. More details coming soon.",
                    'category_id' => DB::table('categories')->where('type', 'destination')->where('name', $catName)->value('id'),
                    'entrance_fee' => null,
                    'status' => 'published',
                    'created_by' => 1,
                ]);
                break;
            case 'accommodation':
                $atype = match (true) {
                    str_contains($t, 'resort') => 'Resort',
                    str_contains($t, 'hotel') => 'Hotel',
                    (bool) preg_match('/homestay|cottage|house|krib|lodge|hostel|bed/', $t) => 'Homestay',
                    default => 'Inn',
                };
                $id = DB::table('accommodations')->insertGetId($base + [
                    'description' => "{$name} is a ".Str::lower($atype)." in {$muni->name}, Sorsogon. More details coming soon.",
                    'type' => $atype,
                    'price_range' => 'moderate',
                    'status' => 'pending',
                ]);
                break;
            case 'food':
                $cuisine = match (true) {
                    (bool) preg_match('/grill|seafood|bulalo|fish/', $t) => 'Seafood',
                    (bool) preg_match('/cafe|coffee|kuzina|curia/', $t) => 'Cafe',
                    default => 'Filipino',
                };
                $id = DB::table('food_beverages')->insertGetId($base + [
                    'description' => "{$name} is an eatery in {$muni->name}, Sorsogon. More details coming soon.",
                    'cuisine_type' => $cuisine,
                    'price_range' => 'moderate',
                    'status' => 'pending',
                ]);
                break;
            default:
                $souvenir = (bool) preg_match('/craft|bayong|abaca|slipper|bag|soap|oil|shell|handicraft/', $t);
                $id = DB::table('retail_products')->insertGetId($base + [
                    'description' => "{$name} is a local product from {$muni->name}, Sorsogon. More details coming soon.",
                    'product_type' => $souvenir ? 'Souvenir' : 'Local Food Product',
                    'status' => 'pending',
                ]);
        }

        return ['id' => $id, 'name' => $name];
    }
}

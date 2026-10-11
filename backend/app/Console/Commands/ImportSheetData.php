<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImportSheetData extends Command
{
    protected $signature = 'sheets:import {dataset : destinations|accommodations|food|retail} {--dry-run : Report what would happen without writing} {--file= : Read this local CSV instead of downloading}';

    protected $description = 'Import a tab of the tourism Google Sheet, skipping rows whose name already exists (or is similar)';

    private const SHEET_ID = '13PpENWelo6NoSaOO381kE5Brpu3FXvG9';

    private const GIDS = [
        'destinations' => '20318762',
        'accommodations' => '1001030521',
        'food' => '2045797489',
        'retail' => '1563241955',
    ];

    private const TABLES = [
        'destinations' => 'destinations',
        'accommodations' => 'accommodations',
        'food' => 'food_beverages',
        'retail' => 'retail_products',
    ];

    // Sorsogon bounding box used to reject bad coordinates in the sheet.
    private const LAT = [12.4, 13.3];

    private const LNG = [123.4, 124.4];

    private array $municipalities = [];

    private array $existing = [];

    public function handle(): int
    {
        $dataset = $this->argument('dataset');
        if (! isset(self::GIDS[$dataset])) {
            $this->error('Unknown dataset.');

            return self::FAILURE;
        }

        $rows = $this->loadRows($dataset);
        if ($rows === null) {
            return self::FAILURE;
        }

        foreach (DB::table('municipalities')->get() as $m) {
            $this->municipalities[$this->key($m->name)] = $m;
        }

        $table = self::TABLES[$dataset];
        $dry = (bool) $this->option('dry-run');
        $inserted = 0;
        $skipped = 0;
        $problems = 0;

        $this->existing = DB::table($table)->get(['name', 'municipality_id'])
            ->map(fn ($r) => [$this->norm($r->name), (int) $r->municipality_id, $r->name])->all();

        DB::beginTransaction();
        try {
            foreach ($rows as $row) {
                $name = trim(preg_replace('/\s+/', ' ', $row['name']));
                if (str_starts_with($name, 'orsogon City ')) { // sheet typo (missing leading "S")
                    $name = 'S'.$name;
                }
                $muni = $this->resolveMunicipality($row['municipality']);
                if (! $muni) {
                    $this->warn("PROBLEM  {$name}: unknown municipality '{$row['municipality']}'");
                    $problems++;

                    continue;
                }

                $match = $this->findSimilar($name, $muni->id);
                if ($match) {
                    $this->line("SKIP     {$name}  ~  {$match}");
                    $skipped++;

                    continue;
                }

                $record = $this->build($dataset, $row, $name, $muni);
                if ($record === null) {
                    $problems++;

                    continue;
                }

                DB::table($table)->insert($record + ['created_at' => now(), 'updated_at' => now()]);
                $this->existing[] = [$this->norm($name), $muni->id, $name];
                $this->info("INSERT   {$name}  ({$muni->name})");
                $inserted++;
            }

            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed, rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(($dry ? '[dry-run] ' : '')."{$dataset}: inserted {$inserted}, skipped {$skipped}, problems {$problems}");

        return self::SUCCESS;
    }

    // ---------- loading ----------

    private function loadRows(string $dataset): ?array
    {
        if ($file = $this->option('file')) {
            $csv = file_get_contents($file);
        } else {
            $url = 'https://docs.google.com/spreadsheets/d/'.self::SHEET_ID.'/export?format=csv&gid='.self::GIDS[$dataset];
            $res = Http::timeout(60)->get($url);
            if (! $res->successful()) {
                $this->error('Download failed: HTTP '.$res->status());

                return null;
            }
            $csv = $res->body();
        }

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        $header = array_map(fn ($h) => Str::lower(trim($h)), fgetcsv($fh) ?: []);

        $col = fn (array $r, array $names) => trim((string) ($r[collect($names)->map(fn ($n) => array_search($n, $header, true))->first(fn ($i) => $i !== false)] ?? ''));
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            $name = $col($r, ['destination name', 'business name']);
            if ($name === '') {
                continue;
            }
            $rows[] = [
                'name' => $name,
                'description' => $col($r, ['description']),
                'type' => $col($r, ['category', 'business type']),
                'municipality' => $col($r, ['municipality/city']),
                'barangay' => $col($r, ['barangay']),
                'address' => $col($r, ['address']),
                'contact' => $col($r, ['contact']),
                'lat' => $col($r, ['latitude']),
                'lng' => $col($r, ['longitude']),
                'fee' => $col($r, ['entrance fee']),
                'hours' => $col($r, ['operating hours']),
                'status' => $col($r, ['status']),
                'notes' => $col($r, ['notes']),
            ];
        }
        fclose($fh);

        return $rows;
    }

    // ---------- matching ----------

    private function key(string $s): string
    {
        return Str::lower(trim($s));
    }

    private function norm(string $s): string
    {
        $s = Str::lower(Str::ascii($s));
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        $s = preg_replace('/\b(the|and|of|a|resort|hotel|beach|inn|restaurant|cafe|shop|store)\b/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }

    private function findSimilar(string $name, int $municipalityId): ?string
    {
        $n = $this->norm($name);
        if ($n === '') {
            $n = Str::lower($name);
        }
        foreach ($this->existing as [$en, $mid, $orig]) {
            if ($mid !== $municipalityId || $en === '') {
                continue;
            }
            similar_text($n, $en, $pct);
            if ($n === $en || $pct >= 85 || (strlen($n) >= 6 && strlen($en) >= 6 && (str_contains($n, $en) || str_contains($en, $n)))) {
                return $orig;
            }
        }

        return null;
    }

    private function resolveMunicipality(string $name)
    {
        $k = $this->key($name);
        // Bacon and Sorsogon East are districts of Sorsogon City, not separate municipalities.
        if (in_array($k, ['bacon', 'sorsogon east', 'bacon district'], true)) {
            $k = 'sorsogon city';
        }

        return $this->municipalities[$k] ?? null;
    }

    private function resolveBarangay(int $municipalityId, string $name)
    {
        if ($name === '') {
            return null;
        }
        $n = Str::lower(trim($name));

        return DB::table('barangays')->where('municipality_id', $municipalityId)
            ->whereRaw('LOWER(name) = ?', [$n])->first()
            ?? DB::table('barangays')->where('municipality_id', $municipalityId)
                ->whereRaw('LOWER(name) LIKE ?', ['%'.$n.'%'])->first();
    }

    // ---------- value parsing ----------

    private function coord(string $raw, array $range): ?float
    {
        if (! preg_match('/-?\d+(?:\.\d+)?/', $raw, $m)) {
            return null;
        }
        $v = (float) $m[0];
        if (preg_match('/[SW]\b/i', $raw) && $v > 0) {
            $v = -$v;
        }

        return ($v >= $range[0] && $v <= $range[1]) ? $v : null;
    }

    /** Sheet coordinates, else barangay, else the mean of records already in that municipality, else Sorsogon City. */
    private function coordinates(array $row, $muni, $barangay, string $table): array
    {
        $lat = $this->coord($row['lat'], self::LAT);
        $lng = $this->coord($row['lng'], self::LNG);
        if ($lat !== null && $lng !== null) {
            return [$lat, $lng];
        }
        if ($barangay && $barangay->latitude && $barangay->longitude) {
            return [(float) $barangay->latitude, (float) $barangay->longitude];
        }
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

    private function contactFields(string $contact): array
    {
        $phone = null;
        $site = null;
        if (preg_match('/(\+?\d[\d\s\-()]{6,18}\d)/', $contact, $m)) {
            $phone = substr(trim($m[1]), 0, 20);
        }
        if (preg_match('~(https?://\S+|(?:www\.)?[a-z0-9\-]+\.(?:org|com|ph|net)\S*)~i', $contact, $m)) {
            $site = substr(rtrim($m[1], '.,;·'), 0, 500);
        }

        return [$phone, $site];
    }

    private function description(array $row, $muni, string $what): string
    {
        $d = trim($row['description']);
        if ($d !== '') {
            return $d;
        }
        $place = $row['barangay'] !== '' ? "{$row['barangay']}, {$muni->name}" : $muni->name;

        return "{$row['name']} is a {$what} located in {$place}, Sorsogon.";
    }

    // ---------- record builders ----------

    private function build(string $dataset, array $row, string $name, $muni): ?array
    {
        $barangay = $this->resolveBarangay($muni->id, $row['barangay']);
        $table = self::TABLES[$dataset];
        [$lat, $lng] = $this->coordinates($row, $muni, $barangay, $table);
        [$phone, $site] = $this->contactFields($row['contact']);
        $address = $row['address'] !== '' ? substr($row['address'], 0, 500)
            : trim(($row['barangay'] !== '' ? $row['barangay'].', ' : '').$muni->name.', Sorsogon');
        $hours = $row['hours'] !== '' ? substr($row['hours'], 0, 255) : null;
        $t = Str::lower($row['type'].' '.$name.' '.$row['description']);
        $pending = $dataset !== 'destinations' && Str::contains(Str::lower($row['status']), 'research');
        $status = $pending ? 'pending' : 'approved';

        $base = [
            'name' => $name,
            'municipality_id' => $muni->id,
            'barangay_id' => $barangay->id ?? null,
            'address' => $address,
            'latitude' => $lat,
            'longitude' => $lng,
            'contact_number' => $phone,
            'website' => $site,
        ];

        switch ($dataset) {
            case 'destinations':
                $category = $this->destinationCategory($row['type']);
                if (! $category) {
                    $this->warn("PROBLEM  {$name}: no category for '{$row['type']}'");

                    return null;
                }
                $fee = null;
                if (preg_match('/\d+(?:\.\d+)?/', str_replace(',', '', $row['fee']), $m)) {
                    $fee = (float) $m[0];
                } elseif ($row['fee'] === '' || Str::contains(Str::lower($row['fee']), 'free')) {
                    $fee = 0;
                }
                $desc = $this->description($row, $muni, Str::lower($row['type']).' destination');
                if (strlen($row['fee']) > 12) {
                    $desc .= ' Entrance fee: '.$row['fee'];
                }

                return $base + [
                    'description' => $desc,
                    'category_id' => $category,
                    'operating_hours' => $hours,
                    'entrance_fee' => $fee,
                    'status' => 'published',
                    'created_by' => 1,
                ];

            case 'accommodations':
                $type = match (true) {
                    str_contains($t, 'resort') => 'Resort',
                    str_contains($t, 'hotel') => 'Hotel',
                    str_contains($t, 'homestay') || str_contains($t, 'kubo') || str_contains($t, 'camp') || str_contains($t, 'villa') || str_contains($t, 'house') => 'Homestay',
                    default => 'Inn',
                };
                $price = preg_match('/camp|kubo|hostel|pension|lodge|inn\b|budget/', $t) ? 'budget' : 'moderate';
                if (str_contains($t, 'boutique') || str_contains($t, 'luxury')) {
                    $price = 'luxury';
                }

                return $base + [
                    'description' => $this->description($row, $muni, Str::lower($type)),
                    'type' => $type,
                    'price_range' => $price,
                    'operating_hours' => $hours,
                    'status' => $status,
                ];

            case 'food':
                $cuisine = match (true) {
                    str_contains($t, 'seafood') => 'Seafood',
                    str_contains($t, 'italian') => 'Italian',
                    str_contains($t, 'bakery') || str_contains($t, 'bakeshop') => 'Bakery',
                    str_contains($t, 'cafe') || str_contains($t, 'coffee') => 'Cafe',
                    str_contains($t, 'snack') || str_contains($t, 'dessert') || str_contains($t, 'milk tea') => 'Snacks & Desserts',
                    str_contains($t, 'bicolano') || str_contains($t, 'filipino') || str_contains($t, 'carinderia') || str_contains($t, 'local') => 'Filipino',
                    default => 'Restaurant',
                };
                $price = match (true) {
                    (bool) preg_match('/carinderia|snack|milk tea|eatery|bakery|unlimited rice/', $t) => 'budget',
                    (bool) preg_match('/fine dining|heritage restaurant/', $t) => 'luxury',
                    default => 'moderate',
                };

                return $base + [
                    'description' => $this->description($row, $muni, Str::lower($row['type'])),
                    'cuisine_type' => $cuisine,
                    'price_range' => $price,
                    'operating_hours' => $hours,
                    'status' => $status,
                ];

            case 'retail':
                // Per the requester: food-like stalls are "Local Food Product", everything else (incl. hardware) is "Souvenir".
                $food = (bool) preg_match('/dried|seafood|delicac|pili|confection|bake|organic|fish|market|produce|food|cake/', $t);

                return $base + [
                    'description' => $this->description($row, $muni, Str::lower($row['type'])),
                    'product_type' => $food ? 'Local Food Product' : 'Souvenir',
                    'admin_notes' => trim($row['type'].($row['notes'] !== '' ? ' — '.$row['notes'] : '').($hours ? ' | Hours: '.$hours : '')),
                    'status' => $status,
                ];
        }

        return null;
    }

    private function destinationCategory(string $type): ?int
    {
        $map = [
            'beach' => 'Beach', 'mountain' => 'Mountain', 'waterfall' => 'Waterfall', 'historical' => 'Historical',
            'church' => 'Church', 'park' => 'Park', 'eco' => 'Eco-Tourism', 'island' => 'Island', 'resort' => 'Resort',
            'spring' => 'Eco-Tourism', 'lake' => 'Eco-Tourism',
        ];
        $lower = Str::lower($type);
        $best = null;
        $pos = PHP_INT_MAX;
        foreach ($map as $token => $cat) {
            $p = strpos($lower, $token);
            if ($p !== false && $p < $pos) {
                $pos = $p;
                $best = $cat;
            }
        }

        return $best ? DB::table('categories')->where('type', 'destination')->where('name', $best)->value('id') : null;
    }
}

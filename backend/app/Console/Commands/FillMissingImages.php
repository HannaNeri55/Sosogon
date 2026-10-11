<?php

namespace App\Console\Commands;

use App\Services\ImageOptimizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FillMissingImages extends Command
{
    protected $signature = 'images:fill-missing {--dry-run : Show the chosen photos without writing}';

    protected $description = 'Give records that have no image (and no photo folder of their own) one borrowed cover from a fitting seed-images folder';

    /** type => [table, image table, fk, image dir] */
    private const TYPES = [
        'destination' => ['destinations', 'destination_images', 'destination_id', 'destinations'],
        'food' => ['food_beverages', 'food_beverage_images', 'food_beverage_id', 'food-beverages'],
        'retail' => ['retail_products', 'retail_product_images', 'retail_product_id', 'retail-products'],
        'shop' => ['retail_shops', 'retail_shop_images', 'retail_shop_id', 'retail-shops'],
    ];

    // Same non-place folders as ImportSeedImages::ROOT_FLAT, kept out of the stand-in photo pool.
    private const ROOT_FLAT = ['accommodations', 'destinations', 'events', 'food-beverages', 'retail-products', 'retail-shops', 'activity', 'Menu', 'Room', 'Souviners'];

    /** Municipality => folder patterns holding that municipality's own food photos. */
    private const LOCAL_FOOD = [
        'Pilar' => ['#^PILAR/PRODUCTS/dish#', '#^PILAR/PRODUCTS/Seafood#'],
        'Magallanes' => ['#^MAGALLANES/PRODUCTS/food#'],
        'Santa Magdalena' => ['#^MAGDALENA/PRODUCTS/food#'],
        'Matnog' => ['#Bistro di bantigui#'],
    ];

    private array $pool = [];   // rel folder => remaining files

    private array $used = [];

    public function handle(ImageOptimizationService $images): int
    {
        $dry = (bool) $this->option('dry-run');
        $this->buildPool(storage_path('app/seed-images'));
        $municipalities = DB::table('municipalities')->pluck('name', 'id');
        $done = 0;
        $low = [];

        DB::beginTransaction();
        try {
            foreach (self::TYPES as $type => [$table, $imageTable, $fk, $dir]) {
                $rows = DB::table($table)->whereNotIn('id', DB::table($imageTable)->select($fk))->orderBy('id')->get(['id', 'name', 'municipality_id']);
                foreach ($rows as $r) {
                    $muni = $municipalities[$r->municipality_id] ?? '';
                    [$patterns, $confidence] = $this->patternsFor($type, mb_strtolower($r->name), $muni);
                    [$file, $folder, $reused] = $this->take($patterns);
                    if (! $file) {
                        $this->warn("NO PHOTO  [{$type}] {$r->name}");

                        continue;
                    }
                    $conf = $reused ? 'reused' : $confidence;
                    if ($conf !== 'good') {
                        $low[] = "{$r->name} ({$conf})";
                    }
                    $size = getimagesize($file);
                    $this->line(sprintf('%-11s %-50s <- %s  [%dx%d, %s]', $type, mb_substr($r->name, 0, 50), $folder, $size[0], $size[1], $conf));
                    if (! $dry) {
                        $paths = $images->storeFromPath($file, "{$dir}/{$r->id}");
                        $row = [$fk => $r->id, 'image_path' => $paths['image_path'], 'thumbnail_path' => $paths['thumbnail_path'], 'sort_order' => 0, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()];
                        if ($type === 'destination') {
                            $row['alt_text'] = $r->name;
                        }
                        DB::table($imageTable)->insert($row);
                    }
                    $done++;
                }
            }
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed, rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(($dry ? '[dry-run] ' : '')."{$done} records given a cover");
        if ($low) {
            $this->warn('Lower-confidence picks (worth replacing with real photos): '.implode('; ', $low));
        }

        return self::SUCCESS;
    }

    // ---------- pool ----------

    /** Every photo except each folder's own cover (the largest one, which ImportSeedImages already attached). */
    private function buildPool(string $root): void
    {
        $byDir = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && preg_match('/\.(jpe?g|png|webp)$/i', $file->getFilename())) {
                $byDir[$file->getPath()][] = $file->getPathname();
            }
        }
        ksort($byDir);
        foreach ($byDir as $dir => $files) {
            $rel = str_replace('\\', '/', substr($dir, strlen($root) + 1));
            if (in_array(explode('/', $rel)[0], self::ROOT_FLAT, true)) {
                continue;
            }
            natsort($files);
            $sized = [];
            foreach ($files as $f) {
                $s = @getimagesize($f);
                if ($s && $s[0] >= 150 && $s[1] >= 150) {
                    $sized[$f] = $s[0] * $s[1];
                }
            }
            if (! $sized) {
                continue;
            }
            arsort($sized);
            $cover = array_key_first($sized);            // same choice as ImportSeedImages::cover()
            unset($sized[$cover]);
            // Prefer larger photos first among the leftovers.
            $this->pool[$rel] = array_keys($sized);
        }
    }

    /** Take the next unused photo, trying patterns in order; falls back to reuse when the pool is exhausted. */
    private function take(array $patterns): array
    {
        foreach ($patterns as $pattern) {
            foreach ($this->pool as $rel => $files) {
                if (! preg_match($pattern, $rel)) {
                    continue;
                }
                foreach ($files as $i => $f) {
                    if (! isset($this->used[$f])) {
                        $this->used[$f] = true;

                        return [$f, $rel, false];
                    }
                }
            }
        }
        foreach ($patterns as $pattern) {          // exhausted: reuse an already-picked photo from the best folder
            foreach ($this->pool as $rel => $files) {
                if (preg_match($pattern, $rel) && $files) {
                    return [$files[0], $rel, true];
                }
            }
        }

        return [null, null, false];
    }

    // ---------- rules ----------

    /** @return array{0: string[], 1: string} folder patterns in priority order, and a confidence label */
    private function patternsFor(string $type, string $name, string $muni): array
    {
        $has = fn (string $re) => (bool) preg_match("/{$re}/", $name);

        if ($type === 'destination') {
            return match (true) {
                $has('hamor') => [['#CASIGURAN ATTRACTIONS/RESIDENCIA DEL HAMOR#', '#FULL\s?VIEW|FULLVIEW#iu'], 'good'],
                $has('arthur') => [['#^PRIETO DIAZ ATTRACTIONS/(consan|papa lo|san roque|sabang)#i'], 'good'],
                $has('kapehan') => [['#^RESTAURANTS/SkyView#', '#^RESTAURANTS/Curia#'], 'low'],
                default => [['#^BACON ATTRACTIONS/(ozi camp|mai_s|Hanamori|Evergreen)#i', '#^PRIETO DIAZ ATTRACTIONS/(consan|papa lo)#i'], 'low'],
            };
        }

        if ($type === 'food') {
            $local = self::LOCAL_FOOD[$muni] ?? [];
            $cafe = ['#^RESTAURANTS/(Curia|kuzina-del-sur)#', '#^RESTAURANTS/SkyView#'];
            $bakery = ['#^MATNOG/PRODUCTS/Old town bakeshop#'];
            $seafood = ['#^PILAR/PRODUCTS/Seafood#', '#^RESTAURANTS/(Bobmarlin|1stColonialGrill)#'];
            $general = ['#^RESTAURANTS/(Tya-Tinay|Baragatdampa|J-bulalohan|PiliHaven)#i', '#^SORSOGON/PRODUCTS/Dish#'];

            return match (true) {
                $has('bakeshop|bakery|pandesal') => [array_merge($bakery, $cafe), 'good'],
                $has('cafe|caffe|coffee|milk tea|dessert|sweet') => [array_merge($cafe, $bakery), 'good'],
                $has('seafood|paluto|grill|fish') => [array_merge($seafood, $local, $general), 'good'],
                default => [array_merge($local, $general, $seafood), 'ok'],
            };
        }

        return match (true) {
            $has('cake|pastr|bake') => [['#^MATNOG/PRODUCTS/Old town bakeshop#'], 'good'],
            $has('pili|pasalubong|delicac|coconut|otop|confection') => [['#^SORSOGON/PRODUCTS/(PiliNut|Mazapan|Pili Oil|Pili Soap|Cacao)#', '#^LOCAL PRODUCTS/#'], 'good'],
            $has('shell|coral|handicraft|craft|souvenir|native|collectible') => [['#^MAGDALENA/PRODUCTS/crafts#', '#^SORSOGON/PRODUCTS/Bayong#', '#^PILAR/PRODUCTS/handicrafts#', '#^LOCAL PRODUCTS/abaca#'], 'ok'],
            $has('fish|seafood|dried|market|produce') => [['#^PILAR/PRODUCTS/Seafood#', '#^SORSOGON/PRODUCTS/Dish#', '#^MAGALLANES/PRODUCTS/food#'], 'ok'],
            default => [['#^MAGDALENA/PRODUCTS/crafts#', '#^SORSOGON/PRODUCTS/Bayong#', '#^LOCAL PRODUCTS/#'], 'low'],  // hardware, agri-vet: no matching photos exist
        };
    }
}

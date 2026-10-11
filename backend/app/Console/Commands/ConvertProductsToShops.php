<?php

namespace App\Console\Commands;

use App\Services\ImageOptimizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ConvertProductsToShops extends Command
{
    protected $signature = 'retail:convert-to-shops {--dry-run : Report what would happen without writing}';

    protected $description = 'Turn imported shop-like retail_products rows (no shop) into retail_shops, keeping shop data and removing the product record';

    public function handle(ImageOptimizationService $images): int
    {
        $dry = (bool) $this->option('dry-run');
        $products = DB::table('retail_products')->whereNull('retail_shop_id')->orderBy('id')->get();
        $existing = DB::table('retail_shops')->get(['name', 'municipality_id'])
            ->map(fn ($s) => [$this->norm($s->name), (int) $s->municipality_id])->all();
        $converted = 0;
        $skipped = 0;

        $notShops = 0;
        foreach ($products as $p) {
            // Only businesses become shops. Records that are really products ("Local Products", "Abaca Slipper", folder-created
            // items such as "Pilar Dish") carry no shop-type note and are left as products.
            if (! preg_match('/shop|store|market|stall|hub|cent(?:er|re)|workshop|studio|retailer|seafood retail/i', $p->admin_notes ?? '') && ! preg_match('/bakeshop/i', $p->name)) {
                $this->line("KEEP     {$p->name}  (a product, not a shop)");
                $notShops++;

                continue;
            }
            $n = $this->norm($p->name);
            $dupe = collect($existing)->first(fn ($e) => $e[1] === (int) $p->municipality_id && $e[0] !== '' && ($e[0] === $n || str_contains($e[0], $n) || str_contains($n, $e[0])));
            if ($dupe) {
                $this->line("SKIP     {$p->name}  (a similar shop already exists)");
                $skipped++;

                continue;
            }

            [$hours, $notes] = $this->splitNotes((string) $p->admin_notes);
            DB::beginTransaction();
            try {
                if ($dry) {
                    $this->info("CONVERT  {$p->name}  (hours: ".($hours ?: '-').')');
                    $converted++;
                    DB::rollBack();

                    continue;
                }

                $shopId = DB::table('retail_shops')->insertGetId([
                    'user_id' => $p->user_id,
                    'name' => $p->name,
                    'description' => $p->description,
                    'municipality_id' => $p->municipality_id,
                    'barangay_id' => $p->barangay_id,
                    'address' => $p->address,
                    'latitude' => $p->latitude,
                    'longitude' => $p->longitude,
                    'contact_number' => $p->contact_number,
                    'operating_hours' => $hours,
                    'status' => $p->status,
                    'admin_notes' => $notes,
                    'view_count' => $p->view_count,
                    'approved_at' => $p->approved_at,
                    'approved_by' => $p->approved_by,
                    'created_at' => $p->created_at,
                    'updated_at' => now(),
                ]);

                $imageRows = DB::table('retail_product_images')->where('retail_product_id', $p->id)->orderBy('sort_order')->get();
                foreach ($imageRows as $i => $img) {
                    $copy = $this->copyImage($img, $shopId);
                    if ($copy) {
                        DB::table('retail_shop_images')->insert([
                            'retail_shop_id' => $shopId, 'image_path' => $copy[0], 'thumbnail_path' => $copy[1],
                            'is_primary' => $i === 0, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }

                // Remove the product record (and its images) now that the shop carries the data.
                foreach ($imageRows as $img) {
                    $images->delete($img->image_path, $img->thumbnail_path);
                }
                DB::table('retail_product_images')->where('retail_product_id', $p->id)->delete();
                DB::table('retail_products')->where('id', $p->id)->delete();
                DB::commit();

                $existing[] = [$n, (int) $p->municipality_id];
                $this->info("CONVERT  {$p->name}  -> shop #{$shopId}");
                $converted++;
            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error("FAILED   {$p->name}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->line(($dry ? '[dry-run] ' : '')."converted {$converted}, skipped {$skipped}, left as products {$notShops}");

        return self::SUCCESS;
    }

    /** The import stored "<Business type> — <notes> | Hours: <hours>" in admin_notes. */
    private function splitNotes(string $notes): array
    {
        $hours = null;
        if (preg_match('/\s*\|\s*Hours:\s*(.+)$/u', $notes, $m)) {
            $hours = trim($m[1]);
            $notes = trim(preg_replace('/\s*\|\s*Hours:\s*.+$/u', '', $notes));
        }

        return [$hours ? substr($hours, 0, 255) : null, $notes !== '' ? $notes : null];
    }

    private function copyImage(object $img, int $shopId): ?array
    {
        $disk = Storage::disk('public');
        if (! $img->image_path || ! $disk->exists($img->image_path)) {
            return null;
        }
        $name = Str::uuid()->toString().'.'.(pathinfo($img->image_path, PATHINFO_EXTENSION) ?: 'webp');
        $imagePath = "retail-shops/{$shopId}/{$name}";
        $disk->copy($img->image_path, $imagePath);
        $thumbPath = $imagePath;
        if ($img->thumbnail_path && $img->thumbnail_path !== $img->image_path && $disk->exists($img->thumbnail_path)) {
            $thumbPath = "retail-shops/{$shopId}/thumbnails/{$name}";
            $disk->copy($img->thumbnail_path, $thumbPath);
        }

        return [$imagePath, $thumbPath];
    }

    private function norm(string $s): string
    {
        $s = Str::lower(Str::ascii($s));
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', $s));
    }
}

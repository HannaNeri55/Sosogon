<?php

namespace Database\Seeders;

use App\Models\ListingEstimate;
use App\Models\MenuItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One-off clean-up of public listing pages (from the listing page audit). Matches records by
 * content, not ID, and is safe to run again: a second run changes nothing. It never touches
 * coordinates, deletes rows or files, or changes any other approval status.
 *
 * - Menu items labelled "(Demo price.)": the label is removed and the price kept, recorded as
 *   an estimate so pages show "Est. ₱…".
 * - Food place descriptions: the "Demo details: …" paragraph is removed.
 * - Contact numbers: the sequential 0900 000 01xx placeholders on approved food places and the
 *   literal "Not verified" on approved accommodations are cleared (no number is invented).
 * - Bentornato's "Baked Mussels" gets its own copy of the photo that shows the baked mussels
 *   (the one on its "Pancit Canton"); no file is overwritten or deleted.
 * - "Test Restaurant - Revision 2" is unpublished the way the admin panel does it (pending,
 *   publication draft), not deleted.
 */
class ListingPageCleanupSeeder extends Seeder
{
    private const DEMO_PRICE = ' (Demo price.)';

    private array $changed = [];

    public function run(): void
    {
        $this->estimatedMenuPrices();
        $this->demoNotes();
        $this->placeholderContacts();
        $this->bakedMusselsPhoto();
        $this->unpublishTestListing();

        $this->command?->info('Listing page clean-up: '.json_encode($this->changed ?: ['nothing to change']));
    }

    private function estimatedMenuPrices(): void
    {
        foreach (MenuItem::where('description', 'like', '%'.self::DEMO_PRICE.'%')->get() as $item) {
            DB::table('menu_items')->where('id', $item->id)->update([
                'description' => trim(str_replace(self::DEMO_PRICE, '', $item->description)),
            ]);
            if ($item->price !== null) {
                ListingEstimate::updateOrCreate(
                    ['estimable_type' => $item->getMorphClass(), 'estimable_id' => $item->id, 'field' => 'price'],
                    ['value' => (string) (float) $item->price, 'basis' => 'Menu price not confirmed with the business (was labelled a demo price)']
                );
            }
            $this->changed['menu_items.demo_price_label'][] = $item->id;
        }
    }

    private function demoNotes(): void
    {
        foreach (DB::table('food_beverages')->where('description', 'like', '%Demo details:%')->get(['id', 'description']) as $place) {
            $cut = strpos($place->description, 'Demo details:');
            DB::table('food_beverages')->where('id', $place->id)->update(['description' => rtrim(substr($place->description, 0, $cut))]);
            $this->changed['food_beverages.demo_note'][] = $place->id;
        }
    }

    private function placeholderContacts(): void
    {
        foreach (DB::table('food_beverages')->where('status', 'approved')->whereNotNull('contact_number')->get(['id', 'contact_number']) as $place) {
            if (preg_match('/^0900 000 01\d\d$/', trim($place->contact_number))) {
                DB::table('food_beverages')->where('id', $place->id)->update(['contact_number' => null]);
                $this->changed['food_beverages.contact_cleared'][] = $place->id;
            }
        }
        foreach (DB::table('accommodations')->where('status', 'approved')->where('contact_number', 'Not verified')->pluck('id') as $id) {
            DB::table('accommodations')->where('id', $id)->update(['contact_number' => null]);
            $this->changed['accommodations.contact_cleared'][] = $id;
        }
    }

    private function bakedMusselsPhoto(): void
    {
        $place = DB::table('food_beverages')->where('name', 'Bentornato')->value('id');
        $mussels = DB::table('menu_items')->where('food_beverage_id', $place)->where('name', 'Baked Mussels')->first();
        $pancit = DB::table('menu_items')->where('food_beverage_id', $place)->where('name', 'Pancit Canton')->first();
        if (! $mussels || ! $pancit || ! $pancit->image_path || ! Storage::disk('public')->exists($pancit->image_path)) {
            return;
        }
        // Already given its copy on an earlier run.
        if ($mussels->image_path && Storage::disk('public')->exists($mussels->image_path)
            && Storage::disk('public')->get($mussels->image_path) === Storage::disk('public')->get($pancit->image_path)) {
            return;
        }

        $name = Str::uuid().'.'.pathinfo($pancit->image_path, PATHINFO_EXTENSION);
        $image = "menu-items/{$mussels->id}/{$name}";
        Storage::disk('public')->copy($pancit->image_path, $image);
        $thumbnail = $image;
        if ($pancit->thumbnail_path && Storage::disk('public')->exists($pancit->thumbnail_path)) {
            $thumbnail = "menu-items/{$mussels->id}/thumbnails/{$name}";
            Storage::disk('public')->copy($pancit->thumbnail_path, $thumbnail);
        }

        DB::table('menu_items')->where('id', $mussels->id)->update(['image_path' => $image, 'thumbnail_path' => $thumbnail]);
        $this->changed['menu_items.photo_copied'][] = $mussels->id;
    }

    private function unpublishTestListing(): void
    {
        $id = DB::table('food_beverages')->where('name', 'Test Restaurant - Revision 2')->where('status', 'approved')->value('id');
        if ($id) {
            DB::table('food_beverages')->where('id', $id)->update([
                'status' => 'pending', 'publication_review_status' => 'draft', 'approved_at' => null, 'approved_by' => null,
            ]);
            $this->changed['food_beverages.unpublished'][] = $id;
        }
    }
}

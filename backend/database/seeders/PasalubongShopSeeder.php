<?php

namespace Database\Seeders;

use App\Models\BusinessSubmission;
use App\Models\RetailShop;
use App\Models\RetailShopImage;
use App\Models\Role;
use App\Models\User;
use App\Services\ImageOptimizationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The project team's pasalubong shops, each under its own placeholder Business Owner account.
 *
 * A shop is left where an owner's "submit for review" leaves it: status pending, review status
 * submitted. Nothing is published until an admin approves it in Review Listings. The photos are
 * openly licensed and credited representative photos, not photos of the shops, and each one is
 * used by one shop only.
 * Not part of DatabaseSeeder; run it by name:
 *
 *   PASALUBONG_OWNER_PASSWORD=... PASALUBONG_IMAGE_DIR=... php artisan db:seed --class=PasalubongShopSeeder
 *
 * Refuses production. Only creates rows, and skips a shop already in that town, so re-runs add nothing.
 * PASALUBONG_IMAGE_DIR holds the photos and a manifest.json with their authors and licences.
 */
class PasalubongShopSeeder extends Seeder
{
    public const EMAIL_DOMAIN = 'demo.sosogon.test';

    public function run(ImageOptimizationService $images): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Placeholder business owners cannot be seeded in production.');
        }

        $password = getenv('PASALUBONG_OWNER_PASSWORD') ?: null;
        $imageDir = getenv('PASALUBONG_IMAGE_DIR') ?: null;
        if (! $password || ! $imageDir) {
            throw new RuntimeException('Set PASALUBONG_OWNER_PASSWORD and PASALUBONG_IMAGE_DIR before running this seeder.');
        }

        $photos = collect(json_decode(file_get_contents("{$imageDir}/manifest.json"), true))
            ->keyBy(fn ($photo) => pathinfo($photo['file'], PATHINFO_FILENAME));
        $role = Role::where('name', 'business_owner')->firstOrFail();
        $photoSets = require database_path('seeders/data/pasalubong_shop_photos.php');
        $permit = file_get_contents(database_path('seeders/data/sample-permit.pdf'));
        $created = 0;

        foreach (require database_path('seeders/data/pasalubong_shops.php') as $shop) {
            $townId = DB::table('municipalities')->where('name', $shop['town'])->value('id')
                ?? throw new RuntimeException("Unknown town \"{$shop['town']}\" for \"{$shop['name']}\".");

            if (RetailShop::where('municipality_id', $townId)->whereRaw('LOWER(name) = ?', [mb_strtolower($shop['name'])])->exists()) {
                continue;
            }

            $barangayId = $shop['barangay']
                ? DB::table('barangays')->where('municipality_id', $townId)->where('name', $shop['barangay'])->value('id')
                : null;

            $listing = DB::transaction(function () use ($shop, $townId, $barangayId, $role, $password) {
                $owner = User::create([
                    'role_id' => $role->id,
                    'name' => "Demo Owner – {$shop['name']}",
                    'email' => 'owner.'.Str::slug("{$shop['name']} {$shop['town']}").'@'.self::EMAIL_DOMAIN,
                    'password' => $password,
                    'status' => 'active',
                    'business_type' => 'retail_product',
                ]);

                $listing = RetailShop::create([
                    'user_id' => $owner->id,
                    'name' => $shop['name'],
                    'description' => $this->description($shop),
                    'product_categories' => ['Pasalubong Shop', ...$shop['products']],
                    'municipality_id' => $townId,
                    'barangay_id' => $barangayId,
                    'address' => $shop['address'],
                    'latitude' => $shop['lat'],
                    'longitude' => $shop['lng'],
                    'contact_number' => $shop['phone'],
                    'operating_hours' => $shop['hours'],
                    'status' => 'pending',
                    'publication_review_status' => 'submitted',
                    'admin_notes' => $this->adminNotes($shop),
                ]);

                (new BusinessSubmission)->forceFill([
                    'user_id' => $owner->id,
                    'business_name' => $shop['name'],
                    'type' => 'retail_product',
                    'description' => $listing->description,
                    'address' => $shop['address'],
                    'municipality_id' => $townId,
                    'barangay_id' => $barangayId,
                    'latitude' => $shop['lat'],
                    'longitude' => $shop['lng'],
                    'contact_number' => $shop['phone'] ?? 'Not provided',
                    'operating_hours' => $shop['hours'],
                    'business_permit_path' => "business-permits/{$owner->id}/sample-permit.pdf",
                    'status' => 'approved',
                    'admin_notes' => "Added from the project team's list, not reviewed by an admin. The permit is a sample file.",
                    'listing_id' => $listing->id,
                ])->save();

                return $listing;
            });

            Storage::disk('local')->put("business-permits/{$listing->user_id}/sample-permit.pdf", $permit);

            foreach ($photoSets["{$shop['name']}|{$shop['town']}"] as $order => $name) {
                $photo = $photos[$name] ?? throw new RuntimeException("Photo {$name} is not in the manifest.");
                $author = $photo['artist'] !== '' ? $photo['artist'] : 'see source page';

                RetailShopImage::create([
                    ...$images->storeFromPath("{$imageDir}/{$name}.jpg", "retail-shops/{$listing->id}"),
                    'retail_shop_id' => $listing->id,
                    'is_primary' => $order === 0,
                    'sort_order' => $order,
                    'image_credit' => Str::limit("Representative photo: {$author} / Wikimedia Commons, {$photo['lic']}", 250),
                    'image_source_url' => $photo['page'],
                ]);
            }
            $created++;
        }

        $this->command?->info("Pasalubong shops created: {$created}.");
    }

    /** Only what the list supports: where the shop is, and what its name says it sells. */
    private function description(array $shop): string
    {
        $place = $shop['town'] === 'Sorsogon City' ? 'Sorsogon City' : "{$shop['town']}, Sorsogon";
        $intro = ($shop['market'] ?? false)
            ? "{$shop['name']} is a market in {$place} where visitors can look for local goods to bring home."
            : "{$shop['name']} is a local stop in {$place} for visitors looking for something to bring home.";
        $offer = $shop['products']
            ? ' Going by its name, it offers '.Str::lower(implode(' and ', $shop['products'])).'.'
            : '';

        return "{$intro}{$offer}";
    }

    private function adminNotes(array $shop): string
    {
        return trim("From the project team's list; products, contact details and hours are not verified with the business. "
            .'The owner account is a placeholder and the photos are representative. '.($shop['flag'] ?? ''));
    }
}

<?php

namespace Database\Seeders;

use App\Models\BusinessSubmission;
use App\Models\FoodBeverage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The project team's restaurants, each under its own placeholder Business Owner account.
 *
 * A listing is left as an owner's draft (status pending, review status draft), because it has no
 * photos yet and needs 5 to submit. Nothing is public until an admin approves it.
 * Not part of DatabaseSeeder; run it by name:
 *
 *   RESTAURANT_OWNER_CREDENTIALS=/path/outside/the/repo.csv php artisan db:seed --class=RestaurantOwnerSeeder
 *
 * Every new account gets its own random password, written only to that CSV file. Refuses production.
 * Only creates rows and skips what exists, so a re-run adds only what is new.
 */
class RestaurantOwnerSeeder extends Seeder
{
    public const EMAIL_DOMAIN = 'sosogon.test';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Placeholder business owners cannot be seeded in production.');
        }

        $credentialsFile = getenv('RESTAURANT_OWNER_CREDENTIALS') ?: null;
        if (! $credentialsFile) {
            throw new RuntimeException('Set RESTAURANT_OWNER_CREDENTIALS to a CSV path outside the repository before running this seeder.');
        }

        $role = Role::where('name', 'business_owner')->firstOrFail();
        $permit = file_get_contents(database_path('seeders/data/sample-permit.pdf'));
        $credentials = [];
        $listings = 0;

        foreach (require database_path('seeders/data/restaurant_owners.php') as $place) {
            $townId = DB::table('municipalities')->where('name', $place['town'])->value('id')
                ?? throw new RuntimeException("Unknown town \"{$place['town']}\" for \"{$place['name']}\".");
            $barangayId = $place['barangay']
                ? DB::table('barangays')->where('municipality_id', $townId)->where('name', $place['barangay'])->value('id')
                    ?? throw new RuntimeException("Unknown barangay \"{$place['barangay']}\" for \"{$place['name']}\".")
                : null;

            $email = Str::slug($place['name']).'@'.self::EMAIL_DOMAIN;
            $owner = User::where('email', $email)->first();
            if (! $owner) {
                $password = Str::password(16, symbols: false);
                $owner = User::create([
                    'role_id' => $role->id,
                    'name' => "Demo Owner – {$place['name']}",
                    'email' => $email,
                    'password' => $password,
                    'status' => 'active',
                    'business_type' => 'food_beverage',
                ]);
                $credentials[] = [$place['name'], $place['town'], $email, $password];
            }

            $exists = FoodBeverage::where('municipality_id', $townId)->whereRaw('LOWER(name) = ?', [mb_strtolower($place['name'])])->exists();
            if ($exists) {
                continue;
            }

            DB::transaction(function () use ($place, $owner, $townId, $barangayId) {
                $listing = FoodBeverage::create([
                    'user_id' => $owner->id,
                    'name' => $place['name'],
                    'description' => $place['about'],
                    'cuisine_type' => $place['cuisine'],
                    'meals_served' => $place['meals'],
                    'specialties' => $place['specialties'],
                    'municipality_id' => $townId,
                    'barangay_id' => $barangayId,
                    'address' => $place['address'],
                    'latitude' => $place['lat'],
                    'longitude' => $place['lng'],
                    'price_range' => $place['price'],
                    'contact_number' => $place['phone'],
                    'operating_hours' => $place['hours'],
                    'status' => 'pending',
                    'publication_review_status' => 'draft',
                ]);

                $filled = $place['filled'] === [] ? '' : ' Written by the team, not from the list: description, meals served, '.implode(', ', $place['filled']).'.';

                (new BusinessSubmission)->forceFill([
                    'user_id' => $owner->id,
                    'business_name' => $place['name'],
                    'type' => 'food_beverage',
                    'description' => $place['about'],
                    'address' => $place['address'],
                    'municipality_id' => $townId,
                    'barangay_id' => $barangayId,
                    'latitude' => $place['lat'],
                    'longitude' => $place['lng'],
                    'contact_number' => $place['phone'] ?? 'Not provided',
                    'business_permit_path' => "business-permits/{$owner->id}/sample-permit.pdf",
                    'status' => 'approved',
                    'admin_notes' => "Added from the project team's list, not reviewed by an admin. The permit is a sample file.".$filled,
                    'listing_id' => $listing->id,
                ])->save();
            });

            Storage::disk('local')->put("business-permits/{$owner->id}/sample-permit.pdf", $permit);
            $listings++;
        }

        if ($credentials !== []) {
            $handle = fopen($credentialsFile, 'a');
            if (ftell($handle) === 0) {
                fputcsv($handle, ['restaurant', 'town', 'email', 'password']);
            }
            foreach ($credentials as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }

        $this->command?->info('Restaurant owners created: '.count($credentials).". Listings created: {$listings}.");
    }
}

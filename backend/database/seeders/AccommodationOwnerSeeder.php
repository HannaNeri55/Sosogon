<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\AccommodationRoom;
use App\Models\BusinessSubmission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The project team's accommodations, each under its own placeholder Business Owner account.
 *
 * A listing is left as an owner's draft (status pending, review status draft), because it has no
 * photos yet and needs 5 to submit. Nothing is public until an admin approves it. Rooms are pending too.
 * Not part of DatabaseSeeder; run it by name:
 *
 *   ACCOMMODATION_OWNER_PASSWORD=... php artisan db:seed --class=AccommodationOwnerSeeder
 *
 * Refuses production. Only creates rows and skips what exists, so re-runs add only what is new, such
 * as the listing of a business whose coordinates have since been added to the data file.
 */
class AccommodationOwnerSeeder extends Seeder
{
    public const EMAIL_DOMAIN = 'sosogon.test';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Placeholder business owners cannot be seeded in production.');
        }

        $password = getenv('ACCOMMODATION_OWNER_PASSWORD') ?: null;
        if (! $password) {
            throw new RuntimeException('Set ACCOMMODATION_OWNER_PASSWORD before running this seeder.');
        }

        $role = Role::where('name', 'business_owner')->firstOrFail();
        $permit = file_get_contents(database_path('seeders/data/sample-permit.pdf'));
        $accounts = 0;
        $listings = 0;

        foreach (require database_path('seeders/data/accommodation_owners.php') as $stay) {
            $townId = DB::table('municipalities')->where('name', $stay['town'])->value('id')
                ?? throw new RuntimeException("Unknown town \"{$stay['town']}\" for \"{$stay['name']}\".");
            $barangayId = $stay['barangay']
                ? DB::table('barangays')->where('municipality_id', $townId)->where('name', $stay['barangay'])->value('id')
                : null;

            $owner = User::firstOrCreate(
                ['email' => Str::slug($stay['name']).'@'.self::EMAIL_DOMAIN],
                [
                    'role_id' => $role->id,
                    'name' => "Demo Owner – {$stay['name']}",
                    'password' => $password,
                    'status' => 'active',
                    'business_type' => 'accommodation',
                ],
            );
            $accounts += $owner->wasRecentlyCreated ? 1 : 0;

            $exists = Accommodation::where('municipality_id', $townId)->whereRaw('LOWER(name) = ?', [mb_strtolower($stay['name'])])->exists();
            if ($exists || $stay['lat'] === null) {
                continue;
            }

            DB::transaction(function () use ($stay, $owner, $townId, $barangayId) {
                $listing = Accommodation::create([
                    'user_id' => $owner->id,
                    'name' => $stay['name'],
                    'description' => $stay['about'],
                    'type' => $stay['type'],
                    'offering_arrangement' => $stay['arrangement'],
                    'classifications' => $stay['classes'],
                    'municipality_id' => $townId,
                    'barangay_id' => $barangayId,
                    'address' => $stay['address'],
                    'latitude' => $stay['lat'],
                    'longitude' => $stay['lng'],
                    'price_range' => 'moderate',
                    'contact_number' => $stay['phone'],
                    'email' => $stay['email'] ?? null,
                    'check_in_time' => $stay['in'],
                    'check_out_time' => $stay['out'],
                    'amenities' => $stay['amenities'],
                    'status' => 'pending',
                    'publication_review_status' => 'draft',
                ]);

                foreach ($stay['rooms'] as $order => $room) {
                    AccommodationRoom::create([
                        'accommodation_id' => $listing->id,
                        'name' => $room['name'],
                        'room_number' => $room['name'],
                        'unit_type' => $room['unit_type'],
                        'unit_count' => $room['unit_count'] ?? null,
                        'max_guests' => $room['max_guests'] ?? null,
                        'bedrooms' => $room['bedrooms'] ?? null,
                        'bed_configuration' => $room['beds'] ?? null,
                        'pricing_mode' => $room['mode'] ?? 'contact',
                        'price' => $room['price'] ?? null,
                        'price_max' => $room['price_max'] ?? null,
                        'price_note' => $room['price_note'] ?? null,
                        'description' => $room['description'] ?? null,
                        'notes' => $room['notes'] ?? null,
                        'breakfast_included' => $room['breakfast'] ?? null,
                        'room_features' => $room['features'] ?? [],
                        'sort_order' => $order,
                        'occupancy_status' => 'unoccupied',
                        'status' => 'pending',
                    ]);
                }

                (new BusinessSubmission)->forceFill([
                    'user_id' => $owner->id,
                    'business_name' => $stay['name'],
                    'type' => 'accommodation',
                    'description' => $stay['about'],
                    'address' => $stay['address'],
                    'municipality_id' => $townId,
                    'barangay_id' => $barangayId,
                    'latitude' => $stay['lat'],
                    'longitude' => $stay['lng'],
                    'contact_number' => $stay['phone'] ?? 'Not provided',
                    'business_permit_path' => "business-permits/{$owner->id}/sample-permit.pdf",
                    'status' => 'approved',
                    'admin_notes' => trim("Added from the project team's list, not reviewed by an admin. The permit is a sample file. ".($stay['flag'] ?? '')),
                    'listing_id' => $listing->id,
                ])->save();
            });

            Storage::disk('local')->put("business-permits/{$owner->id}/sample-permit.pdf", $permit);
            $listings++;
        }

        $this->command?->info("Accommodation owners created: {$accounts}. Listings created: {$listings}.");
    }
}

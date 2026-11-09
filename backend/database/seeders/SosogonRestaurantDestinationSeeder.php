<?php

namespace Database\Seeders;

use App\Support\PlaceCover;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Restaurants (with owner accounts) and admin destinations from RESTAURANTS.pdf,
 * data in database/seeders/data/sosogon_restaurants_destinations.php.
 *
 * Each restaurant gets an active business owner (owner.<slug>@example.com), an
 * approved business submission and a DRAFT food & beverage listing with a demo
 * menu and a title card; the owner adds 5 photos and submits it for publication.
 * Each destination is published as an admin destination with fees, amenities,
 * activities and a title card. Demo values are flagged in every description.
 *
 * Only ever inserts: anything whose name already exists (any owner, any type
 * table) is skipped, so re-running adds nothing. Not part of DatabaseSeeder:
 *
 *   PLACES_PREVIEW=1 php artisan db:seed --class=SosogonRestaurantDestinationSeeder   (prints the plan only)
 *   php artisan db:seed --class=SosogonRestaurantDestinationSeeder
 */
class SosogonRestaurantDestinationSeeder extends Seeder
{
    public const PASSWORD = 'SosogonDemo#2026';

    public const EMAIL_DOMAIN = 'example.com';

    public const RESTAURANT_NOTE = 'Demo details: the contact number, opening hours, price range and menu prices are placeholders that have not been confirmed with the business.';

    public const DESTINATION_NOTE = 'Demo details: the visiting hours, fees, amenities and activities are estimates that have not been confirmed on site.';

    public const ADMIN_NOTE = 'DEMO DATA from RESTAURANTS.pdf. Name and coordinates as supplied; description, contact number, hours, price range and menu are placeholders.';

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo restaurants and destinations cannot be seeded in production.');
        }

        $data = require database_path('seeders/data/sosogon_restaurants_destinations.php');
        $restaurants = $this->planRestaurants($data['restaurants']);
        $destinations = $this->planDestinations($data['destinations']);

        if (getenv('PLACES_PREVIEW') === '1') {
            $this->printPlan($restaurants, $destinations);

            return;
        }

        $admin = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'admin')->orderBy('users.id')->value('users.id');
        $ownerRole = DB::table('roles')->where('name', 'business_owner')->value('id');
        if (! $admin || ! $ownerRole) {
            throw new RuntimeException('Admin user or business_owner role not found. Nothing was written.');
        }

        $permits = [];
        $made = ['restaurants' => 0, 'destinations' => 0];

        DB::transaction(function () use ($restaurants, $destinations, $admin, $ownerRole, &$permits, &$made) {
            $now = now();
            $password = Hash::make(self::PASSWORD);

            foreach ($restaurants as $r) {
                if ($r['action'] !== 'create') {
                    continue;
                }

                $ownerId = DB::table('users')->insertGetId([
                    'role_id' => $ownerRole, 'name' => "{$r['name']} Owner", 'email' => $r['email'],
                    'password' => $password, 'phone' => $r['contact_number'], 'status' => 'active',
                    'business_type' => 'food_beverage', 'created_at' => $now, 'updated_at' => $now,
                ]);

                $listingId = DB::table('food_beverages')->insertGetId([
                    'user_id' => $ownerId, 'name' => $r['name'], 'description' => $r['full_description'],
                    'cuisine_type' => $r['cuisine_type'], 'municipality_id' => $r['municipality_id'],
                    'barangay_id' => $r['barangay_id'], 'address' => $r['address'],
                    'latitude' => $r['latitude'], 'longitude' => $r['longitude'],
                    'price_range' => $r['price_range'], 'contact_number' => $r['contact_number'],
                    'operating_hours' => $r['operating_hours'], 'status' => 'pending',
                    'publication_review_status' => 'draft', 'is_sample' => false,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                $permit = "business-permits/{$ownerId}/sample-permit.pdf";
                $permits[] = $permit;
                DB::table('business_submissions')->insert([
                    'user_id' => $ownerId, 'business_name' => $r['name'], 'type' => 'food_beverage',
                    'description' => $r['full_description'], 'address' => $r['address'],
                    'municipality_id' => $r['municipality_id'], 'barangay_id' => $r['barangay_id'],
                    'latitude' => $r['latitude'], 'longitude' => $r['longitude'],
                    'contact_number' => $r['contact_number'], 'operating_hours' => $r['operating_hours'],
                    'business_permit_path' => $permit, 'status' => 'approved', 'admin_notes' => self::ADMIN_NOTE,
                    'reviewed_by' => $admin, 'reviewed_at' => $now, 'listing_id' => $listingId, 'is_sample' => false,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                $cover = PlaceCover::store('food-beverages/covers', $r['name'], $r['kind'], $r['cuisine_type'], 'Bulan, Sorsogon');
                DB::table('food_beverage_images')->insert([
                    'food_beverage_id' => $listingId, 'image_path' => $cover, 'thumbnail_path' => $cover,
                    'is_primary' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);

                foreach ($r['menu'] as [$dish, $about, $price]) {
                    DB::table('menu_items')->insert([
                        'food_beverage_id' => $listingId, 'name' => $dish, 'description' => "{$about} (Demo price.)",
                        'price' => $price, 'status' => 'approved', 'approved_at' => $now, 'approved_by' => $admin,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                $made['restaurants']++;
            }

            foreach ($destinations as $d) {
                if ($d['action'] !== 'create') {
                    continue;
                }

                $id = DB::table('destinations')->insertGetId([
                    'name' => $d['name'], 'description' => $d['full_description'], 'category_id' => $d['category_id'],
                    'municipality_id' => $d['municipality_id'], 'barangay_id' => $d['barangay_id'], 'address' => $d['address'],
                    'latitude' => $d['latitude'], 'longitude' => $d['longitude'], 'operating_hours' => $d['operating_hours'],
                    'entrance_fee' => $d['entrance_fee'], 'status' => 'published', 'created_by' => $admin,
                    'is_featured' => false, 'created_at' => $now, 'updated_at' => $now,
                ]);

                foreach ($d['fees'] as $i => [$label, $amount, $note]) {
                    DB::table('destination_fees')->insert([
                        'destination_id' => $id, 'label' => $label, 'amount' => $amount, 'description' => $note,
                        'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                foreach ($d['amenities'] as $amenity) {
                    DB::table('destination_amenities')->insert([
                        'destination_id' => $id, 'name' => $amenity, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                foreach ($d['activities'] as [$name, $type, $about]) {
                    DB::table('activities')->insert([
                        'destination_id' => $id, 'name' => $name, 'type' => $type, 'description' => $about,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }

                $cover = PlaceCover::store('destinations/covers', $d['name'], $d['kind'], $d['category'], "{$d['municipality']}, Sorsogon");
                DB::table('destination_images')->insert([
                    'destination_id' => $id, 'image_path' => $cover, 'thumbnail_path' => $cover,
                    'alt_text' => "{$d['name']} title card", 'credit' => 'SOSOGON title card', 'sort_order' => 0,
                    'is_primary' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $made['destinations']++;
            }
        });

        // Owners need a permit file on the private disk, like every other owner (never public).
        $pdf = file_get_contents(database_path('seeders/data/sample-permit.pdf'));
        foreach ($permits as $path) {
            Storage::disk('local')->put($path, $pdf);
        }

        $this->command?->info(sprintf(
            'Restaurants: %d created, %d skipped. Destinations: %d created, %d skipped.',
            $made['restaurants'], count($restaurants) - $made['restaurants'],
            $made['destinations'], count($destinations) - $made['destinations'],
        ));
    }

    private function planRestaurants(array $records): array
    {
        $bulan = $this->municipality('Bulan');
        $existing = $this->names('food_beverages')
            ->merge(DB::table('business_submissions')->where('type', 'food_beverage')->pluck('business_name')->map(fn ($n) => self::key($n)));

        $plan = [];
        foreach ($records as $i => $record) {
            $email = 'owner.'.Str::slug(str_replace(['’', "'"], '', $record['name'])).'@'.self::EMAIL_DOMAIN;
            $exists = $existing->contains(self::key($record['name']));
            if (! $exists && DB::table('users')->where('email', $email)->exists()) {
                throw new RuntimeException("{$email} already exists without a \"{$record['name']}\" listing. Nothing was written.");
            }

            $plan[] = array_merge($record, [
                'municipality_id' => $bulan,
                'barangay_id' => $this->barangay($bulan, $record['barangay']),
                'email' => $email,
                'contact_number' => sprintf('0900 000 01%02d', $i + 1),
                'full_description' => $record['description']."\n\n".self::RESTAURANT_NOTE,
                'action' => $exists ? 'skip' : 'create',
            ]);
        }
        $this->assertUnique(array_column($plan, 'email'), 'owner emails');

        return $plan;
    }

    private function planDestinations(array $records): array
    {
        // A destination is also skipped when it already exists as an owner's accommodation (e.g. Mai's Hotel and Resort).
        $existing = $this->names('destinations')->merge($this->names('accommodations'));

        $plan = [];
        foreach ($records as $record) {
            $town = $this->municipality($record['municipality']);
            $category = DB::table('categories')->where('type', 'destination')->where('name', $record['category'])->value('id')
                ?? throw new RuntimeException("Destination category \"{$record['category']}\" not found. Nothing was written.");

            $plan[] = array_merge($record, [
                'municipality_id' => $town,
                'barangay_id' => $this->barangay($town, $record['barangay']),
                'category_id' => $category,
                'full_description' => $record['description']."\n\n".self::DESTINATION_NOTE,
                'action' => $existing->contains(self::key($record['name'])) ? 'skip' : 'create',
            ]);
        }
        $this->assertUnique(array_map(fn ($d) => self::key($d['name']), $plan), 'destination names');

        return $plan;
    }

    private function municipality(string $name): int
    {
        $ids = DB::table('municipalities')->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->pluck('id');
        if ($ids->count() !== 1) {
            throw new RuntimeException("Municipality \"{$name}\" did not resolve to exactly one row. Nothing was written.");
        }

        return (int) $ids->first();
    }

    private function barangay(int $municipalityId, ?string $name): ?int
    {
        return $name === null ? null : DB::table('barangays')->where('municipality_id', $municipalityId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])->value('id');
    }

    private function names(string $table)
    {
        return DB::table($table)->pluck('name')->map(fn ($n) => self::key($n));
    }

    /** Case-, space- and apostrophe-insensitive name key, so "Ning's" matches "Ning’s". */
    private static function key(string $name): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim(str_replace('’', "'", $name))));
    }

    private function assertUnique(array $values, string $what): void
    {
        if (count(array_unique($values)) !== count($values)) {
            throw new RuntimeException("Duplicate {$what} in the data file. Nothing was written.");
        }
    }

    private function printPlan(array $restaurants, array $destinations): void
    {
        $this->command?->line('PREVIEW — nothing is written.');
        foreach ($restaurants as $r) {
            $this->command?->line(sprintf('[%s] Restaurant | %s | %s, %s | barangay %s | %s | %s',
                $r['action'], $r['name'], $r['latitude'], $r['longitude'],
                $r['barangay'] ? ($r['barangay_id'] ? "#{$r['barangay_id']}" : "\"{$r['barangay']}\" not found") : '-',
                $r['email'], $r['contact_number']));
        }
        foreach ($destinations as $d) {
            $this->command?->line(sprintf('[%s] Destination | %s | %s (#%d) | %s #%d | %s, %s | barangay %s',
                $d['action'], $d['name'], $d['municipality'], $d['municipality_id'], $d['category'], $d['category_id'],
                $d['latitude'], $d['longitude'],
                $d['barangay'] ? ($d['barangay_id'] ? "#{$d['barangay_id']}" : "\"{$d['barangay']}\" not found") : '-'));
        }
    }
}

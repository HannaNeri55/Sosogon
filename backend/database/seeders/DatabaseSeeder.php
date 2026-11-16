<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminUserSeeder::class,
            MunicipalitySeeder::class,
            MunicipalityProfileSeeder::class,
            BarangaySeeder::class,
            CategorySeeder::class,
            // The experience vocabulary only; no destination is tagged by seeding.
            ExperienceTagSeeder::class,
            SampleDestinationSeeder::class,
            SampleAccommodationSeeder::class,
            SampleFoodBeverageSeeder::class,
            SampleRetailShopSeeder::class,
            CopyMenuToEmptyFoodBeverageSeeder::class,
            CopyProductsToEmptyRetailShopSeeder::class,
            CopyRoomsToEmptyAccommodationSeeder::class,
            CopyDestinationImageByCategorySeeder::class,
            ActivitySeeder::class,
            CopyActivityImageByNameSeeder::class,
            EventSeeder::class,
            BulanBarangayFiestaSeeder::class,
            AnnouncementSeeder::class,
            EventCoverSeeder::class,
        ]);
    }
}

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
            BarangaySeeder::class,
            CategorySeeder::class,
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
        ]);
    }
}

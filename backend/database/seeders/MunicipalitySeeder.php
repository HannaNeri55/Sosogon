<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MunicipalitySeeder extends Seeder
{
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        DB::table('barangays')->truncate();
        DB::table('municipalities')->truncate();
        Schema::enableForeignKeyConstraints();

        DB::table('municipalities')->insert([
            ['name' => 'Bulan', 'description' => 'Municipality of Bulan, the pilot area for Sorsogon system implementation.', 'latitude' => 12.6744, 'longitude' => 123.8756, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Barcelona', 'description' => 'Known for its churches and natural springs.', 'latitude' => 12.8694, 'longitude' => 124.1417, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bulusan', 'description' => 'Municipality of Bulusan, Sorsogon.', 'latitude' => null, 'longitude' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Casiguran', 'description' => 'Municipality of Casiguran, Sorsogon.', 'latitude' => null, 'longitude' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Castilla', 'description' => 'Municipality of Castilla, Sorsogon.', 'latitude' => null, 'longitude' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Donsol', 'description' => 'Known for whale shark interactions.', 'latitude' => 12.9083, 'longitude' => 123.5981, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Gubat', 'description' => 'Municipality of Gubat, Sorsogon.', 'latitude' => null, 'longitude' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Irosin', 'description' => 'Municipality of Irosin, Sorsogon.', 'latitude' => null, 'longitude' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Juban', 'description' => 'Municipality of Juban, Sorsogon.', 'latitude' => null, 'longitude' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Magallanes', 'description' => 'Municipality of Magallanes, Sorsogon.', 'latitude' => null, 'longitude' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Matnog', 'description' => 'Gateway to the Visayas with beautiful islands.', 'latitude' => 12.5833, 'longitude' => 124.0833, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Pilar', 'description' => 'Municipality of Pilar, Sorsogon.', 'latitude' => null, 'longitude' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Prieto Diaz', 'description' => 'Municipality of Prieto Diaz, Sorsogon.', 'latitude' => null, 'longitude' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Santa Magdalena', 'description' => 'Municipality of Santa Magdalena, Sorsogon.', 'latitude' => null, 'longitude' => null, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Sorsogon City', 'description' => 'Capital city of the Province of Sorsogon.', 'latitude' => 12.9742, 'longitude' => 124.0049, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            // Destination categories
            ['name' => 'Beach', 'type' => 'destination', 'icon' => 'waves'],
            ['name' => 'Mountain', 'type' => 'destination', 'icon' => 'mountain'],
            ['name' => 'Waterfall', 'type' => 'destination', 'icon' => 'droplets'],
            ['name' => 'Historical', 'type' => 'destination', 'icon' => 'landmark'],
            ['name' => 'Church', 'type' => 'destination', 'icon' => 'church'],
            ['name' => 'Park', 'type' => 'destination', 'icon' => 'trees'],
            ['name' => 'Eco-Tourism', 'type' => 'destination', 'icon' => 'leaf'],
            ['name' => 'Island', 'type' => 'destination', 'icon' => 'palmtree'],
            ['name' => 'Resort', 'type' => 'destination', 'icon' => 'umbrella'],
            // Accommodation categories
            ['name' => 'Hotel', 'type' => 'accommodation', 'icon' => 'building'],
            ['name' => 'Resort', 'type' => 'accommodation', 'icon' => 'umbrella'],
            ['name' => 'Inn', 'type' => 'accommodation', 'icon' => 'home'],
            ['name' => 'Homestay', 'type' => 'accommodation', 'icon' => 'house'],
            // Food categories
            ['name' => 'Restaurant', 'type' => 'food_beverage', 'icon' => 'utensils'],
            ['name' => 'Cafe', 'type' => 'food_beverage', 'icon' => 'coffee'],
            ['name' => 'Street Food', 'type' => 'food_beverage', 'icon' => 'soup'],
            ['name' => 'Seafood', 'type' => 'food_beverage', 'icon' => 'fish'],
            // Retail categories
            ['name' => 'Souvenir', 'type' => 'retail_product', 'icon' => 'gift'],
            ['name' => 'Local Food Product', 'type' => 'retail_product', 'icon' => 'package'],
            ['name' => 'Handicraft', 'type' => 'retail_product', 'icon' => 'scissors'],
            ['name' => 'Clothing', 'type' => 'retail_product', 'icon' => 'shirt'],
        ];

        foreach ($categories as &$cat) {
            $cat['created_at'] = now();
            $cat['updated_at'] = now();
        }

        DB::table('categories')->insert($categories);
    }
}

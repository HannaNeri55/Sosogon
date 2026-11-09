<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amenities offered as checkboxes on the owner forms. Only suggestions: a listing's amenities are
 * the text saved on its own record, so a custom one stays on that record until an admin promotes
 * the name to a suggestion, and promoting changes no listing.
 */
return new class extends Migration
{
    private const PROPERTY = ['Free Parking', 'Parking', 'Swimming Pool', 'Free Wi-Fi', 'Restaurant', 'Bar/Lounge', 'Spa', 'Fitness Center', 'Free Breakfast', 'Breakfast', 'Garden', 'Beach Access', '24-Hour Front Desk', 'Function Hall', 'Conference Room', 'Playground', 'Outdoor Dining Area', 'Shared Kitchen', 'Laundry Service', 'Airport Shuttle', 'Security', 'Reception Area', 'Room Service', 'Family Rooms', 'Pet Friendly', 'Accessibility Facilities'];

    private const UNIT = ['Private Bathroom', 'Shared Bathroom', 'Refrigerator', 'Mini Refrigerator', 'Mini Bar', 'Electric Kettle', 'Kitchenette', 'Microwave', 'Dining Area', 'Balcony', 'Private Pool', 'Bathtub', 'Shower', 'Hot and Cold Shower', 'Hair Dryer', 'Wardrobe', 'Desk', 'Work Desk', 'Living Area', 'Sofa', 'KTV', 'Toiletries', 'Towels', 'Ocean View', 'Mountain View', 'Garden View'];

    private const BOTH = ['Air Conditioning', 'Television', 'Kitchen', 'Hot Shower'];

    public function up(): void
    {
        Schema::create('amenity_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            // property, unit or both.
            $table->string('scope', 20)->default('both');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $sort = 0;
        foreach (['property' => self::PROPERTY, 'unit' => self::UNIT, 'both' => self::BOTH] as $scope => $names) {
            foreach ($names as $name) {
                DB::table('amenity_suggestions')->insert(['name' => $name, 'scope' => $scope, 'sort_order' => $sort++, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('amenity_suggestions');
    }
};

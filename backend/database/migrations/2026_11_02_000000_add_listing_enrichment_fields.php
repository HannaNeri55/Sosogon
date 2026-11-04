<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured details for listings that the existing tables can't hold:
 * - destinations: on-site food (what's there and which meals) and visitor guidelines;
 * - accommodations: on-site dining;
 * - food places: which meals they serve and their specialties;
 * - shops: the kinds of products they carry;
 * - accommodation_room_types: the room types a stay offers, for information only. Unlike
 *   accommodation_rooms (bookable inventory the trip planner books), these are never booked.
 * All nullable: a listing without the information simply leaves them empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->text('dining_details')->nullable()->after('description');
            $table->json('meals_offered')->nullable()->after('dining_details');
            $table->text('visitor_guidelines')->nullable()->after('meals_offered');
        });
        Schema::table('accommodations', function (Blueprint $table) {
            $table->text('dining_details')->nullable()->after('amenities');
        });
        Schema::table('food_beverages', function (Blueprint $table) {
            $table->json('meals_served')->nullable()->after('cuisine_type');
            $table->text('specialties')->nullable()->after('meals_served');
        });
        Schema::table('retail_shops', function (Blueprint $table) {
            $table->json('product_categories')->nullable()->after('description');
        });

        Schema::create('accommodation_room_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('accommodation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('bed_configuration')->nullable();
            $table->unsignedSmallInteger('max_guests')->nullable();
            $table->json('amenities')->nullable();
            $table->decimal('price_min', 10, 2)->nullable();
            $table->decimal('price_max', 10, 2)->nullable();
            // What the price covers ("per night, 2 guests"), or why there's none.
            $table->string('price_note')->nullable();
            // True only when a source says breakfast comes with the rate; null when unknown.
            $table->boolean('breakfast_included')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['accommodation_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accommodation_room_types');
        Schema::table('retail_shops', fn (Blueprint $table) => $table->dropColumn('product_categories'));
        Schema::table('food_beverages', fn (Blueprint $table) => $table->dropColumn(['meals_served', 'specialties']));
        Schema::table('accommodations', fn (Blueprint $table) => $table->dropColumn('dining_details'));
        Schema::table('destinations', fn (Blueprint $table) => $table->dropColumn(['dining_details', 'meals_offered', 'visitor_guidelines']));
    }
};

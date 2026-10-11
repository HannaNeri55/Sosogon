<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the trip's budget is built from: the rooms booked at the stay, and the dishes
        // and souvenirs picked at each food or souvenir stop (with quantities).
        Schema::create('itinerary_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('itinerary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accommodation_room_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['itinerary_id', 'accommodation_room_id'], 'itinerary_rooms_unique');
        });

        Schema::create('itinerary_item_dishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('itinerary_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();
            $table->unique(['itinerary_item_id', 'menu_item_id'], 'itinerary_item_dishes_unique');
        });

        Schema::create('itinerary_item_souvenirs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('itinerary_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retail_product_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->timestamps();
            // Explicit name: the generated one is over MySQL's 64-character limit.
            $table->unique(['itinerary_item_id', 'retail_product_id'], 'itinerary_item_souvenirs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('itinerary_item_souvenirs');
        Schema::dropIfExists('itinerary_item_dishes');
        Schema::dropIfExists('itinerary_rooms');
    }
};

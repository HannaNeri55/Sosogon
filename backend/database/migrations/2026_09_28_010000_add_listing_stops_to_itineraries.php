<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Itinerary stops can now be a tourist destination, the tourist's accommodation
        // (free days), a food place (dinner) or a souvenir shop. Existing rows are all
        // destination stops, which is what the item_type default covers.
        Schema::table('itinerary_items', function (Blueprint $table) {
            $table->unsignedBigInteger('destination_id')->nullable()->change();
            $table->string('item_type', 20)->default('destination')->after('itinerary_id');
            $table->foreignId('accommodation_id')->nullable()->after('destination_id')->constrained()->nullOnDelete();
            $table->foreignId('food_beverage_id')->nullable()->after('accommodation_id')->constrained()->nullOnDelete();
            $table->foreignId('retail_shop_id')->nullable()->after('food_beverage_id')->constrained()->nullOnDelete();
        });

        Schema::table('itineraries', function (Blueprint $table) {
            $table->foreignId('accommodation_id')->nullable()->after('municipality_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accommodation_id');
        });

        Schema::table('itinerary_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retail_shop_id');
            $table->dropConstrainedForeignId('food_beverage_id');
            $table->dropConstrainedForeignId('accommodation_id');
            $table->dropColumn('item_type');
            $table->unsignedBigInteger('destination_id')->nullable(false)->change();
        });
    }
};

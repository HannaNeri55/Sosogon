<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the tourist said they'd get around (private_car, motorcycle, rented_vehicle,
 * public_transport or no_preference), kept so the itinerary can show matching directions.
 * Itineraries saved before this have none and are treated as no preference.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->string('transport_preference', 30)->nullable()->after('accommodation_type');
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropColumn('transport_preference');
        });
    }
};

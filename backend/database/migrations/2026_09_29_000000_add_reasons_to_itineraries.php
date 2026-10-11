<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Why each stop (and the trip's accommodation) was recommended. Nullable so
        // itineraries generated before this existed keep working.
        Schema::table('itineraries', function (Blueprint $table) {
            $table->text('accommodation_reason')->nullable()->after('accommodation_id');
        });

        Schema::table('itinerary_items', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('itinerary_items', function (Blueprint $table) {
            $table->dropColumn('reason');
        });

        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropColumn('accommodation_reason');
        });
    }
};

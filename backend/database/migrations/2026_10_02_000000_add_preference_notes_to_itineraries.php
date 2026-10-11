<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An apology shown when the planner couldn't follow the tourist's form (stay type,
        // cuisine, budget, or a meal moved to the stay because no other place was left).
        Schema::table('itineraries', function (Blueprint $table) {
            $table->text('accommodation_note')->nullable()->after('accommodation_reason');
        });

        Schema::table('itinerary_items', function (Blueprint $table) {
            $table->text('preference_note')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('itinerary_items', function (Blueprint $table) {
            $table->dropColumn('preference_note');
        });

        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropColumn('accommodation_note');
        });
    }
};

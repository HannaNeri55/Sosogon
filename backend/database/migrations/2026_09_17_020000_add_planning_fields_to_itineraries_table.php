<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->date('end_date')->nullable()->after('start_date');
            $table->string('food_preference', 50)->nullable()->after('budget');
            $table->string('accommodation_type', 50)->nullable()->after('food_preference');
            $table->text('additional_preferences')->nullable()->after('accommodation_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropColumn(['end_date', 'food_preference', 'accommodation_type', 'additional_preferences']);
        });
    }
};

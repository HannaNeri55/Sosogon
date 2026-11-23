<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether visitors may bring their own food to a destination (a beach where day guests picnic):
     * trips then plan a packed lunch there instead of a trip to a restaurant. Empty (not stated)
     * for every existing destination.
     */
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->boolean('outside_food_allowed')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn('outside_food_allowed');
        });
    }
};

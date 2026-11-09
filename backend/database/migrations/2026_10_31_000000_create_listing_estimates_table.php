<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estimated values for listing details that are missing (typical opening hours, an entrance
 * fee range, usual check-in time, price ranges…), kept apart from the listings' own columns
 * so a real value always wins and estimates never overwrite what owners or admins entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_estimates', function (Blueprint $table) {
            $table->id();
            $table->morphs('estimable');
            // e.g. operating_hours, entrance_fee, check_in, check_out, cuisine_type, nightly_rate, meal_price, item_price
            $table->string('field', 50);
            $table->string('value');
            // Internal only: the rule the estimate came from (never shown publicly).
            $table->string('basis');
            $table->timestamps();

            $table->unique(['estimable_type', 'estimable_id', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_estimates');
    }
};

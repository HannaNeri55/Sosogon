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
        Schema::create('itineraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedTinyInteger('num_days');
            $table->json('interests');
            $table->string('travel_pace', 50);
            $table->string('budget', 50);
            $table->date('start_date')->nullable();
            $table->text('ai_response_raw')->nullable();
            $table->decimal('total_distance_km', 8, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('itinerary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('itinerary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained();
            $table->unsignedTinyInteger('day_number');
            $table->unsignedTinyInteger('sequence');
            $table->string('time_slot', 50)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->unsignedSmallInteger('travel_time_from_prev')->nullable();
            $table->decimal('distance_from_prev_km', 6, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itinerary_items');
        Schema::dropIfExists('itineraries');
    }
};

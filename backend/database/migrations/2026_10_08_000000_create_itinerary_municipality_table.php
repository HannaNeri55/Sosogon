<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('itinerary_municipality', function (Blueprint $table) {
            $table->id();
            $table->foreignId('itinerary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipality_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['itinerary_id', 'municipality_id']);
        });

        // Every existing itinerary was planned for the one town in itineraries.municipality_id.
        DB::table('itinerary_municipality')->insertUsing(
            ['itinerary_id', 'municipality_id', 'created_at', 'updated_at'],
            DB::table('itineraries')->whereNotNull('municipality_id')->select('id', 'municipality_id', 'created_at', 'updated_at')
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itinerary_municipality');
    }
};

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
        // One recommended stay per town of the trip; a stay's rooms are the itinerary_rooms
        // rows whose room belongs to its accommodation.
        Schema::create('itinerary_stays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('itinerary_id')->constrained()->cascadeOnDelete();
            $table->foreignId('municipality_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('accommodation_id')->nullable()->constrained()->nullOnDelete();
            $table->json('night_days')->nullable();
            $table->text('reason')->nullable();
            $table->text('note')->nullable();
            $table->decimal('cost_min', 10, 2)->nullable();
            $table->decimal('cost_max', 10, 2)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // Every existing itinerary had one stay for every night of the trip.
        DB::table('itineraries')->whereNotNull('accommodation_id')->orderBy('id')->each(function ($itinerary) {
            DB::table('itinerary_stays')->insert([
                'itinerary_id' => $itinerary->id,
                'municipality_id' => $itinerary->municipality_id,
                'accommodation_id' => $itinerary->accommodation_id,
                'night_days' => json_encode(range(1, max((int) $itinerary->num_days - 1, 1))),
                'reason' => $itinerary->accommodation_reason,
                'note' => $itinerary->accommodation_note,
                'cost_min' => $itinerary->accommodation_cost_min,
                'cost_max' => $itinerary->accommodation_cost_max,
                'sort' => 0,
                'created_at' => $itinerary->created_at,
                'updated_at' => $itinerary->updated_at,
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('itinerary_stays');
    }
};

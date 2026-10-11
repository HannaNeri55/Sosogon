<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Peso cost estimates (for the whole group of travelers) and a timed activity list
        // per destination block. All nullable/defaulted so older itineraries keep working.
        Schema::table('itineraries', function (Blueprint $table) {
            $table->unsignedTinyInteger('travelers')->default(1)->after('budget');
            $table->decimal('estimated_cost_min', 10, 2)->nullable()->after('total_distance_km');
            $table->decimal('estimated_cost_max', 10, 2)->nullable()->after('estimated_cost_min');
            $table->decimal('accommodation_cost_min', 10, 2)->nullable()->after('accommodation_reason');
            $table->decimal('accommodation_cost_max', 10, 2)->nullable()->after('accommodation_cost_min');
        });

        Schema::table('itinerary_items', function (Blueprint $table) {
            $table->json('activities')->nullable()->after('reason');
            $table->decimal('cost_min', 10, 2)->nullable()->after('activities');
            $table->decimal('cost_max', 10, 2)->nullable()->after('cost_min');
            $table->string('cost_note', 100)->nullable()->after('cost_max');
        });
    }

    public function down(): void
    {
        Schema::table('itinerary_items', function (Blueprint $table) {
            $table->dropColumn(['activities', 'cost_min', 'cost_max', 'cost_note']);
        });

        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropColumn(['travelers', 'estimated_cost_min', 'estimated_cost_max', 'accommodation_cost_min', 'accommodation_cost_max']);
        });
    }
};

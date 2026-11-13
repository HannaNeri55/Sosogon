<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two admin-managed facts the itinerary planner relies on instead of guessing:
 * - tourism_priority: how much the place is worth a trip (iconic, popular, recommended,
 *   hidden_gem), set by the tourism office; empty until they classify it.
 * - setting: whether a visit happens indoors, in the open air or partly under cover, so a
 *   rain plan never sends tourists to an open-air place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->string('tourism_priority', 20)->nullable()->after('is_featured');
            $table->string('setting', 10)->nullable()->after('tourism_priority');
        });

        // The tourism office confirmed the Balogo sports complex is an open-air facility.
        DB::table('destinations')->where('name', 'like', 'Balogo%')->update(['setting' => 'outdoor']);
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn(['tourism_priority', 'setting']);
        });
    }
};

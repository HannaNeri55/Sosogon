<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each day's title and main highlight, as generated: {"1": {"title": "…", "highlight_id": 24}}.
 * Older itineraries have none and show the day's towns instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->json('day_plans')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropColumn('day_plans');
        });
    }
};

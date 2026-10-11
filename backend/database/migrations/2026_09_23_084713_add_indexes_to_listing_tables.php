<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `status` is filtered on in every public listing query and had no index
     * anywhere; as data grows this forces a full table scan on every browse
     * request. Same for the other columns commonly used as filters.
     */
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->index('status');
            $table->index(['status', 'municipality_id']);
        });

        Schema::table('accommodations', function (Blueprint $table) {
            $table->index('status');
            $table->index('type');
            $table->index('price_range');
            $table->index(['status', 'municipality_id']);
        });

        Schema::table('food_beverages', function (Blueprint $table) {
            $table->index('status');
            $table->index('cuisine_type');
            $table->index('price_range');
            $table->index(['status', 'municipality_id']);
        });

        Schema::table('retail_shops', function (Blueprint $table) {
            $table->index('status');
            $table->index(['status', 'municipality_id']);
        });

        Schema::table('business_submissions', function (Blueprint $table) {
            $table->index('status');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->index('status');
            $table->index('start_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['status', 'municipality_id']);
        });

        Schema::table('accommodations', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['type']);
            $table->dropIndex(['price_range']);
            $table->dropIndex(['status', 'municipality_id']);
        });

        Schema::table('food_beverages', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['cuisine_type']);
            $table->dropIndex(['price_range']);
            $table->dropIndex(['status', 'municipality_id']);
        });

        Schema::table('retail_shops', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['status', 'municipality_id']);
        });

        Schema::table('business_submissions', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['start_date']);
        });
    }
};

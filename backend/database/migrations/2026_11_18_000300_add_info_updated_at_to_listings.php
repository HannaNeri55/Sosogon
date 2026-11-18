<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Last Updated" for the public detail pages: when a listing's public information was last
     * published or changed while public (App\Models\Concerns\TracksInfoUpdates). Empty for existing
     * listings: no date is backfilled, it is set by the first real update.
     */
    private const TABLES = ['destinations', 'accommodations', 'food_beverages', 'retail_shops'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('info_updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('info_updated_at');
            });
        }
    }
};

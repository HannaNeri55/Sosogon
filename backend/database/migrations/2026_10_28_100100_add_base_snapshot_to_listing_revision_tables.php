<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of the published listing (revision-editable fields + gallery) taken
 * when a revision starts. On approval it shows what changed on the live listing
 * since, so a revision can never silently overwrite newer approved data
 * (see App\Support\RevisionBase). Existing revisions keep NULL (no check).
 */
return new class extends Migration
{
    private array $tables = ['accommodation_revisions', 'food_beverage_revisions', 'retail_shop_revisions'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->json('base_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('base_snapshot');
            });
        }
    }
};

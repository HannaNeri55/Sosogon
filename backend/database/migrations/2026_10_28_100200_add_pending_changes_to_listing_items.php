<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proposed changes to an APPROVED room, menu item or retail product, held for
 * admin review while the approved version stays public (see HasPendingChanges).
 * Shape: { values, new_images, remove_image_ids, base_hash, status, notes, submitted_at }.
 */
return new class extends Migration
{
    private array $tables = ['accommodation_rooms', 'menu_items', 'retail_products'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->json('pending_changes')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('pending_changes');
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Attribution for licensed photos (e.g. Creative Commons images from
     * Wikimedia Commons). Both columns are optional: existing photos and
     * admin uploads keep NULL and display exactly as before.
     */
    public function up(): void
    {
        Schema::table('destination_images', function (Blueprint $table) {
            $table->string('credit', 500)->nullable()->after('alt_text');
            $table->string('source_url', 2048)->nullable()->after('credit');
        });
    }

    public function down(): void
    {
        Schema::table('destination_images', function (Blueprint $table) {
            $table->dropColumn(['credit', 'source_url']);
        });
    }
};

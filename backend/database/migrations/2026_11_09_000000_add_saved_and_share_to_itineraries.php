<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * saved_at: when the tourist pressed Save (itineraries are stored in the account from the moment
 * they are generated; this records that the tourist chose to keep one).
 * share_token: the secret in the itinerary's share link. Empty means the itinerary is private.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->timestamp('saved_at')->nullable();
            $table->string('share_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropUnique(['share_token']);
            $table->dropColumn(['saved_at', 'share_token']);
        });
    }
};

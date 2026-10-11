<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guests can generate an itinerary before logging in. It is saved without an owner
        // and a hashed claim token; logging in with that token attaches it to the tourist.
        Schema::table('itineraries', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('claim_token', 64)->nullable()->unique()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('itineraries', function (Blueprint $table) {
            $table->dropUnique(['claim_token']);
            $table->dropColumn('claim_token');
        });

        // Unclaimed guest itineraries have no owner and can't satisfy NOT NULL.
        DB::table('itineraries')->whereNull('user_id')->delete();

        Schema::table('itineraries', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};

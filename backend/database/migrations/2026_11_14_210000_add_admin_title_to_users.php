<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How an admin account is designated in the admin area, e.g. "System Administrator" for the
 * development team's provincial-scope accounts. Null uses the default from the scope
 * ("Provincial Administrator", or "<Municipality> Tourism Office"); see User::adminProfile().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('admin_title', 100)->nullable()->after('municipality_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('admin_title');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Municipality-based administration (see App\Support\AdminScope). Only nullable columns are
 * added; existing rows keep their values and behave as before:
 *  - users.admin_scope: 'provincial' (every municipality) or 'municipal' (users.municipality_id
 *    only). Null on admins means not assigned yet, which keeps today's full access.
 *  - users.municipality_id: the municipal admin's municipality.
 *  - announcements.municipality_id: the municipality an announcement belongs to; null is
 *    province-wide (all existing announcements).
 *  - account_violations.rating_id: the review an action was about, so a municipal admin may act
 *    on a tourist over a review of their own municipality's listing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('admin_scope', 20)->nullable()->after('role_id');
            $table->foreignId('municipality_id')->nullable()->after('admin_scope')->constrained()->nullOnDelete();
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->foreignId('municipality_id')->nullable()->after('status')->constrained()->nullOnDelete();
        });

        Schema::table('account_violations', function (Blueprint $table) {
            $table->foreignId('rating_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_violations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rating_id');
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('municipality_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('municipality_id');
            $table->dropColumn('admin_scope');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account enforcement on users, without changing the existing status values:
 * - status 'suspended' + suspended_until in the future = temporarily suspended
 * - status 'suspended' + no suspended_until             = deactivated
 * - closed_at set                                         = account closed (data kept)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_until')->nullable()->after('status');
            $table->timestamp('closed_at')->nullable()->after('suspended_until');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['suspended_until', 'closed_at']);
        });
    }
};

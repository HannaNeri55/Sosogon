<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('business_submissions', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected', 'removed'])->default('pending')->change();
            $table->timestamp('warned_at')->nullable()->after('reviewed_at');
            $table->text('warning_notes')->nullable()->after('warned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_submissions', function (Blueprint $table) {
            $table->dropColumn(['warned_at', 'warning_notes']);
        });

        Schema::table('business_submissions', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->change();
        });
    }
};

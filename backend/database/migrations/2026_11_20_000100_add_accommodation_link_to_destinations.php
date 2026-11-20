<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A destination that is also listed as an accommodation (a resort with day-use swimming and
     * rooms, like Isabel's Resort): the link the Tourism Office sets in Manage Destinations. Empty
     * for every existing destination; removing the accommodation just clears it.
     */
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->foreignId('accommodation_id')->nullable()->constrained('accommodations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accommodation_id');
        });
    }
};

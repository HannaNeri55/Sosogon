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
        Schema::table('accommodation_rooms', function (Blueprint $table) {
            $table->renameColumn('name', 'room_number');
        });

        Schema::table('accommodation_rooms', function (Blueprint $table) {
            $table->string('category')->nullable()->after('room_number');
            $table->decimal('price', 10, 2)->nullable()->after('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accommodation_rooms', function (Blueprint $table) {
            $table->dropColumn(['category', 'price']);
        });

        Schema::table('accommodation_rooms', function (Blueprint $table) {
            $table->renameColumn('room_number', 'name');
        });
    }
};

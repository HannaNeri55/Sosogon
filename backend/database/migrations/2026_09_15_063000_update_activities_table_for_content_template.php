<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('distance_label');
            $table->string('type', 20)->nullable()->after('description');
            $table->string('best_time_to_visit')->nullable()->after('type');
            $table->string('what_to_wear')->nullable()->after('best_time_to_visit');
            $table->text('things_to_do')->nullable()->after('what_to_wear');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['type', 'best_time_to_visit', 'what_to_wear', 'things_to_do']);
            $table->string('distance_label', 100)->nullable();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks seeded demonstration reviews explicitly, instead of relying on the
     * "Demo Review:" comment prefix. Existing rows default to false (genuine);
     * marking existing demo rows is done by DemoDestinationRatingSeeder.
     */
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->after('comment')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};

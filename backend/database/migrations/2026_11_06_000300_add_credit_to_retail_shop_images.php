<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retail_shop_images', function (Blueprint $table) {
            $table->string('image_credit')->nullable();
            $table->string('image_source_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('retail_shop_images', function (Blueprint $table) {
            $table->dropColumn(['image_credit', 'image_source_url']);
        });
    }
};

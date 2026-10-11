<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_submissions', function (Blueprint $table) {
            $table->unsignedBigInteger('listing_id')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('business_submissions', function (Blueprint $table) {
            $table->dropColumn('listing_id');
        });
    }
};

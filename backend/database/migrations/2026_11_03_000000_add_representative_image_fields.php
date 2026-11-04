<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Images for activities and room types that may be representative (illustrating the activity or
 * room category, not necessarily the place): who made the photo and its licence ("credit"),
 * and the page it came from, so licences that require attribution are honoured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('image_credit', 500)->nullable()->after('thumbnail_path');
            $table->string('image_source_url', 500)->nullable()->after('image_credit');
        });
        Schema::table('accommodation_room_types', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('source_url');
            $table->string('thumbnail_path')->nullable()->after('image_path');
            $table->string('image_credit', 500)->nullable()->after('thumbnail_path');
            $table->string('image_source_url', 500)->nullable()->after('image_credit');
        });
    }

    public function down(): void
    {
        Schema::table('accommodation_room_types', fn (Blueprint $table) => $table->dropColumn(['image_path', 'thumbnail_path', 'image_credit', 'image_source_url']));
        Schema::table('activities', fn (Blueprint $table) => $table->dropColumn(['image_credit', 'image_source_url']));
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stay offers zero or more accommodation options: a room, villa, cottage or other unit, or the
 * entire property, each a row of accommodation_rooms. room_number stays required and mirrors
 * name, and occupancy_status is kept but no longer read: SOSOGON doesn't track availability.
 * Every new field is optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accommodations', function (Blueprint $table) {
            // units, entire_property or both; empty means not stated and is treated as units.
            $table->string('offering_arrangement', 20)->nullable()->after('type');
            $table->json('classifications')->nullable()->after('offering_arrangement');
            $table->string('check_in_time', 50)->nullable()->after('operating_hours');
            $table->string('check_out_time', 50)->nullable()->after('check_in_time');
        });

        Schema::table('accommodation_rooms', function (Blueprint $table) {
            $table->string('unit_type', 30)->nullable()->after('accommodation_id');
            $table->string('name')->nullable()->after('unit_type');
            // How many similar units this record stands for (10 Superior Rooms = one record).
            $table->unsignedSmallInteger('unit_count')->nullable()->after('name');
            $table->unsignedSmallInteger('max_guests')->nullable();
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->string('bed_configuration')->nullable();
            $table->decimal('size_sqm', 7, 2)->nullable();
            // indicative (price given), varies, or contact (ask the property).
            $table->string('pricing_mode', 20)->nullable();
            $table->decimal('price_max', 10, 2)->nullable();
            $table->string('price_note')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('breakfast_included')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('source_url', 500)->nullable();
            // The accommodation_room_types row this was copied from, if any.
            $table->unsignedBigInteger('legacy_room_type_id')->nullable()->unique();
        });

        Schema::table('accommodation_room_images', function (Blueprint $table) {
            $table->string('image_credit', 500)->nullable()->after('thumbnail_path');
            $table->string('image_source_url', 500)->nullable()->after('image_credit');
        });
    }

    public function down(): void
    {
        Schema::table('accommodation_room_images', fn (Blueprint $table) => $table->dropColumn(['image_credit', 'image_source_url']));
        Schema::table('accommodation_rooms', function (Blueprint $table) {
            $table->dropUnique(['legacy_room_type_id']);
            $table->dropColumn(['unit_type', 'name', 'unit_count', 'max_guests', 'bedrooms', 'bed_configuration', 'size_sqm', 'pricing_mode', 'price_max', 'price_note', 'notes', 'breakfast_included', 'sort_order', 'source_url', 'legacy_room_type_id']);
        });
        Schema::table('accommodations', fn (Blueprint $table) => $table->dropColumn(['offering_arrangement', 'classifications', 'check_in_time', 'check_out_time']));
    }
};

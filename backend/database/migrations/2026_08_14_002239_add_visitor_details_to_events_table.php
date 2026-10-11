<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('what_to_wear')->nullable()->after('end_date');
            $table->string('what_to_bring')->nullable()->after('what_to_wear');
            $table->json('travel_tips')->nullable()->after('what_to_bring');
            $table->string('contact_name')->nullable()->after('travel_tips');
            $table->string('contact_phone')->nullable()->after('contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['what_to_wear', 'what_to_bring', 'travel_tips', 'contact_name', 'contact_phone']);
        });
    }
};

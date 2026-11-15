<?php

use Database\Seeders\MunicipalityProfileSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The About page's municipality profiles: contact details, geography, history and a
     * representative photo for each of the 15 local government units, kept by provincial admins.
     * Then fills in the researched profiles (only fields that are still empty; see the seeder).
     */
    public function up(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->string('lgu_type', 20)->default('municipality')->after('name');
            $table->string('contact_number')->nullable()->after('longitude');
            $table->string('email')->nullable()->after('contact_number');
            $table->string('address')->nullable()->after('email');
            $table->text('other_contacts')->nullable()->after('address');
            $table->text('geography')->nullable()->after('other_contacts');
            $table->text('boundaries')->nullable()->after('geography');
            $table->text('history')->nullable()->after('boundaries');
            $table->text('name_origin')->nullable()->after('history');
            $table->text('sources')->nullable()->after('name_origin');
            $table->string('image_path')->nullable()->after('sources');
            $table->string('thumbnail_path')->nullable()->after('image_path');
            $table->foreignId('profile_updated_by')->nullable()->after('thumbnail_path')->constrained('users')->nullOnDelete();
        });

        (new MunicipalityProfileSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('municipalities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('profile_updated_by');
            $table->dropColumn([
                'lgu_type', 'contact_number', 'email', 'address', 'other_contacts', 'geography', 'boundaries',
                'history', 'name_origin', 'sources', 'image_path', 'thumbnail_path',
            ]);
        });
    }
};

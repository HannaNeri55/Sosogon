<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * listing_verifications: each time the Tourism Office confirmed one part of a listing's
     * information (price, location, hours or contact), with a fingerprint and copy of the values
     * checked. Rows are only ever added, so the history is kept. See App\Services\ListingVerifications.
     *
     * site_settings: values the Tourism Office can change without a code change (for now, the
     * number of days after which a verification is overdue).
     */
    public function up(): void
    {
        Schema::create('listing_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('listing_type', 40);
            $table->unsignedBigInteger('listing_id');
            $table->string('aspect', 20);
            $table->string('fingerprint', 64);
            $table->json('snapshot');
            $table->string('note', 500)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at');
            $table->timestamps();
            $table->index(['listing_type', 'listing_id', 'aspect', 'verified_at'], 'listing_verifications_lookup');
        });

        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('listing_verifications');
    }
};

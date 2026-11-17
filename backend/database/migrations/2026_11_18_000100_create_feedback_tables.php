<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Feedback and concerns sent from the public site, and the Tourism Office's internal notes on
     * them. Never shown publicly. See App\Models\FeedbackSubmission.
     */
    public function up(): void
    {
        Schema::create('feedback_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->string('name', 100)->nullable();
            $table->string('email', 255)->nullable();
            $table->boolean('wants_reply')->default(false);
            $table->string('category', 40)->index();
            $table->string('subject', 150);
            $table->text('message');
            $table->string('module', 40)->nullable();
            $table->string('listing_type', 40)->nullable();
            $table->unsignedBigInteger('listing_id')->nullable();
            // The listing's municipality, so the right office sees it; null reaches the provincial admins.
            $table->foreignId('municipality_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // HMAC of the sender's IP address (never the address itself) and of the text, to catch resends.
            $table->string('ip_hash', 64)->nullable();
            $table->string('content_hash', 64)->index();
            $table->timestamps();
            $table->index(['listing_type', 'listing_id']);
        });

        Schema::create('feedback_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feedback_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_notes');
        Schema::dropIfExists('feedback_submissions');
    }
};

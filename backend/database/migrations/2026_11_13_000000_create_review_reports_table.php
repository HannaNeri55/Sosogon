<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review moderation: a listing's owner reports a review (reason, details, optional evidence) and
 * the Tourism Office decides. Taking action hides the review from the public (hidden_at) without
 * deleting it; dismissing a report leaves the review as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('is_demo');
        });

        Schema::create('review_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rating_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 40);
            $table->text('details');
            $table->string('evidence_path')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_reports');
        Schema::table('ratings', function (Blueprint $table) {
            $table->dropColumn('hidden_at');
        });
    }
};

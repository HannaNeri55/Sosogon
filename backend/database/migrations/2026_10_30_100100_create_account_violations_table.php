<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per enforcement action (warning, temporary suspension, deactivation).
 * Kept permanently, including after reactivation or a successful appeal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 60);
            $table->string('reason', 255);
            $table->text('details');
            $table->text('evidence_notes')->nullable();
            $table->json('evidence_files')->nullable();
            $table->string('action', 20); // warning | suspension | deactivation
            $table->unsignedSmallInteger('suspension_days')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->date('review_date')->nullable();
            $table->date('reported_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            // active | expired | lifted (reactivated by an admin) | reversed (appeal approved)
            $table->string('status', 20)->default('active');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['action', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_violations');
    }
};

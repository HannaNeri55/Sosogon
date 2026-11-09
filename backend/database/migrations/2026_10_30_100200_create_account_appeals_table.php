<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A user's appeal against one enforcement action, and the admin's decision on it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_violation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('explanation');
            $table->json('attachments')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_appeals');
    }
};

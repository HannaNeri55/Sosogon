<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail of account management actions. Rows are never updated or
 * deleted by the application (see App\Models\AccountActionLog), and deleting a user
 * keeps their log rows (user references are nulled, the names are kept as text).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('admin_name')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('action', 40);
            $table->string('previous_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();
            $table->text('reason')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_action_logs');
    }
};

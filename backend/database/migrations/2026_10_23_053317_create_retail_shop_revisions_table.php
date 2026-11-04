
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retail_shop_revisions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('retail_shop_id')
                ->constrained('retail_shops')
                ->cascadeOnDelete();

            $table->foreignId('submitted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Proposed shop information.
            $table->json('proposed_data');

            // Proposed gallery changes.
            $table->json('proposed_gallery')->nullable();

            // Review status separate from published shop.
            $table->enum('status', [
                'draft',
                'submitted',
                'approved',
                'needs_revision',
            ])->default('draft');

            $table->text('admin_notes')->nullable();

            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(
                ['retail_shop_id', 'status'],
                'retail_shop_revision_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retail_shop_revisions');
    }
};

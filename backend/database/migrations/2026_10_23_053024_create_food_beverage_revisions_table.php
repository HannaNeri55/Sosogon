
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('food_beverage_revisions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('food_beverage_id')
                ->constrained('food_beverages')
                ->cascadeOnDelete();

            $table->foreignId('submitted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Proposed establishment information.
            $table->json('proposed_data');

            // Proposed gallery state:
            // existing image IDs, new uploaded image paths,
            // cover selection, and display order.
            $table->json('proposed_gallery')->nullable();

            // Separate review status from the published listing.
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
                ['food_beverage_id', 'status'],
                'food_beverage_revision_status_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('food_beverage_revisions');
    }
};

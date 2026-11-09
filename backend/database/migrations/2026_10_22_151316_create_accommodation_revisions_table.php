
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
        Schema::create('accommodation_revisions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('accommodation_id')
                ->constrained('accommodations')
                ->cascadeOnDelete();

            $table->foreignId('submitted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Proposed property information.
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
                ['accommodation_id', 'status'],
                'accommodation_revision_status_index'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accommodation_revisions');
    }
};


<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'accommodations',
        'food_beverages',
        'retail_shops',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('publication_review_status', 30)
                    ->nullable();
            });

            // Preserve existing approved listings.
            DB::table($tableName)
                ->where('status', 'approved')
                ->update([
                    'publication_review_status' => 'published',
                ]);

            // Preserve any existing pending review workflows.
            DB::table($tableName)
                ->where('status', 'pending')
                ->update([
                    'publication_review_status' => 'submitted',
                ]);

            DB::table($tableName)
                ->where('status', 'rejected')
                ->update([
                    'publication_review_status' => 'needs_revision',
                ]);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('publication_review_status');
            });
        }
    }
};

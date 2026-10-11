<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `price` was never in BusinessSubmission::$fillable, so it has always
     * been silently dropped on create — nothing populates it. It's also
     * semantically wrong now that a retail_product submission creates a shop,
     * not a priced product; price belongs on the product, not the submission.
     */
    public function up(): void
    {
        Schema::table('business_submissions', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_submissions', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('operating_hours');
        });
    }
};

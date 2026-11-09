<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Internal record of where a listing field's value came from. A field with no row here is
     * original data; see App\Models\FieldProvenance. Never exposed through the public API.
     */
    public function up(): void
    {
        Schema::create('field_provenance', function (Blueprint $table) {
            $table->id();
            $table->string('record_table', 60);
            $table->unsignedBigInteger('record_id');
            $table->string('field', 60);
            $table->string('source', 20);
            $table->longText('recorded_value')->nullable();
            $table->longText('previous_value')->nullable();
            $table->string('batch', 60)->nullable()->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['record_table', 'record_id', 'field'], 'field_provenance_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_provenance');
    }
};

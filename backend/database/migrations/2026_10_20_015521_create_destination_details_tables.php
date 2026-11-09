
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Multiple entrance and admission fees
        Schema::create('destination_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('label');
            $table->decimal('amount', 10, 2);
            $table->string('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Cottage rentals, corkage, function halls, etc.
        Schema::create('destination_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('label');
            $table->decimal('amount', 10, 2);
            $table->string('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Available facilities and amenities
        Schema::create('destination_amenities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_amenities');
        Schema::dropIfExists('destination_rates');
        Schema::dropIfExists('destination_fees');
    }
};

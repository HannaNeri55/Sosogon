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
        Schema::create('food_beverages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('name');
            $table->text('description');
            $table->string('cuisine_type', 100)->nullable();
            $table->foreignId('municipality_id')->constrained();
            $table->foreignId('barangay_id')->constrained();
            $table->string('address', 500);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->enum('price_range', ['budget', 'moderate', 'luxury']);
            $table->string('contact_number', 20)->nullable();
            $table->string('operating_hours', 255)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'archived'])->default('pending');
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->integer('total_reviews')->default(0);
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('food_beverage_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('food_beverage_id')->constrained()->cascadeOnDelete();
            $table->string('image_path', 500);
            $table->string('thumbnail_path', 500);
            $table->boolean('is_primary')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('food_beverage_images');
        Schema::dropIfExists('food_beverages');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks demonstration businesses. A sample submission produces a sample
 * listing on approval; public queries hide samples unless
 * config('listings.show_samples') is on. Existing rows stay real (false).
 */
return new class extends Migration
{
    private array $tables = ['business_submissions', 'accommodations', 'food_beverages', 'retail_shops'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->boolean('is_sample')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex("{$name}_is_sample_index");
                $table->dropColumn('is_sample');
            });
        }
    }
};

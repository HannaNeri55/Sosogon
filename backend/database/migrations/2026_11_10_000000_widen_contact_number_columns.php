<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Some listings store two numbers ("0918 915 8005 / 0917 876 1338"); 20 characters was too
// short, and MySQL rejects longer values where SQLite silently kept them.
return new class extends Migration
{
    private array $tables = ['destinations', 'accommodations', 'food_beverages', 'retail_shops', 'retail_products', 'business_submissions'];

    public function up(): void
    {
        $this->resize(50);
    }

    public function down(): void
    {
        $this->resize(20);
    }

    private function resize(int $length): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) use ($name, $length) {
                $table->string('contact_number', $length)->nullable($name !== 'business_submissions')->change();
            });
        }
    }
};

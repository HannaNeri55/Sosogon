<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Page views of the public website: one row per page per day (Sorsogon calendar day), counting
 * each time a visitor opened that page. Replaces counting detail API calls (visitor_statistics,
 * kept as it was) as the source of the admin's visitor figures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_views', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('path', 191);
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();

            $table->unique(['date', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_views');
    }
};

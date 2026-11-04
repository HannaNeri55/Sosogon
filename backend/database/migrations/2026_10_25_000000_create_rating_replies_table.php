<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business Owner responses to reviews. Kept in their own table so a reply
     * can never overwrite the Tourist's original rating comment.
     */
    public function up(): void
    {
        Schema::create('rating_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rating_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_replies');
    }
};

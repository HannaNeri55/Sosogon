<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Business Owner's registration: business details plus the business permit
 * (private disk), reviewed by an admin before the account is activated.
 * One row per owner; resubmissions after a rejection update the same row.
 * Owners who registered before this existed simply have no row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_owner_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_name');
            $table->enum('business_type', ['accommodation', 'food_beverage', 'retail_product']);
            $table->string('business_address', 500);
            $table->foreignId('municipality_id')->constrained();
            $table->foreignId('barangay_id')->nullable()->constrained();
            $table->string('business_permit_number', 100);
            $table->string('business_permit_path', 500);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('resubmitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_owner_applications');
    }
};

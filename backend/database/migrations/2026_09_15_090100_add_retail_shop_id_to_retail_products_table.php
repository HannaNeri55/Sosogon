<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('retail_products', function (Blueprint $table) {
            $table->foreignId('retail_shop_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->text('admin_notes')->nullable()->after('status');
        });

        $this->backfillShopsForExistingProducts();
    }

    /**
     * Every retail_products row predates the retail_shops table and carries its
     * own address/municipality/contact fields. Group them by location and give
     * each group a synthetic shop so existing (seeded) products stay linked to
     * a shop instead of being orphaned by the new foreign key.
     */
    private function backfillShopsForExistingProducts(): void
    {
        $products = DB::table('retail_products')->whereNull('retail_shop_id')->get();

        $groups = $products->groupBy(fn ($product) => "{$product->municipality_id}:{$product->barangay_id}");

        foreach ($groups as $members) {
            $first = $members->first();

            $municipalityName = DB::table('municipalities')->where('id', $first->municipality_id)->value('name');

            $shopId = DB::table('retail_shops')->insertGetId([
                'user_id' => null,
                'name' => trim(($municipalityName ?: 'Local').' Pasalubong Center'),
                'description' => 'A collection of local products from this area, migrated from the previous single-table product listings.',
                'municipality_id' => $first->municipality_id,
                'barangay_id' => $first->barangay_id,
                'address' => $first->address,
                'latitude' => $first->latitude,
                'longitude' => $first->longitude,
                'contact_number' => $first->contact_number,
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('retail_products')
                ->whereIn('id', $members->pluck('id'))
                ->update(['retail_shop_id' => $shopId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('retail_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retail_shop_id');
            $table->dropColumn('admin_notes');
        });
    }
};

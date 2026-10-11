<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * CategorySeeder used a plain insert, so seeding twice stored every category twice
     * (identical copies). Keep the oldest row of each name + type, point anything that
     * used a copy at it, delete the copies, and add a unique index so it can't recur.
     */
    public function up(): void
    {
        DB::transaction(function () {
            $kept = DB::table('categories')
                ->selectRaw('MIN(id) as id, name, type')
                ->groupBy('name', 'type')
                ->get();

            foreach ($kept as $category) {
                $copies = DB::table('categories')
                    ->where('name', $category->name)
                    ->where('type', $category->type)
                    ->where('id', '!=', $category->id)
                    ->pluck('id');

                if ($copies->isEmpty()) {
                    continue;
                }

                DB::table('destinations')->whereIn('category_id', $copies)->update(['category_id' => $category->id]);
                DB::table('categories')->whereIn('id', $copies)->delete();
            }
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->unique(['name', 'type']);
        });
    }

    /**
     * Reverse the migrations. The deleted rows were exact copies, so only the index is undone.
     */
    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['name', 'type']);
        });
    }
};

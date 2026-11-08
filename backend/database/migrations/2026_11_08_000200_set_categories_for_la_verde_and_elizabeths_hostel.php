<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Categories chosen by the Tourism Office on 2026-11-08 for listings whose own information
 * didn't say what kind of place they are. Changed only while each is still unclassified.
 */
return new class extends Migration
{
    private const CHOSEN = ['La Verde' => 'Resort', "Elizabeth's hostel" => 'Hotel'];

    public function up(): void
    {
        foreach (self::CHOSEN as $name => $category) {
            DB::table('accommodations')
                ->where('name', $name)
                ->where('type', 'Accommodation')
                ->where(fn ($q) => $q->whereNull('classifications')->orWhereIn('classifications', ['', '[]']))
                ->update(['type' => $category, 'classifications' => json_encode([$category])]);
        }
    }

    public function down(): void
    {
        foreach (self::CHOSEN as $name => $category) {
            DB::table('accommodations')
                ->where('name', $name)
                ->where('type', $category)
                ->where('classifications', json_encode([$category]))
                ->update(['type' => 'Accommodation', 'classifications' => null]);
        }
    }
};

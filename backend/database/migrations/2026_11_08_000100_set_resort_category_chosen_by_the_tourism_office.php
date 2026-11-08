<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Listings whose own information didn't say what kind of place they are, classified by the
 * Tourism Office on 2026-11-08. Changed only while each is still unclassified.
 */
return new class extends Migration
{
    private const RESORTS = ["Kendi's Beach Garden", 'Birbeck Lodge', 'Star Beach Casitas'];

    public function up(): void
    {
        DB::table('accommodations')
            ->whereIn('name', self::RESORTS)
            ->where('type', 'Accommodation')
            ->where(fn ($q) => $q->whereNull('classifications')->orWhereIn('classifications', ['', '[]']))
            ->update(['type' => 'Resort', 'classifications' => json_encode(['Resort'])]);
    }

    public function down(): void
    {
        DB::table('accommodations')
            ->whereIn('name', self::RESORTS)
            ->where('type', 'Resort')
            ->where('classifications', json_encode(['Resort']))
            ->update(['type' => 'Accommodation', 'classifications' => null]);
    }
};

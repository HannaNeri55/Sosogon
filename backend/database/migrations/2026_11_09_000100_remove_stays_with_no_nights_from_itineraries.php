<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The planner used to save a stay for every town of a trip, even a town where no night is spent,
 * and those showed on the itinerary as a stay with "No night planned here". This removes those
 * rows from saved itineraries. Only the trip's link to the stay goes: the accommodation listing,
 * the day's stops and the costs are untouched (a stay with no nights never had a cost or rooms).
 */
return new class extends Migration
{
    public function up(): void
    {
        $noNights = DB::table('itinerary_stays')->get(['id', 'night_days'])
            ->filter(fn ($stay) => (json_decode((string) $stay->night_days, true) ?: []) === [])
            ->pluck('id');

        DB::table('itinerary_stays')->whereIn('id', $noNights)->delete();
    }

    public function down(): void
    {
        // Nothing to restore: these rows were never a stay of their trip.
    }
};

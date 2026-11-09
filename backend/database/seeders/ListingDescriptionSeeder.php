<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Rewritten listing descriptions (database/seeders/data/listing_descriptions.php) for
 * destinations, accommodations, food places and shops. Only the description column changes,
 * and only on a row whose id AND name still match the data file, so nothing else (coordinates,
 * prices, statuses, timestamps) is touched and a renamed or missing listing is skipped. Safe to
 * run again: an unchanged description is left alone.
 */
class ListingDescriptionSeeder extends Seeder
{
    public function run(): void
    {
        $data = require database_path('seeders/data/listing_descriptions.php');
        $report = [];

        foreach ($data as $table => $rows) {
            $report[$table] = ['updated' => 0, 'unchanged' => 0, 'skipped' => []];
            foreach ($rows as $id => [$name, $description]) {
                $current = DB::table($table)->where('id', $id)->first(['name', 'description']);
                if (! $current || $current->name !== $name) {
                    $report[$table]['skipped'][] = $id;

                    continue;
                }
                if ($current->description === $description) {
                    $report[$table]['unchanged']++;

                    continue;
                }
                DB::table($table)->where('id', $id)->update(['description' => $description]);
                $report[$table]['updated']++;
            }
        }

        $this->command?->info('Listing descriptions: '.json_encode($report));
    }
}

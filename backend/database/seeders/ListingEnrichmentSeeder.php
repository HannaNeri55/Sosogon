<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Adds the structured details in data/listing_enrichment.php to existing approved and
 * published listings: on-site dining, meals and visitor guidelines for destinations (plus
 * activities and amenities), dining and room types for stays, meals served and specialties for
 * food places, and product categories for shops.
 *
 * Safe to run again: a listing is only written when its ID and name both match and it is
 * approved/published; child rows are keyed by parent and name, so re-runs update instead of
 * duplicating. Coordinates, statuses, owners, images, rooms and itineraries are never touched.
 */
class ListingEnrichmentSeeder extends Seeder
{
    public function run(): void
    {
        $data = require database_path('seeders/data/listing_enrichment.php');
        $now = now();
        $skipped = [];
        $counts = ['destinations' => 0, 'activities' => 0, 'amenities' => 0, 'accommodations' => 0, 'room_types' => 0, 'food' => 0, 'shops' => 0];

        DB::transaction(function () use ($data, $now, &$skipped, &$counts) {
            // Both passes in order (see 'destinations_more' in the data file).
            $destinationRows = [];
            foreach (['destinations', 'destinations_more'] as $pass) {
                foreach ($data[$pass] ?? [] as $id => $row) {
                    $destinationRows[] = [$id, $row];
                }
            }
            $enriched = [];
            foreach ($destinationRows as [$id, $row]) {
                if (! $this->matches('destinations', $id, $row['name'], 'published')) {
                    $skipped[] = "destination #{$id} {$row['name']}";

                    continue;
                }
                $fields = Arr::only($row, ['dining_details', 'meals_offered', 'visitor_guidelines']);
                if (isset($fields['meals_offered'])) {
                    $fields['meals_offered'] = json_encode(array_values($fields['meals_offered']));
                }
                if ($fields) {
                    DB::table('destinations')->where('id', $id)->update($fields + ['updated_at' => $now]);
                }
                foreach ($row['activities'] ?? [] as $activity) {
                    $values = Arr::only($activity, ['description', 'type', 'best_time_to_visit', 'duration_minutes']);
                    $counts['activities'] += $this->upsert('activities', ['destination_id' => $id, 'name' => $activity['name']], $values, $now);
                }
                foreach ($row['amenities'] ?? [] as $amenity) {
                    $counts['amenities'] += $this->upsert('destination_amenities', ['destination_id' => $id, 'name' => $amenity], [], $now);
                }
                $enriched[$id] = true;
                $counts['destinations'] = count($enriched);
            }

            foreach ($data['accommodations'] as $id => $row) {
                if (! $this->matches('accommodations', $id, $row['name'], 'approved')) {
                    $skipped[] = "accommodation #{$id} {$row['name']}";

                    continue;
                }
                if (isset($row['dining_details'])) {
                    DB::table('accommodations')->where('id', $id)->update(['dining_details' => $row['dining_details'], 'updated_at' => $now]);
                }
                foreach (array_values($row['room_types'] ?? []) as $sort => $type) {
                    $values = Arr::only($type, ['description', 'bed_configuration', 'max_guests', 'price_min', 'price_max', 'price_note', 'breakfast_included', 'source_url']);
                    $values['amenities'] = isset($type['amenities']) ? json_encode(array_values($type['amenities'])) : null;
                    $values['sort_order'] = $sort;
                    // A type the stay lists bookable rooms for: its price range from those rooms.
                    if (! empty($type['from_rooms'])) {
                        $prices = DB::table('accommodation_rooms')->where('accommodation_id', $id)->where('category', $type['from_rooms'])
                            ->where('status', 'approved')->where('price', '>', 0)->pluck('price')->map(fn ($p) => (float) $p);
                        if ($prices->isNotEmpty()) {
                            $values += ['price_min' => $prices->min(), 'price_max' => $prices->max(), 'price_note' => 'Per night, from the room prices this stay lists on SOSOGON'];
                        }
                    }
                    $counts['room_types'] += $this->upsert('accommodation_room_types', ['accommodation_id' => $id, 'name' => $type['name']], $values, $now);
                }
                $counts['accommodations']++;
            }

            foreach ($data['food'] as $id => $row) {
                if (! $this->matches('food_beverages', $id, $row['name'], 'approved')) {
                    $skipped[] = "food place #{$id} {$row['name']}";

                    continue;
                }
                $fields = ['meals_served' => json_encode(array_values($row['meals_served'])), 'specialties' => $row['specialties'], 'updated_at' => $now];
                $hours = trim((string) DB::table('food_beverages')->where('id', $id)->value('operating_hours'));
                // Sourced hours only where the listing has none, or only a vague "Daytime".
                if (! empty($row['operating_hours_if_missing']) && $hours === '') {
                    $fields['operating_hours'] = $row['operating_hours_if_missing'];
                }
                if (! empty($row['operating_hours_if_vague']) && in_array(mb_strtolower($hours), ['', 'daytime'], true)) {
                    $fields['operating_hours'] = $row['operating_hours_if_vague'];
                }
                DB::table('food_beverages')->where('id', $id)->update($fields);
                $counts['food']++;
            }

            foreach ($data['shops'] as $id => $row) {
                if (! $this->matches('retail_shops', $id, $row['name'], 'approved')) {
                    $skipped[] = "shop #{$id} {$row['name']}";

                    continue;
                }
                DB::table('retail_shops')->where('id', $id)->update(['product_categories' => json_encode(array_values($row['product_categories'])), 'updated_at' => $now]);
                $counts['shops']++;
            }
        });

        $this->command?->info('Listing enrichment: '.collect($counts)->map(fn ($n, $k) => "{$n} {$k}")->implode(', '));
        foreach ($skipped as $line) {
            $this->command?->warn("Skipped (ID/name/status mismatch): {$line}");
        }
    }

    /** The listing exists with this exact name and status. */
    private function matches(string $table, int $id, string $name, string $status): bool
    {
        return DB::table($table)->where('id', $id)->where('name', $name)->where('status', $status)->exists();
    }

    /** Inserts or updates the row matching $keys; returns 1 when a row was created. */
    private function upsert(string $table, array $keys, array $values, $now): int
    {
        $existing = DB::table($table)->where($keys)->first();
        if ($existing) {
            if ($values) {
                DB::table($table)->where('id', $existing->id)->update($values + ['updated_at' => $now]);
            }

            return 0;
        }
        DB::table($table)->insert($keys + $values + ['created_at' => $now, 'updated_at' => $now]);

        return 1;
    }
}

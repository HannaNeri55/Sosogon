<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The 18 draft experience tags from docs/data-quality/classification-design-2026-11-15.md.
 * Adds only the tags whose slug isn't there yet, so running it again (or after an interrupted
 * deployment) never duplicates a tag, never changes a tag already stored (its wording may have
 * been corrected since) and never touches which destinations use them.
 */
class ExperienceTagSeeder extends Seeder
{
    public const TAGS = [
        ['beach', 'Beach', 'Water & coast', 'A shoreline where visitors swim, walk on the sand or watch the sea.'],
        ['island', 'Island or sandbar', 'Water & coast', 'An island, islet or sandbar reached by boat, including island hopping.'],
        ['waterfall', 'Waterfall', 'Water & coast', 'A waterfall visitors go to see, and often swim at.'],
        ['spring_river', 'Spring or river', 'Water & coast', 'A natural cold or hot spring, or a river, kept mostly natural.'],
        ['pool', 'Swimming pool', 'Water & coast', 'Built swimming pools open to visitors.'],
        ['water_sports', 'Water sports', 'Water & coast', 'Kayaking, paddleboarding, surfing, snorkeling, diving or other water activities.'],
        ['viewpoint', 'Viewpoint', 'Land & nature', 'A hill, highland or lookout visited mainly for the view.'],
        ['hiking', 'Hiking or trekking', 'Land & nature', 'A trail, climb or trek on foot.'],
        ['forest_mangrove', 'Forest or mangrove', 'Land & nature', 'A forest, mangrove area or eco park with natural vegetation.'],
        ['farm', 'Farm visit', 'Land & nature', 'A working farm or garden open for tours or farm activities.'],
        ['wildlife', 'Wildlife watching', 'Land & nature', 'Seeing animals in the wild: whale sharks, fireflies, birds or marine life in a sanctuary.'],
        ['scenic_route', 'Scenic route', 'Land & nature', 'A road or route enjoyed for the drive or ride itself, with stops along the way.'],
        ['heritage', 'Heritage site', 'Culture', 'Ruins, old houses, monuments, lighthouses or places with a historical marker.'],
        ['museum', 'Museum', 'Culture', 'A museum or gallery with exhibits.'],
        ['religious', 'Religious site', 'Culture', 'A church, shrine or pilgrimage site visited for worship or devotion.'],
        ['resort', 'Resort (day use)', 'Leisure', 'A privately run resort that day visitors can enter, with cottages, pools or a beach.'],
        ['recreation', 'Recreation area', 'Leisure', 'A public park, promenade, plaza, playground or sports facility.'],
        ['camping', 'Camping', 'Leisure', 'Tent pitching or overnight camping on site.'],
    ];

    public function run(): void
    {
        $existing = DB::table('experience_tags')->pluck('slug')->all();
        $now = now();
        $rows = [];
        foreach (self::TAGS as $index => [$slug, $name, $group, $description]) {
            if (! in_array($slug, $existing, true)) {
                $rows[] = [
                    'slug' => $slug,
                    'name' => $name,
                    'group' => $group,
                    'description' => $description,
                    'sort_order' => $index + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // One statement: all the missing tags are added, or none (a retry adds them).
        if ($rows !== []) {
            DB::table('experience_tags')->insert($rows);
        }
    }
}

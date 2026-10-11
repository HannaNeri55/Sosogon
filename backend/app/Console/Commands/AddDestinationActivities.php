<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AddDestinationActivities extends Command
{
    protected $signature = 'activities:add-missing {--dry-run : Report what would be added without writing}';

    protected $description = 'Add 3 category-based activities to every destination that has none';

    public function handle(): int
    {
        $destinations = DB::table('destinations')
            ->join('categories', 'categories.id', '=', 'destinations.category_id')
            ->whereNotIn('destinations.id', DB::table('activities')->select('destination_id'))
            ->orderBy('destinations.id')
            ->get(['destinations.id', 'destinations.name', 'destinations.description', 'categories.name as category']);

        $dry = (bool) $this->option('dry-run');
        $count = 0;

        DB::beginTransaction();
        try {
            foreach ($destinations as $d) {
                $set = $this->templatesFor($d->category, Str::lower($d->name.' '.$d->description));
                foreach ($set as $t) {
                    $id = DB::table('activities')->insertGetId([
                        'destination_id' => $d->id,
                        'name' => strtr($t['name'], ['{n}' => $d->name]),
                        'description' => strtr($t['description'], ['{n}' => $d->name]),
                        'type' => $t['type'],
                        'best_time_to_visit' => $t['best'],
                        'what_to_wear' => $t['wear'],
                        'things_to_do' => strtr($t['todo'], ['{n}' => $d->name]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    if (! $dry) {
                        $img = $this->placeholderImage("activities/{$id}", $d->category);
                        DB::table('activities')->where('id', $id)->update($img);
                    }
                    $count++;
                }
                $this->line("{$d->name} ({$d->category}): +".count($set));
            }
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed, rolled back: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(($dry ? '[dry-run] ' : '')."{$destinations->count()} destinations, {$count} activities added");

        return self::SUCCESS;
    }

    private function a(string $name, string $desc, string $type, string $best, string $wear, array $todo): array
    {
        return ['name' => $name, 'description' => $desc, 'type' => $type, 'best' => $best, 'wear' => $wear, 'todo' => implode("\n", $todo)];
    }

    /** Generic, category-based drafts; worth an admin read-through, not sourced facts about each place. */
    private function templatesFor(string $category, string $text): array
    {
        $has = fn (string ...$words) => (bool) preg_match('/'.implode('|', $words).'/', $text);

        switch ($category) {
            case 'Beach':
                return [
                    $this->a('Swimming and Beach Lounging at {n}', 'Relax on the shoreline and take a dip in the sea at {n}.', 'outdoor', 'Morning or late afternoon, when the sun is gentler', 'Swimwear, sunscreen and a hat', ['Swim in the shallows', 'lounge on the sand', 'build sandcastles with the kids']),
                    $this->a('Sunset Watching at {n}', 'Catch the sky change colour over the water from the shore of {n}.', 'outdoor', 'Late afternoon, 4:30 PM to 6:00 PM', 'Light, breathable clothing', ['Watch the sunset', 'take photos of the horizon', 'stroll along the shoreline']),
                    $this->a('Beachside Picnic at {n}', 'Bring food and enjoy an easy day out with family and friends at {n}.', 'outdoor', 'Weekday mornings for a quieter beach', 'Casual clothing and sandals', ['Set up a picnic', 'try local snacks', 'play beach games']),
                ];
            case 'Mountain':
                return [
                    $this->a('Scenic Trek at {n}', 'Walk the trail up to the viewpoints of {n} and take in the highland scenery.', 'outdoor', 'Early morning for cool weather and clear views', 'Trekking shoes, light layers and a cap', ['Hike the trail', 'rest at viewpoints', 'photograph the landscape']),
                    $this->a('Camping and Stargazing at {n}', 'Spend the night under open skies at {n}, weather permitting.', 'outdoor', 'Dry season, arriving before sunset', 'Warm layers and closed shoes', ['Pitch a tent', 'cook a simple meal', 'stargaze after dark']),
                    $this->a('Sunrise Viewing at {n}', 'Wake early to watch the sun rise over the hills around {n}.', 'outdoor', 'Around dawn, 5:00 AM to 6:30 AM', 'Jacket and comfortable shoes', ['Watch the sunrise', 'enjoy the sea of clouds if present', 'take panoramic photos']),
                ];
            case 'Waterfall':
                return [
                    $this->a('Waterfall Sightseeing at {n}', 'Follow the path to the falls at {n} and enjoy the sound and spray of falling water.', 'outdoor', 'Morning on dry days, when trails are safer', 'Quick-dry clothes and water shoes', ['Walk to the falls', 'take photos', 'feel the mist']),
                    $this->a('Cool Dip near {n}', 'Cool off in the pool at the base of the falls at {n} where swimming is allowed.', 'outdoor', 'Late morning to early afternoon', 'Swimwear and slip-resistant footwear', ['Swim in the pool', 'wade near the shallows', 'follow local safety guidance']),
                    $this->a('Nature Picnic at {n}', 'Enjoy a packed lunch surrounded by greenery and running water at {n}.', 'outdoor', 'Weekday mornings', 'Casual clothing and a light jacket', ['Set up a picnic', 'listen to the water', 'take home your trash']),
                ];
            case 'Historical':
                return [
                    $this->a('Heritage Walk at {n}', 'Walk around {n} and learn about the history that shaped this part of Sorsogon.', 'outdoor', 'Morning or late afternoon', 'Comfortable walking shoes', ['Read the historical markers', 'walk around the grounds', 'ask locals about the site']),
                    $this->a('Photography at {n}', 'Photograph the architecture and details of {n}.', 'outdoor', 'Golden hour for the best light', 'Casual clothing', ['Shoot old structures', 'capture details and textures', 'take a group photo']),
                    $this->a('History Learning Visit to {n}', 'Bring students or family to learn the story behind {n}.', 'indoor', 'Weekday mornings', 'Modest, comfortable clothing', ['Read the exhibit or markers', 'join a local guide if available', 'discuss the site with your group']),
                ];
            case 'Church':
                return [
                    $this->a('Quiet Visit and Prayer at {n}', 'Spend a peaceful moment inside {n}.', 'indoor', 'Early morning or after mass', 'Modest clothing that covers shoulders and knees', ['Light a candle', 'pray or reflect', 'admire the altar']),
                    $this->a('Architecture Tour of {n}', 'Look at the facade, interior and grounds of {n}.', 'indoor', 'Daytime, outside of mass times', 'Modest, comfortable clothing', ['Study the architecture', 'photograph the exterior', 'read any historical markers']),
                    $this->a('Attend Mass at {n}', 'Join the local community for a service at {n}.', 'indoor', 'Sunday mass; check the parish schedule', 'Modest, neat clothing', ['Attend a service', 'meet local parishioners', 'enjoy the surrounding plaza']),
                ];
            case 'Park':
                return [
                    $this->a('Leisure Stroll at {n}', 'Take an easy walk through the green spaces of {n}.', 'outdoor', 'Late afternoon', 'Casual clothes and walking shoes', ['Stroll the paths', 'sit on the benches', 'people-watch']),
                    $this->a('Family Picnic at {n}', 'Bring the family for a relaxed meal and play at {n}.', 'outdoor', 'Weekend mornings', 'Casual, breathable clothing', ['Set up a picnic', 'play outdoor games', 'let kids run around']),
                    $this->a('Photo Session at {n}', 'Photograph the landscape and scenery of {n}.', 'outdoor', 'Golden hour, near sunset', 'Comfortable clothing', ['Take portraits', 'shoot the scenery', 'capture the sunset']),
                ];
            case 'Island':
                return [
                    $this->a('Island Hopping to {n}', 'Take a boat trip out to {n} and explore its shores.', 'outdoor', 'Morning, when the sea is calm', 'Swimwear, sunscreen and a hat', ['Ride a boat across', 'explore the shoreline', 'take photos of the coast']),
                    $this->a('Snorkeling around {n}', 'Look for fish and coral in the clear waters around {n}, where conditions allow.', 'outdoor', 'Mid-morning, when visibility is best', 'Swimwear and rash guard', ['Snorkel the shallows', 'watch marine life', 'follow local safety guidance']),
                    $this->a('Beach Picnic at {n}', 'Enjoy a packed lunch on the sand at {n}.', 'outdoor', 'Late morning to afternoon', 'Light clothing and sandals', ['Picnic on the beach', 'swim', 'watch the sunset before heading back']),
                ];
            case 'Resort':
                if ($has('spring')) {
                    return [
                        $this->a('Spring Water Swimming at {n}', 'Cool off in the spring-fed pools of {n}.', 'outdoor', 'Late morning to afternoon', 'Swimwear and a change of clothes', ['Swim in the spring pool', 'relax on the deck', 'enjoy the cool water']),
                        $this->a('Family Cottage Day at {n}', 'Rent a cottage and spend the day together at {n}.', 'outdoor', 'Weekend mornings', 'Casual clothing', ['Rent a cottage', 'bring a home-cooked meal', 'play poolside games']),
                        $this->a('Garden Stroll at {n}', 'Walk the resort grounds and enjoy the greenery at {n}.', 'outdoor', 'Early morning or late afternoon', 'Light casual wear', ['Walk the grounds', 'take photos', 'sit and relax']),
                    ];
                }

                return [
                    $this->a('Poolside Relaxation at {n}', 'Unwind in the pool and lounge areas at {n}.', 'outdoor', 'Late morning to afternoon', 'Swimwear and sunscreen', ['Swim in the pool', 'lounge by the water', 'order a cold drink']),
                    $this->a('Family Day Out at {n}', 'Spend a leisurely day with family and friends at {n}.', 'outdoor', 'Weekends', 'Casual clothing', ['Rent a cottage', 'share a meal', 'enjoy the facilities']),
                    $this->a('Sunset Photos at {n}', 'Capture the evening sky from the grounds of {n}.', 'outdoor', 'Late afternoon', 'Comfortable clothing', ['Watch the sunset', 'take photos', 'stroll the grounds']),
                ];
            case 'Eco-Tourism':
            default:
                if ($has('cave')) {
                    return [
                        $this->a('Cave Exploration at {n}', 'Explore the passages and rock formations of {n} with a local guide.', 'outdoor', 'Dry days, mid-morning', 'Sturdy shoes and clothes you can get dirty', ['Enter with a guide', 'look at the formations', 'bring a flashlight']),
                        $this->a('Nature Photography near {n}', 'Photograph the rocks, light and surrounding forest of {n}.', 'outdoor', 'Late morning, when light enters the cave', 'Comfortable outdoor clothes', ['Shoot rock formations', 'capture the light beams', 'photograph the surrounding forest']),
                        $this->a('Guided Nature Walk at {n}', 'Learn about the plants and wildlife around {n} on a short walk.', 'outdoor', 'Morning', 'Closed shoes and a cap', ['Walk the trail', 'spot birds and plants', 'ask your guide about the area']),
                    ];
                }
                if ($has('spring', 'river', 'lake')) {
                    return [
                        $this->a('Cool Dip at {n}', 'Cool off in the clear waters of {n}, where swimming is allowed.', 'outdoor', 'Late morning to afternoon', 'Swimwear and water shoes', ['Swim or wade', 'relax on the banks', 'follow local safety guidance']),
                        $this->a('Nature Walk around {n}', 'Stroll around the greenery surrounding {n}.', 'outdoor', 'Morning', 'Light clothing and closed shoes', ['Walk the trail', 'spot birds and plants', 'take photos']),
                        $this->a('Riverside Picnic at {n}', 'Enjoy a packed lunch by the water at {n}.', 'outdoor', 'Weekday mornings', 'Casual clothing', ['Set up a picnic', 'listen to the water', 'take home your trash']),
                    ];
                }

                return [
                    $this->a('Guided Nature Walk at {n}', 'Walk the grounds of {n} and learn about the local environment.', 'outdoor', 'Morning', 'Closed shoes and a cap', ['Walk the trails', 'spot birds and plants', 'ask locals about the area']),
                    $this->a('Nature Photography at {n}', 'Capture the scenery and wildlife of {n}.', 'outdoor', 'Early morning or late afternoon', 'Comfortable outdoor clothing', ['Shoot landscapes', 'photograph plants and insects', 'try golden-hour portraits']),
                    $this->a('Family Picnic at {n}', 'Bring a meal and relax outdoors at {n}.', 'outdoor', 'Weekend mornings', 'Casual clothing', ['Set up a picnic', 'play outdoor games', 'take home your trash']),
                ];
        }
    }

    private function placeholderImage(string $directory, string $label): array
    {
        $path = "{$directory}/placeholder-".Str::slug($label).'.svg';
        Storage::disk('public')->put($path, $this->buildPlaceholderSvg($label));

        return ['image_path' => $path, 'thumbnail_path' => $path];
    }

    private function buildPlaceholderSvg(string $label): string
    {
        $palette = ['#0E6B4F', '#2B7FBF', '#E8A838', '#094A37', '#1B557F'];
        $color = $palette[crc32($label) % count($palette)];
        $safeLabel = htmlspecialchars($label, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 450">
  <rect width="800" height="450" fill="{$color}"/>
  <circle cx="700" cy="60" r="120" fill="#fff" opacity="0.08"/>
  <circle cx="80" cy="400" r="160" fill="#fff" opacity="0.08"/>
  <text x="400" y="235" font-family="Arial, sans-serif" font-size="32" font-weight="700" fill="#fff" text-anchor="middle">{$safeLabel}</text>
  <text x="400" y="275" font-family="Arial, sans-serif" font-size="16" fill="#fff" opacity="0.8" text-anchor="middle">Sorsogon Province</text>
</svg>
SVG;
    }
}

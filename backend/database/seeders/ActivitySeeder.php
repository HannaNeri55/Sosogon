<?php

namespace Database\Seeders;

use App\Services\ImageOptimizationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ActivitySeeder extends Seeder
{
    public function __construct(private ImageOptimizationService $images) {}

    /**
     * Category label per destination, used only to pick a placeholder image
     * when no real photo exists at storage/app/seed-images/activities/.
     */
    private array $destinationCategoryLabels = [
        'Museo Sorsogon' => 'Historical',
        'Buhatan River Eco Adventure' => 'Eco-Tourism',
        'St. Joseph Parish (Barcelona Church)' => 'Church',
        'Barcelona Ruins Park' => 'Historical',
        'World War II Japanese Tunnels' => 'Historical',
        'Ubo Falls' => 'Waterfall',
        'Bulusan Lake' => 'Park',
        'Bayugin Falls' => 'Waterfall',
        'Nagsipit Falls' => 'Waterfall',
        'Namuat Falls' => 'Waterfall',
        'Malawmawan Island' => 'Island',
        'Donsol Whale Shark Interaction Center' => 'Eco-Tourism',
        'Donsol River Firefly Watching' => 'Eco-Tourism',
        'Lola Sayong Eco-Surfing Camp' => 'Beach',
        'Oroc Cold Spring' => 'Eco-Tourism',
        'Irosin Hot Springs (San Mateo)' => 'Eco-Tourism',
        'The Old Houses of Juban' => 'Historical',
        'Parola Beach & Lighthouse Station' => 'Beach',
        'Bucal-Bucalan Spring Resort' => 'Eco-Tourism',
        'Subic Beach' => 'Beach',
        'Juag Lagoon Marine Sanctuary' => 'Eco-Tourism',
        'Panumbagan Marine Sanctuary' => 'Eco-Tourism',
        'Prieto Diaz Mangrove Eco-Park' => 'Eco-Tourism',
        'Balading Beach' => 'Beach',
    ];

    public function run(): void
    {
        // 3 activities per destination in the Sorsogon-wide batch (2026-09-22), drafted
        // to fit each destination's category — worth a read-through/edit in the admin
        // panel, not sourced facts about what each place specifically offers.
        // things_to_do items are newline-separated, matching ManageActivities.jsx's format.
        $activities = [
            'Museo Sorsogon' => [
                ['name' => 'Guided Museum Tour', 'description' => "A docent-led walkthrough of the museum's history and culture exhibits.", 'type' => 'indoor', 'best_time_to_visit' => 'Morning, before it gets crowded', 'what_to_wear' => 'Casual, comfortable clothing', 'things_to_do' => "Browse historical artifacts\njoin a guided tour\ntake photos in the restored jail cells"],
                ['name' => 'Photo Walk of the Restored Jail', 'description' => "Explore and photograph the museum's restored colonial-era jail architecture.", 'type' => 'indoor', 'best_time_to_visit' => 'Any time during opening hours', 'what_to_wear' => 'Comfortable walking shoes', 'things_to_do' => "Photograph the architecture\nread exhibit placards\nvisit the gift corner"],
                ['name' => 'Local History Talk', 'description' => "Sit in on a short talk about Sorsogon's provincial history from museum staff.", 'type' => 'indoor', 'best_time_to_visit' => 'Weekday mornings', 'what_to_wear' => 'Casual attire', 'things_to_do' => "Listen to the talk\nask questions\nbrowse related reading materials"],
            ],
            'Buhatan River Eco Adventure' => [
                ['name' => 'River Cruise', 'description' => 'A relaxed boat ride along the Buhatan River past mangroves and floating cabanas.', 'type' => 'outdoor', 'best_time_to_visit' => 'Late afternoon for cooler weather', 'what_to_wear' => 'Light clothing and a hat', 'things_to_do' => "Ride the floating cabana\ntake in the river scenery\ntry local snacks"],
                ['name' => 'Floating Cabana Lunch', 'description' => 'Dine on local dishes at a floating cabana over the river.', 'type' => 'outdoor', 'best_time_to_visit' => 'Lunchtime', 'what_to_wear' => 'Casual, breathable clothing', 'things_to_do' => "Order a local meal\nrelax on the cabana\nenjoy the river breeze"],
                ['name' => 'Riverside Photography', 'description' => 'Capture the river, mangroves, and cabanas at golden hour.', 'type' => 'outdoor', 'best_time_to_visit' => 'Late afternoon', 'what_to_wear' => 'Comfortable outdoor wear', 'things_to_do' => "Photograph the mangroves\nwatch the sunset over the river\nchat with boat operators"],
            ],
            'St. Joseph Parish (Barcelona Church)' => [
                ['name' => 'Church Visit & Prayer', 'description' => 'Visit the coral-stone church interior for quiet reflection or Mass.', 'type' => 'indoor', 'best_time_to_visit' => 'Mass schedule or early morning', 'what_to_wear' => 'Modest, respectful attire', 'things_to_do' => "Attend Mass if scheduled\nadmire the altar\nlight a candle"],
                ['name' => 'Heritage Architecture Tour', 'description' => "A closer look at the church's 1800s Franciscan coral-rock construction.", 'type' => 'outdoor', 'best_time_to_visit' => 'Morning light for photos', 'what_to_wear' => 'Comfortable walking shoes', 'things_to_do' => "Examine the facade\nphotograph the coral-stone walls\nread historical markers"],
                ['name' => 'Coastal Church Walk', 'description' => 'A short walk around the church grounds facing the Pacific Ocean.', 'type' => 'outdoor', 'best_time_to_visit' => 'Late afternoon', 'what_to_wear' => 'Light, breathable clothing', 'things_to_do' => "Walk the church grounds\nview the ocean\nvisit nearby stalls"],
            ],
            'Barcelona Ruins Park' => [
                ['name' => 'Ruins Exploration', 'description' => 'Walk through the 138-year-old ruins of La Presidencia.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning or late afternoon', 'what_to_wear' => 'Comfortable walking shoes', 'things_to_do' => "Explore the fortress ruins\nread historical markers\ntake photos"],
                ['name' => 'Sunset Photography at the Ruins', 'description' => 'Photograph the ruins silhouetted against the sunset.', 'type' => 'outdoor', 'best_time_to_visit' => 'Late afternoon to sunset', 'what_to_wear' => 'Light clothing', 'things_to_do' => "Photograph the ruins\nwatch the sunset\nexplore the watchtower"],
                ['name' => 'Historical Walking Tour', 'description' => 'A guided or self-led walk through the fortress, watchtower, and old government seat.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning', 'what_to_wear' => 'Comfortable walking shoes and a hat', 'things_to_do' => "Follow the ruins trail\nlearn the site's Spanish-era history\nrest at the park benches"],
            ],
            'World War II Japanese Tunnels' => [
                ['name' => 'Tunnel Exploration', 'description' => 'Walk through the hidden WWII-era tunnels with a local guide.', 'type' => 'indoor', 'best_time_to_visit' => 'Daytime, guide availability permitting', 'what_to_wear' => 'Sturdy shoes, a light jacket', 'things_to_do' => "Enter the tunnels with a guide\nlearn their wartime history\ntake photos"],
                ['name' => 'WWII History Walk', 'description' => "Learn about the tunnels' use as a hideout during the Japanese occupation.", 'type' => 'outdoor', 'best_time_to_visit' => 'Daytime', 'what_to_wear' => 'Comfortable walking shoes', 'things_to_do' => "Listen to local accounts\nexplore the tunnel entrances\nview the surrounding terrain"],
                ['name' => 'Local Guide Storytelling Session', 'description' => "Hear local stories and folklore tied to the tunnels' wartime history.", 'type' => 'outdoor', 'best_time_to_visit' => 'Daytime', 'what_to_wear' => 'Casual attire', 'things_to_do' => "Listen to a local guide's stories\nask questions\nexplore nearby Santa Remedios"],
            ],
            'Ubo Falls' => [
                ['name' => 'Waterfall Swimming', 'description' => "Cool off in the falls' natural pool.", 'type' => 'outdoor', 'best_time_to_visit' => 'Dry season, midday', 'what_to_wear' => 'Swimwear and sandals', 'things_to_do' => "Swim in the pool\nrelax on the rocks\nenjoy a picnic"],
                ['name' => 'Picnic by the Falls', 'description' => 'Enjoy a packed lunch at this secret local picnic ground.', 'type' => 'outdoor', 'best_time_to_visit' => 'Midday', 'what_to_wear' => 'Casual, quick-dry clothing', 'things_to_do' => "Set up a picnic\nswim\ntake in the forest scenery"],
                ['name' => 'Nature Trek to the Falls', 'description' => 'A short trek through forest trails to reach the falls.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning', 'what_to_wear' => 'Trekking shoes and light clothing', 'things_to_do' => "Trek the trail\nspot local flora\nswim on arrival"],
            ],
            'Bulusan Lake' => [
                ['name' => 'Kayaking on the Lake', 'description' => 'Paddle across the emerald-green lake at the heart of the national park.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning for calm water', 'what_to_wear' => 'Swimwear or quick-dry clothing', 'things_to_do' => "Rent a kayak\npaddle the lake\nenjoy the volcano views"],
                ['name' => 'Jungle Trekking', 'description' => 'Trek the forest trails surrounding the lake.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning', 'what_to_wear' => 'Trekking shoes, insect repellent', 'things_to_do' => "Trek the forest trail\nspot birds\nrest at the lake's edge"],
                ['name' => 'Lakeside Picnic', 'description' => 'Relax lakeside with a packed meal and the cool mountain air.', 'type' => 'outdoor', 'best_time_to_visit' => 'Midday', 'what_to_wear' => 'Casual outdoor wear', 'things_to_do' => "Picnic by the lake\ntake photos\nwatch for local wildlife"],
            ],
            'Bayugin Falls' => [
                ['name' => 'Forest Trek to the Falls', 'description' => 'A mossy, 20-minute trail through dense forest down to the falls.', 'type' => 'outdoor', 'best_time_to_visit' => 'Dry season', 'what_to_wear' => 'Trekking shoes with good grip', 'things_to_do' => "Trek the trail\nswim in the cold water\nenjoy the forest shade"],
                ['name' => 'Cold Water Swim', 'description' => "Take a refreshing dip in the falls' cold, clear water.", 'type' => 'outdoor', 'best_time_to_visit' => 'Midday, dry season', 'what_to_wear' => 'Swimwear and sandals', 'things_to_do' => "Swim\nrelax by the river\ntake photos"],
                ['name' => 'Riverside Relaxation', 'description' => 'Unwind along the Bayugin River that feeds the falls.', 'type' => 'outdoor', 'best_time_to_visit' => 'Afternoon', 'what_to_wear' => 'Casual, quick-dry clothing', 'things_to_do' => "Relax by the river\nswim\nenjoy a light snack"],
            ],
            'Nagsipit Falls' => [
                ['name' => 'Waterfall Trekking', 'description' => 'Hike the improved trail leading to Nagsipit Falls.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning, dry season', 'what_to_wear' => 'Trekking shoes', 'things_to_do' => "Trek the trail\ntake in the forest views\nswim on arrival"],
                ['name' => 'Swimming at the Falls', 'description' => "Cool off in the falls' natural pool.", 'type' => 'outdoor', 'best_time_to_visit' => 'Midday', 'what_to_wear' => 'Swimwear and sandals', 'things_to_do' => "Swim\nrelax on the rocks\ntake photos"],
                ['name' => 'Nature Photography', 'description' => 'Photograph the falls and surrounding lush forest.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning light', 'what_to_wear' => 'Comfortable outdoor wear', 'things_to_do' => "Photograph the falls\nexplore the trail\nrest at the viewing area"],
            ],
            'Namuat Falls' => [
                ['name' => 'Flower Trail Walk', 'description' => 'Walk the scenic trail fringed by multi-colored flowers leading to the falls.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning', 'what_to_wear' => 'Comfortable walking shoes', 'things_to_do' => "Walk the flower-lined trail\nphotograph the blooms\nreach the falls"],
                ['name' => 'Waterfall Swim', 'description' => "Take a dip in Namuat Falls' clear pool.", 'type' => 'outdoor', 'best_time_to_visit' => 'Midday, dry season', 'what_to_wear' => 'Swimwear and sandals', 'things_to_do' => "Swim\nrelax by the falls\nenjoy the scenery"],
                ['name' => 'Nature Photography', 'description' => 'Capture the falls and its colorful floral surroundings.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning light', 'what_to_wear' => 'Casual outdoor wear', 'things_to_do' => "Photograph the falls and flowers\nexplore nearby trails"],
            ],
            'Malawmawan Island' => [
                ['name' => 'Island Hopping & Snorkeling', 'description' => "Explore the tadpole-shaped island's colorful coral formations.", 'type' => 'outdoor', 'best_time_to_visit' => 'Morning, calm seas', 'what_to_wear' => 'Swimwear, reef-safe sunscreen', 'things_to_do' => "Snorkel the reefs\nwalk the sand bar\nrelax on the beach"],
                ['name' => 'Sand Bar Walk', 'description' => "Stroll the island's long stretch of sand bar at low tide.", 'type' => 'outdoor', 'best_time_to_visit' => 'Low tide, check local schedule', 'what_to_wear' => 'Light clothing, sandals', 'things_to_do' => "Walk the sand bar\ntake photos\nswim in shallow water"],
                ['name' => 'Boat Trip from Macalaya', 'description' => 'Take the outrigger boat ride from Castilla Sea Port to the island.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning departure', 'what_to_wear' => 'Casual, sun protection', 'things_to_do' => "Enjoy the boat ride\nspot marine life\narrive to explore the island"],
            ],
            'Donsol Whale Shark Interaction Center' => [
                ['name' => 'Whale Shark Snorkeling', 'description' => 'Snorkel alongside gentle whale sharks under guide supervision.', 'type' => 'outdoor', 'best_time_to_visit' => 'April to June, peak season', 'what_to_wear' => 'Swimwear, rash guard, reef-safe sunscreen', 'things_to_do' => "Join a Butanding Interaction Officer-led swim\nsnorkel with whale sharks\nfollow the code of conduct"],
                ['name' => 'Boat Briefing & Spotting', 'description' => 'Join the boat crew in spotting whale sharks before entering the water.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning, 7:00 AM - 2:00 PM', 'what_to_wear' => 'Sun protection, light clothing', 'things_to_do' => "Attend the pre-departure briefing\nspot whale sharks from the boat\nprepare gear"],
                ['name' => 'Marine Photography', 'description' => 'Photograph whale sharks and the coastal scenery with an underwater camera.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning', 'what_to_wear' => 'Swimwear, waterproof gear', 'things_to_do' => "Photograph whale sharks respectfully\ncapture the boat ride\nreview footage with the crew"],
            ],
            'Donsol River Firefly Watching' => [
                ['name' => 'Evening Firefly Cruise', 'description' => 'A quiet boat cruise along the river to watch fireflies light up the mangroves.', 'type' => 'outdoor', 'best_time_to_visit' => 'Just after sunset', 'what_to_wear' => 'Light jacket, insect repellent', 'things_to_do' => "Board the boat quietly\nwatch the fireflies\nlisten to the guide's commentary"],
                ['name' => 'Mangrove Night Tour', 'description' => 'Learn about the mangrove ecosystem that hosts the fireflies.', 'type' => 'outdoor', 'best_time_to_visit' => 'Early evening', 'what_to_wear' => 'Long sleeves, insect repellent', 'things_to_do' => "Learn about mangroves\nspot fireflies\nenjoy the quiet river"],
                ['name' => 'Stargazing on the River', 'description' => 'Combine firefly watching with stargazing on a clear night.', 'type' => 'outdoor', 'best_time_to_visit' => 'Clear, moonless evenings', 'what_to_wear' => 'Light jacket', 'things_to_do' => "Watch fireflies and stars\nstay quiet to avoid disturbing wildlife\nenjoy the boat ride"],
            ],
            'Lola Sayong Eco-Surfing Camp' => [
                ['name' => 'Surfing Lessons', 'description' => 'Learn to surf the waves at Buenavista beach with local instructors.', 'type' => 'outdoor', 'best_time_to_visit' => 'Surf season, check local conditions', 'what_to_wear' => 'Swimwear or rash guard', 'things_to_do' => "Take a surf lesson\npractice paddling\ncatch a wave"],
                ['name' => 'Beach Camping', 'description' => "Stay overnight at the eco-surf camp's beachside accommodations.", 'type' => 'outdoor', 'best_time_to_visit' => 'Evening check-in', 'what_to_wear' => 'Casual beachwear, light jacket for night', 'things_to_do' => "Set up camp\nrelax by the beach\njoin a bonfire if available"],
                ['name' => 'Beach Relaxation', 'description' => 'Unwind on the beach between surf sessions.', 'type' => 'outdoor', 'best_time_to_visit' => 'Any time', 'what_to_wear' => 'Swimwear, sun protection', 'things_to_do' => "Relax on the beach\nswim\nenjoy the chill camp atmosphere"],
            ],
            'Oroc Cold Spring' => [
                ['name' => 'Forest Trek to the Spring', 'description' => 'A 40-minute trek through forest trails to reach the cold spring.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning', 'what_to_wear' => 'Trekking shoes, light clothing', 'things_to_do' => "Trek the trail\nspot local flora\narrive at the spring"],
                ['name' => 'Cold Spring Dip', 'description' => 'Take a refreshing dip in the cold spring waters.', 'type' => 'outdoor', 'best_time_to_visit' => 'Midday', 'what_to_wear' => 'Swimwear and sandals', 'things_to_do' => "Swim in the cold spring\nrelax\nenjoy a packed lunch"],
                ['name' => 'Hidden Waterfall Visit', 'description' => 'Continue past the spring to see the accompanying hidden waterfalls.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning to early afternoon', 'what_to_wear' => 'Trekking shoes', 'things_to_do' => "Trek to the falls\nswim\nphotograph the scenery"],
            ],
            'Irosin Hot Springs (San Mateo)' => [
                ['name' => 'Hot Spring Soak', 'description' => 'Relax in the geothermal hot spring pools nestled in the valley.', 'type' => 'outdoor', 'best_time_to_visit' => 'Any time, cooler hours preferred', 'what_to_wear' => 'Swimwear, a towel', 'things_to_do' => "Soak in the hot spring\nrelax\nenjoy the valley views"],
                ['name' => 'Hot and Cold Spring Circuit', 'description' => 'Alternate between the hot and adjoining cold spring pools.', 'type' => 'outdoor', 'best_time_to_visit' => 'Midday', 'what_to_wear' => 'Swimwear and sandals', 'things_to_do' => "Soak in both pools\nrelax\nenjoy a light meal nearby"],
                ['name' => 'Valley Nature Walk', 'description' => "A short walk around the spring's valley setting.", 'type' => 'outdoor', 'best_time_to_visit' => 'Morning', 'what_to_wear' => 'Comfortable walking shoes', 'things_to_do' => "Walk the valley trail\ntake photos\nrelax by the springs"],
            ],
            'The Old Houses of Juban' => [
                ['name' => 'Ancestral House Walk', 'description' => 'Stroll along the highway to view the preserved Spanish-era houses.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning', 'what_to_wear' => 'Comfortable walking shoes', 'things_to_do' => "Walk the highway strip\nphotograph the houses\nread historical notes"],
                ['name' => 'Heritage Photography', 'description' => "Photograph the architecture of Juban's ancestral houses.", 'type' => 'outdoor', 'best_time_to_visit' => 'Morning light', 'what_to_wear' => 'Casual outdoor wear', 'things_to_do' => "Photograph the facades\nnote architectural details\nvisit nearby shops"],
                ['name' => 'Local History Chat', 'description' => 'Talk with residents about the families who once lived in these houses.', 'type' => 'outdoor', 'best_time_to_visit' => 'Daytime', 'what_to_wear' => 'Casual attire', 'things_to_do' => "Chat with locals\nlearn family histories\ntour the houses' exteriors"],
            ],
            'Parola Beach & Lighthouse Station' => [
                ['name' => 'Lighthouse Visit', 'description' => 'View the century-old iron lighthouse built in 1903.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning boat departure', 'what_to_wear' => 'Comfortable walking shoes, sun protection', 'things_to_do' => "View the lighthouse\nlearn its history\nphotograph the coastline"],
                ['name' => 'Beach Relaxation on Bagatao Island', 'description' => 'Relax on the beach beside the historic lighthouse station.', 'type' => 'outdoor', 'best_time_to_visit' => 'Daytime', 'what_to_wear' => 'Swimwear, sun protection', 'things_to_do' => "Swim\nrelax on the beach\nexplore the island"],
                ['name' => 'Boat Trip to Bagatao Island', 'description' => 'Take the boat ride out to the island to reach the lighthouse.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning', 'what_to_wear' => 'Casual, sun protection', 'things_to_do' => "Enjoy the boat ride\nspot coastal scenery\narrive at the lighthouse"],
            ],
            'Bucal-Bucalan Spring Resort' => [
                ['name' => 'Spring Pool Swimming', 'description' => "Swim in the resort's large natural spring pools.", 'type' => 'outdoor', 'best_time_to_visit' => 'Midday', 'what_to_wear' => 'Swimwear and sandals', 'things_to_do' => "Swim in the pools\nrelax poolside\nenjoy a meal"],
                ['name' => 'Family Picnic', 'description' => 'A relaxing family outing by the spring pools.', 'type' => 'outdoor', 'best_time_to_visit' => 'Midday to afternoon', 'what_to_wear' => 'Casual clothing', 'things_to_do' => "Set up a picnic\nswim\nrelax with family"],
                ['name' => 'Local Photography', 'description' => 'Photograph the spring pools and surrounding greenery.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning to midday', 'what_to_wear' => 'Casual outdoor wear', 'things_to_do' => "Photograph the pools\nexplore the grounds\nrelax"],
            ],
            'Subic Beach' => [
                ['name' => 'Pink Sand Beach Walk', 'description' => "Walk along the beach's rare pinkish-white sand.", 'type' => 'outdoor', 'best_time_to_visit' => 'Morning or late afternoon', 'what_to_wear' => 'Swimwear, sun protection', 'things_to_do' => "Walk the shoreline\nphotograph the sand\nswim in clear waters"],
                ['name' => 'Swimming & Snorkeling', 'description' => "Swim and snorkel in the beach's crystal clear waters.", 'type' => 'outdoor', 'best_time_to_visit' => 'Midday, calm seas', 'what_to_wear' => 'Swimwear, snorkel gear', 'things_to_do' => "Swim\nsnorkel\nrelax on the beach"],
                ['name' => 'Island Photography', 'description' => "Capture the beach's famous pink sand and clear water.", 'type' => 'outdoor', 'best_time_to_visit' => 'Golden hour', 'what_to_wear' => 'Casual beachwear', 'things_to_do' => "Photograph the beach\nrelax\nenjoy the scenery"],
            ],
            'Juag Lagoon Marine Sanctuary' => [
                ['name' => 'Fish Feeding', 'description' => 'Feed and observe various species of fish in the sanctuary.', 'type' => 'outdoor', 'best_time_to_visit' => 'Midday', 'what_to_wear' => 'Swimwear, reef-safe sunscreen', 'things_to_do' => "Feed the fish\nswim in the lagoon\nobserve marine life"],
                ['name' => 'Lagoon Swimming', 'description' => "Swim in the sanctuary's protected lagoon waters.", 'type' => 'outdoor', 'best_time_to_visit' => 'Midday', 'what_to_wear' => 'Swimwear and sandals', 'things_to_do' => "Swim\nrelax on the floating decks\nobserve fish"],
                ['name' => 'Marine Photography', 'description' => "Photograph the lagoon's fish and clear waters.", 'type' => 'outdoor', 'best_time_to_visit' => 'Midday, clear water', 'what_to_wear' => 'Casual, sun protection', 'things_to_do' => "Photograph marine life\nswim\nrelax at the sanctuary"],
            ],
            'Panumbagan Marine Sanctuary' => [
                ['name' => 'Diving & Boating', 'description' => "Explore the sanctuary's waters ideal for diving and boating.", 'type' => 'outdoor', 'best_time_to_visit' => 'Calm sea conditions', 'what_to_wear' => 'Swimwear, dive gear if diving', 'things_to_do' => "Dive or boat around the sanctuary\nobserve marine life\ntake photos"],
                ['name' => 'Bird Watching', 'description' => 'Spot coastal birds around this emerging eco-tourism site.', 'type' => 'outdoor', 'best_time_to_visit' => 'Early morning', 'what_to_wear' => 'Casual outdoor wear, binoculars', 'things_to_do' => "Watch for coastal birds\nexplore the shoreline\nrelax"],
                ['name' => 'Boat Trip from Pilar Port', 'description' => 'Take the scenic boat ride from Pilar Port to the sanctuary.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning departure', 'what_to_wear' => 'Casual, sun protection', 'things_to_do' => "Enjoy the boat ride\nspot marine life\narrive at the sanctuary"],
            ],
            'Prieto Diaz Mangrove Eco-Park' => [
                ['name' => 'Mangrove Boardwalk Tour', 'description' => 'Walk the boardwalk through the expansive mangrove forest.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning or late afternoon', 'what_to_wear' => 'Comfortable walking shoes, insect repellent', 'things_to_do' => "Walk the boardwalk\nlearn about mangrove conservation\nspot wildlife"],
                ['name' => 'Cave Exploration', 'description' => 'Explore the caves within the coastal environmental project site.', 'type' => 'indoor', 'best_time_to_visit' => 'Daytime', 'what_to_wear' => 'Sturdy shoes, a flashlight', 'things_to_do' => "Explore the caves with a guide\nlearn the site's ecology\ntake photos"],
                ['name' => 'Coastal Nature Walk', 'description' => "A relaxed walk through the mangrove eco-park's coastal trails.", 'type' => 'outdoor', 'best_time_to_visit' => 'Morning', 'what_to_wear' => 'Casual outdoor wear', 'things_to_do' => "Walk the trails\nobserve mangrove wildlife\nrelax at the viewing deck"],
            ],
            'Balading Beach' => [
                ['name' => 'Quiet Beach Walk', 'description' => 'Stroll the picturesque, powdery sand of this undiscovered beach.', 'type' => 'outdoor', 'best_time_to_visit' => 'Morning or late afternoon', 'what_to_wear' => 'Swimwear, sun protection', 'things_to_do' => "Walk the shoreline\nswim\nenjoy the serenity"],
                ['name' => 'Swimming', 'description' => "Take a peaceful swim in Balading's calm waters.", 'type' => 'outdoor', 'best_time_to_visit' => 'Midday', 'what_to_wear' => 'Swimwear and sandals', 'things_to_do' => "Swim\nrelax on the sand\ntake photos"],
                ['name' => 'Sunset Photography', 'description' => 'Photograph the beach at sunset for a serene, uncrowded shot.', 'type' => 'outdoor', 'best_time_to_visit' => 'Sunset', 'what_to_wear' => 'Casual beachwear', 'things_to_do' => "Photograph the sunset\nrelax\nenjoy the quiet beach"],
            ],
        ];

        foreach ($activities as $destinationName => $items) {
            $destination = DB::table('destinations')->where('name', $destinationName)->first();
            if (! $destination) {
                continue;
            }

            foreach ($items as $item) {
                DB::table('activities')->updateOrInsert(
                    ['destination_id' => $destination->id, 'name' => $item['name']],
                    [
                        'destination_id' => $destination->id,
                        'name' => $item['name'],
                        'description' => $item['description'],
                        'type' => $item['type'],
                        'best_time_to_visit' => $item['best_time_to_visit'] ?? null,
                        'what_to_wear' => $item['what_to_wear'] ?? null,
                        'things_to_do' => $item['things_to_do'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
                $activityId = DB::table('activities')
                    ->where('destination_id', $destination->id)
                    ->where('name', $item['name'])
                    ->value('id');

                if (DB::table('activities')->where('id', $activityId)->value('image_path')) {
                    continue;
                }

                $sourcePath = isset($item['image']) ? $this->findSourceImage('activities', $item['image']) : null;

                $optimized = $sourcePath
                    ? $this->images->storeFromPath($sourcePath, "activities/{$activityId}")
                    : $this->placeholderImage("activities/{$activityId}", $this->destinationCategoryLabels[$destinationName] ?? 'Sorsogon');

                DB::table('activities')->where('id', $activityId)->update([
                    'image_path' => $optimized['image_path'],
                    'thumbnail_path' => $optimized['thumbnail_path'],
                ]);
            }
        }
    }

    private function findSourceImage(string $directory, string $basename): ?string
    {
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $path = storage_path("app/seed-images/{$directory}/{$basename}.{$ext}");
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /** @return array{image_path: string, thumbnail_path: string} */
    private function placeholderImage(string $directory, string $label): array
    {
        $path = "{$directory}/placeholder-".Str::slug($label).'.svg';

        if (! Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, $this->buildPlaceholderSvg($label));
        }

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

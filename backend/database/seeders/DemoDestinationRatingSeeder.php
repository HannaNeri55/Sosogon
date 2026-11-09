<?php

namespace Database\Seeders;

use App\Models\Destination;
use App\Models\Municipality;
use App\Models\Rating;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local/demo only. Gives every destination five more clearly marked demo
 * reviews (is_demo = true, "Demo Review:" prefix) so ratings vary between
 * destinations. Admin-featured destinations get stronger score profiles;
 * every average is still computed from the stored review rows.
 *
 * Also marks the older "Demo Review:" rows as is_demo, so they can be hidden
 * outside local development (config/reviews.php).
 *
 * Bulan destinations are skipped unless DEMO_INCLUDE_BULAN=1 is set, which
 * requires the owner's explicit approval. DEMO_PREVIEW=1 prints the plan
 * (including Bulan) without writing anything.
 *
 * Idempotent: each extra review belongs to its own demo account
 * (sosogon.demo.d{destination}r{slot}@example.com), matched on re-runs.
 */
class DemoDestinationRatingSeeder extends Seeder
{
    private const PREFIX = 'Demo Review: ';

    /** Score profiles (5 reviews each). Featured places use the strong set. */
    private const STRONG = [[5, 5, 4, 5, 5], [5, 4, 5, 5, 5], [5, 5, 5, 4, 5], [4, 5, 5, 5, 4]];

    private const MIXED = [
        [4, 3, 4, 5, 3], [3, 4, 3, 4, 4], [5, 4, 4, 3, 4], [4, 4, 5, 4, 3], [3, 3, 4, 4, 5],
        [4, 5, 4, 4, 4], [5, 4, 5, 4, 3], [3, 4, 4, 3, 3], [4, 4, 4, 5, 5], [5, 3, 4, 4, 4],
    ];

    private const FIRST = [
        'Aira', 'Benedict', 'Celine', 'Darwin', 'Eunice', 'Fidel', 'Gwen', 'Harvey', 'Ivy', 'Jeric',
        'Kaye', 'Lance', 'Mae', 'Niño', 'Odette', 'Pia', 'Quino', 'Rhoda', 'Santi', 'Tess',
        'Ulysses', 'Vina', 'Wilfredo', 'Xyza', 'Yancy', 'Zenaida', 'Arvin', 'Bianca', 'Cyrus', 'Dahlia',
        'Emil', 'Fe', 'Gino', 'Hazel Mae', 'Isko', 'Jona', 'Kiko', 'Lorna', 'Mon', 'Nadine',
    ];

    private const LAST = [
        'Abrenica', 'Bagasbas', 'Cabanela', 'Dalida', 'Esplana', 'Fortes', 'Gamboa', 'Habitan', 'Imperial', 'Jolejole',
        'Lagonoy', 'Madrinan', 'Nacional', 'Obias', 'Pesino', 'Relativo', 'Sanchez-Lim', 'Tapia', 'Ubaldo', 'Valenzona',
        'Abenojar', 'Barcelona', 'Collantes', 'Dioneda', 'Encinas', 'Frivaldo', 'Gerona', 'Hebres', 'Ibañez', 'Jamisola',
        'Lasala', 'Marcellana', 'Nebres', 'Ortile', 'Perdigon', 'Rocafort', 'Salvino', 'Tolosa', 'Villaruz', 'Yanson',
    ];

    /** Opening sentence by destination category; {name} is the destination. */
    private const OPENERS = [
        'Beach' => ['{name} was a nice place to relax by the water.', 'We spent a slow, happy afternoon at {name}.', 'The shoreline at {name} was lovely in the late afternoon.', 'A refreshing stop by the sea at {name}.', '{name} made for a relaxing beach day.'],
        'Mountain' => ['The view from {name} was worth the climb.', '{name} has fresh air and a wide view of the area.', 'We enjoyed the cool breeze at {name}.', 'A good spot for nature lovers, {name} did not disappoint.', 'The scenery around {name} was beautiful.'],
        'Waterfall' => ['The falls at {name} were refreshing to see.', '{name} is a calm and green place to visit.', 'We enjoyed the sound of the water at {name}.', 'The walk to {name} was part of the fun.', 'A lovely nature stop, {name} was worth it.'],
        'Historical' => ['{name} is a meaningful look at local history.', 'We learned a lot visiting {name}.', '{name} is a good stop for anyone curious about Sorsogon\'s past.', 'Glad we included {name} in our trip.', 'A quiet and interesting visit to {name}.'],
        'Church' => ['{name} has a calm and solemn feel.', 'We stopped at {name} for a quiet moment.', 'The old architecture of {name} is worth seeing.', 'A peaceful place for reflection, {name} was a good stop.', '{name} is a beautiful place to visit.'],
        'Park' => ['{name} is a pleasant place for a stroll.', 'We liked spending the late afternoon at {name}.', '{name} is a nice open space to take a break.', 'A relaxing stop at {name} after a busy day.', 'We enjoyed the breeze at {name}.'],
        'Eco-Tourism' => ['{name} is a great way to enjoy nature in Sorsogon.', 'We loved the greenery at {name}.', '{name} was a refreshing nature experience.', 'A calm escape into nature at {name}.', 'Our group enjoyed exploring {name}.'],
        'Island' => ['The trip to {name} was a highlight of our visit.', '{name} has beautiful views all around.', 'We enjoyed the peaceful atmosphere at {name}.', 'Getting to {name} was an adventure in itself.', 'A scenic place to explore, {name} was memorable.'],
        'Resort' => ['We had a relaxing day at {name}.', '{name} was a nice spot for a family outing.', 'A calm place to unwind, {name} was enjoyable.', 'We spent a pleasant weekend day at {name}.', '{name} was a good place to rest with friends.'],
        'default' => ['We enjoyed our visit to {name}.', '{name} was a nice stop on our trip.', 'Worth a visit if you are near {name}.', 'A memorable part of our Sorsogon trip was {name}.', 'We had a good time at {name}.'],
    ];

    /** Closing sentence by score. */
    private const CLOSERS = [
        5 => ['Highly recommended.', 'We would gladly come back.', 'One of the best stops on our trip.', 'Loved it.', 'Definitely worth the visit.'],
        4 => ['A good experience overall.', 'We would visit again.', 'Nice place, recommended.', 'We enjoyed it.', 'Worth including in an itinerary.'],
        3 => ['Okay overall, but it could be better maintained.', 'It was fine for a short stop.', 'Decent, though it got crowded.', 'Nice, but plan ahead for the trip there.', 'Average experience for us.'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo reviews cannot be seeded in production.');
        }

        $preview = getenv('DEMO_PREVIEW') === '1';
        $includeBulan = getenv('DEMO_INCLUDE_BULAN') === '1';
        $bulanId = Municipality::where('name', 'Bulan')->value('id') ?? throw new RuntimeException('Bulan municipality not found.');
        $touristRole = Role::where('name', 'tourist')->firstOrFail();

        $destinations = Destination::with('category')->orderBy('id')->get();
        // Names already used by other accounts (this seeder's own accounts excluded, so re-runs match).
        $existingNames = User::where('email', 'not like', 'sosogon.demo.d%r%@example.com')
            ->pluck('name')->map(fn ($n) => mb_strtolower($n))->flip();

        $plan = [];
        foreach ($destinations as $destination) {
            $isBulan = (int) $destination->municipality_id === (int) $bulanId;
            $reviews = Rating::where('rateable_type', Destination::class)->where('rateable_id', $destination->id);
            $plan[] = [
                'destination' => $destination,
                'bulan' => $isBulan,
                'apply' => ! $isBulan || $includeBulan,
                'demo' => (clone $reviews)->where('comment', 'like', 'Demo Review:%')->count(),
                'genuine' => (clone $reviews)->where(fn ($q) => $q->whereNull('comment')->orWhere('comment', 'not like', 'Demo Review:%'))->count(),
                'extra' => $this->extraReviews($destination, $existingNames),
            ];
        }

        if ($preview) {
            $this->printPreview($plan);

            return;
        }

        DB::transaction(function () use ($plan, $touristRole, $bulanId, $includeBulan) {
            // 1. Mark older seeded rows explicitly (Bulan destinations only when approved).
            Rating::where('comment', 'like', 'Demo Review:%')
                ->where('is_demo', false)
                ->when(! $includeBulan, fn ($q) => $q->whereNot(fn ($q) => $q
                    ->where('rateable_type', Destination::class)
                    ->whereIn('rateable_id', Destination::where('municipality_id', $bulanId)->pluck('id'))))
                ->update(['is_demo' => true]);

            // 2. Five more demo reviews per destination, then recompute its average.
            $created = 0;
            foreach ($plan as $row) {
                if (! $row['apply']) {
                    continue;
                }
                foreach ($row['extra'] as $review) {
                    $user = User::firstOrCreate(
                        ['email' => $review['email']],
                        ['role_id' => $touristRole->id, 'name' => $review['name'], 'password' => Hash::make(Str::random(48)), 'status' => 'active']
                    );
                    if ($user->role_id !== $touristRole->id || $user->name !== $review['name']) {
                        throw new RuntimeException("Demo email {$review['email']} belongs to an unexpected user.");
                    }

                    $rating = Rating::firstOrCreate(
                        ['user_id' => $user->id, 'rateable_type' => Destination::class, 'rateable_id' => $row['destination']->id],
                        ['score' => $review['score'], 'comment' => self::PREFIX.$review['comment']]
                    );
                    if ($rating->wasRecentlyCreated) {
                        $rating->timestamps = false;
                        $rating->forceFill(['is_demo' => true, 'created_at' => $review['date'], 'updated_at' => $review['date']])->save();
                        $created++;
                    }
                }
                $row['destination']->recalculateRating();
            }

            $this->command?->info("Demo destination reviews: {$created} created.".($includeBulan ? '' : ' Bulan destinations skipped.'));
        });
    }

    /** The five extra reviews planned for a destination (deterministic). */
    private function extraReviews(Destination $destination, $existingNames): array
    {
        $profiles = $destination->is_featured ? self::STRONG : self::MIXED;
        $scores = $profiles[$destination->id % count($profiles)];
        $openers = self::OPENERS[$destination->category?->name] ?? self::OPENERS['default'];
        $reviews = [];

        foreach ($scores as $slot => $score) {
            // Injective over (destination, slot): a different first/last pair for each review.
            $n = $destination->id * 5 + $slot;
            $first = self::FIRST[$n % count(self::FIRST)];
            $last = self::LAST[(intdiv($n, count(self::FIRST)) + 7 * ($n % count(self::FIRST))) % count(self::LAST)];
            $name = "{$first} {$last}";
            if (isset($existingNames[mb_strtolower($name)])) {
                $name = "{$first} ".chr(65 + $slot).". {$last}";
            }

            $reviews[] = [
                'email' => "sosogon.demo.d{$destination->id}r{$slot}@example.com",
                'name' => $name,
                'score' => $score,
                'comment' => str_replace('{name}', $destination->name, $openers[($destination->id + $slot) % count($openers)])
                    .' '.self::CLOSERS[$score][($destination->id * 3 + $slot) % count(self::CLOSERS[$score])],
                'date' => now()->subDays(3 + ($n * 23) % 200)->startOfHour(),
            ];
        }

        return $reviews;
    }

    private function printPreview(array $plan): void
    {
        foreach (['Bulan (needs your approval)' => true, 'Other municipalities' => false] as $label => $bulan) {
            $this->command?->line("== {$label}");
            foreach (array_filter($plan, fn ($row) => $row['bulan'] === $bulan) as $row) {
                $d = $row['destination'];
                $current = Rating::where('rateable_type', Destination::class)->where('rateable_id', $d->id);
                $newAvg = round(((float) (clone $current)->sum('score') + array_sum(array_column($row['extra'], 'score')))
                    / max(1, (clone $current)->count() + 5), 2);
                $this->command?->line(sprintf(
                    '#%d %s | featured=%s | stored avg %s from %d reviews (%d demo, %d genuine) | +5 demo (%s) -> avg %s from %d',
                    $d->id, $d->name, $d->is_featured ? 'yes' : 'no', $d->average_rating, $row['demo'] + $row['genuine'],
                    $row['demo'], $row['genuine'], implode(',', array_column($row['extra'], 'score')), $newAvg, $row['demo'] + $row['genuine'] + 5
                ));
            }
        }
    }
}

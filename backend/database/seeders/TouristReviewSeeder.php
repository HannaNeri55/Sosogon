<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Rating;
use App\Models\RetailShop;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Adds a new round of sample reviews to every public listing: destinations (Bulan included),
 * accommodations, food & beverage establishments and shops, including those that already have
 * reviews. Mostly 4 and 5 stars, with some 3s and a few 1s and 2s; each listing gets its own mix.
 *
 * Reviewers are a fixed pool of 200 Tourist accounts with ordinary names (TOURIST_POOL; emails at
 * example.com, which never receives mail). Like the other sample reviews, rows are flagged
 * is_demo and start with "Demo Review:" (stripped wherever reviews are shown), so
 * SHOW_DEMO_REVIEWS=false hides them all (config/reviews.php).
 *
 * Rows are written directly, so owners get no "new review" notifications. Stored averages are
 * recalculated afterwards for the listings touched.
 *
 * Idempotent: a listing that already has a review from the pool gets none. Existing reviews are
 * never changed. TOURIST_REVIEWS=remove deletes exactly the pool's reviews and accounts.
 */
class TouristReviewSeeder extends Seeder
{
    private const PREFIX = 'Demo Review: ';

    private const POOL = 200;

    /** Weights for 5, 4, 3, 2 and 1 stars. */
    private const PROFILES = [
        'strong' => [5 => 62, 4 => 27, 3 => 6, 2 => 3, 1 => 2],
        'good' => [5 => 45, 4 => 35, 3 => 12, 2 => 5, 1 => 3],
        'fair' => [5 => 30, 4 => 38, 3 => 18, 2 => 9, 1 => 5],
    ];

    /** How often each profile is picked, out of 100 (featured and must-visit places are always strong). */
    private const PROFILE_SHARE = ['strong' => 35, 'good' => 45, 'fair' => 20];

    private array $content;

    /** @var array<string, true> comments (without the prefix) already used anywhere */
    private array $used = [];

    public function run(): void
    {
        $this->content = require database_path('seeders/data/tourist_reviews.php');

        if (env('TOURIST_REVIEWS') === 'remove') {
            $this->remove();

            return;
        }

        $role = Role::where('name', 'tourist')->first() ?? throw new RuntimeException('The tourist role is missing; run RoleSeeder first.');
        $pool = $this->reviewers($role);
        $poolIds = array_flip($pool);

        $this->used = Rating::whereNotNull('comment')->pluck('comment')
            ->mapWithKeys(fn ($c) => [Str::after($c, self::PREFIX) => true])->all();

        $listings = $this->listings();
        // Who has reviewed what already (one query, not one per listing: TiDB is far away).
        $reviewed = DB::table('ratings')->whereIn('rateable_type', array_unique(array_column($listings, 0)))
            ->get(['rateable_type', 'rateable_id', 'user_id'])
            ->groupBy(fn ($r) => "{$r->rateable_type}:{$r->rateable_id}");

        $rows = [];
        $touched = [];
        $skipped = 0;
        foreach ($listings as [$class, $listing, $group, $kind, $strong]) {
            $reviewers = ($reviewed["{$class}:{$listing->id}"] ?? collect())->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            if (array_intersect_key(array_flip($reviewers), $poolIds) !== []) {
                $skipped++;

                continue;
            }

            $random = new Randomizer(new Mt19937(crc32("{$class}:{$listing->id}")));
            $count = $reviewers === [] ? $random->getInt(8, 14) : $random->getInt(4, 8);
            $weights = self::PROFILES[$strong ? 'strong' : $this->pick(self::PROFILE_SHARE, $random)];
            $free = array_values(array_diff($pool, $reviewers));
            $free = $random->shuffleArray($free);

            foreach (array_slice($free, 0, $count) as $userId) {
                $score = (int) $this->pick($weights, $random);
                $comment = $random->getInt(1, 100) <= 6 ? null : $this->comment($group, $kind, $score, $listing->name, $random);
                $date = now()->subDays(min($random->getInt(1, 330), $random->getInt(1, 330)))->setTime($random->getInt(7, 21), $random->getInt(0, 59));
                $rows[] = [
                    'user_id' => $userId,
                    'rateable_type' => $class,
                    'rateable_id' => $listing->id,
                    'score' => $score,
                    'comment' => $comment === null ? null : self::PREFIX.$comment,
                    'is_demo' => true,
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
            }
            $touched[$class][] = $listing->id;
        }

        DB::transaction(function () use ($rows, $touched) {
            foreach (array_chunk($rows, 300) as $chunk) {
                DB::table('ratings')->insert($chunk);
            }
            $this->refreshAggregates($touched);
        });

        $scores = array_count_values(array_column($rows, 'score'));
        krsort($scores);
        $this->command?->info(sprintf(
            'Added %d reviews to %d listings (%d already had this round). Stars: %s.',
            count($rows), array_sum(array_map('count', $touched)), $skipped,
            implode(', ', array_map(fn ($s, $n) => "{$s}★ {$n}", array_keys($scores), $scores)),
        ));
    }

    /**
     * Every public listing as [class, model, opener group, detail kind, always strong?].
     *
     * @return array<int, array{0: string, 1: Model, 2: string, 3: string, 4: bool}>
     */
    private function listings(): array
    {
        $public = fn ($query, string $table) => Schema::hasColumn($table, 'is_sample') ? $query->where('is_sample', false) : $query;
        $kinds = [
            'Beach' => 'beach', 'Island' => 'beach', 'Mountain' => 'nature', 'Waterfall' => 'nature', 'Eco-Tourism' => 'nature',
            'Historical' => 'heritage', 'Church' => 'heritage', 'Park' => 'park', 'Resort' => 'resort',
        ];
        $list = [];

        foreach ($public(Destination::published()->with('category:id,name'), 'destinations')->orderBy('id')->get() as $d) {
            $strong = $d->is_featured || in_array($d->tourism_priority, ['iconic', 'popular'], true);
            $list[] = [Destination::class, $d, 'place', $kinds[$d->category?->name] ?? 'nature', $strong];
        }
        foreach ($public(Accommodation::approved(), 'accommodations')->orderBy('id')->get() as $a) {
            $list[] = [Accommodation::class, $a, 'stay', 'stay', false];
        }
        foreach ($public(FoodBeverage::approved(), 'food_beverages')->orderBy('id')->get() as $f) {
            $cafe = preg_match('/caf[eé]|coffee|milk ?tea|dessert|bakery/i', $f->cuisine_type.' '.$f->name);
            $list[] = [FoodBeverage::class, $f, 'food', $cafe ? 'cafe' : 'restaurant', false];
        }
        foreach ($public(RetailShop::approved(), 'retail_shops')->orderBy('id')->get() as $s) {
            $craft = preg_match('/craft|shell|native|weav|abaca|souvenir/i', $s->name) && ! preg_match('/pili|pasalubong|delicac|food|dried/i', $s->name);
            $list[] = [RetailShop::class, $s, 'shop', $craft ? 'shop_craft' : 'shop_food', false];
        }

        return $list;
    }

    /**
     * The pool's Tourist accounts (created when missing), in pool order.
     *
     * @return array<int, int> user ids
     */
    private function reviewers(Role $role): array
    {
        $people = $this->pool();
        $existing = DB::table('users')->whereIn('email', array_column($people, 'email'))->get(['id', 'email', 'role_id'])->keyBy('email');
        foreach ($existing as $user) {
            if ((int) $user->role_id !== (int) $role->id) {
                throw new RuntimeException("{$user->email} belongs to an account that is not a tourist.");
            }
        }

        $password = Hash::make(Str::random(40));
        $missing = [];
        foreach ($people as $i => $person) {
            if (! $existing->has($person['email'])) {
                $joined = now()->subDays(340 + $i % 60);
                $missing[] = $person + [
                    'role_id' => $role->id, 'password' => $password, 'status' => 'active',
                    'email_verified_at' => $joined, 'created_at' => $joined, 'updated_at' => $joined,
                ];
            }
        }
        DB::table('users')->insert($missing);

        $ids = DB::table('users')->whereIn('email', array_column($people, 'email'))->pluck('id', 'email');

        return array_map(fn ($p) => (int) $ids[$p['email']], $people);
    }

    /**
     * The same 200 names and emails on every database.
     *
     * @return array<int, array{name: string, email: string}>
     */
    private function pool(): array
    {
        $random = new Randomizer(new Mt19937(20261113));
        $first = $this->content['first_names'];
        $last = $this->content['last_names'];
        $slug = fn ($s) => preg_replace('/[^a-z]/', '', Str::lower(Str::ascii($s)));
        $people = [];
        while (count($people) < self::POOL) {
            $given = $first[$random->getInt(0, count($first) - 1)];
            $family = $last[$random->getInt(0, count($last) - 1)];
            $number = $random->getInt(10, 99);
            if (! isset($people["{$given} {$family}"])) {
                $people["{$given} {$family}"] = ['name' => "{$given} {$family}", 'email' => $slug($given).'.'.$slug($family).$number.'@example.com'];
            }
        }

        return array_values($people);
    }

    private function comment(string $group, string $kind, int $score, string $name, Randomizer $random): ?string
    {
        $band = $score <= 2 ? 'low' : $score;
        $openers = $this->content['openers'][$group][$band];
        $details = $this->content['details'][$kind][$band];
        $closers = $this->content['closers'][$band];

        for ($try = 0; $try < 40; $try++) {
            $parts = [$openers[$random->getInt(0, count($openers) - 1)]];
            $picked = $random->pickArrayKeys($details, $random->getInt(1, 100) <= 60 ? 2 : 1);
            foreach ($random->shuffleArray($picked) as $key) {
                $parts[] = $details[$key];
            }
            if ($random->getInt(1, 100) <= 45) {
                $parts[] = $closers[$random->getInt(0, count($closers) - 1)];
            }
            $comment = str_replace('{name}', $name, implode(' ', $parts));
            if (! isset($this->used[$comment])) {
                $this->used[$comment] = true;

                return $comment;
            }
        }

        return null;
    }

    private function pick(array $weights, Randomizer $random): string|int
    {
        $roll = $random->getInt(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /** Stored averages, counted from visible reviews as RatingController does (shops are computed on read). */
    private function refreshAggregates(array $touched): void
    {
        foreach ($touched as $class => $ids) {
            $table = (new $class)->getTable();
            if (! Schema::hasColumn($table, 'average_rating')) {
                continue;
            }
            $stats = Rating::visible()->where('rateable_type', $class)->whereIn('rateable_id', $ids)
                ->selectRaw('rateable_id, avg(score) as average, count(*) as total')->groupBy('rateable_id')->get()->keyBy('rateable_id');
            foreach ($ids as $id) {
                DB::table($table)->where('id', $id)->update([
                    'average_rating' => round((float) ($stats[$id]->average ?? 0), 2),
                    'total_reviews' => (int) ($stats[$id]->total ?? 0),
                ]);
            }
        }
    }

    private function remove(): void
    {
        $ids = DB::table('users')->whereIn('email', array_column($this->pool(), 'email'))->pluck('id');
        $ratings = DB::table('ratings')->whereIn('user_id', $ids);
        $touched = (clone $ratings)->get(['rateable_type', 'rateable_id'])
            ->groupBy('rateable_type')->map(fn ($g) => $g->pluck('rateable_id')->unique()->values()->all())->all();

        DB::transaction(function () use ($ids, $ratings, $touched) {
            $ratingIds = (clone $ratings)->pluck('id');
            DB::table('rating_replies')->whereIn('rating_id', $ratingIds)->delete();
            $removed = DB::table('ratings')->whereIn('id', $ratingIds)->delete();
            DB::table('users')->whereIn('id', $ids)->delete();
            $this->refreshAggregates($touched);
            $this->command?->info("Removed {$removed} reviews and {$ids->count()} reviewer accounts.");
        });
    }
}

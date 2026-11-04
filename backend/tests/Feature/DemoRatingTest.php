<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Rating;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DemoDestinationRatingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Demo-review marking, visibility, rating calculation and featured ordering. */
class DemoRatingTest extends TestCase
{
    use RefreshDatabase;

    private int $bulan;

    private int $town;

    private int $category;

    private User $tourist;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DEMO_PREVIEW');
        putenv('DEMO_INCLUDE_BULAN');

        $this->tourist = User::create([
            'role_id' => Role::create(['name' => 'tourist', 'description' => 'Tourist'])->id,
            'name' => 'Real Visitor', 'email' => 'real@example.com', 'password' => bcrypt('password'),
        ]);
        $this->bulan = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Bulusan', 'created_at' => now(), 'updated_at' => now()]);
        $this->category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function destination(string $name, int $town, bool $featured = false): Destination
    {
        return Destination::create([
            'name' => $name, 'description' => "{$name} for testing.", 'category_id' => $this->category,
            'municipality_id' => $town, 'address' => 'Test St.', 'latitude' => 12.7, 'longitude' => 124.0,
            'created_by' => $this->tourist->id, 'status' => 'published', 'is_featured' => $featured,
        ]);
    }

    private function review(Destination $d, int $score, string $comment, bool $demo = false, ?User $user = null): Rating
    {
        $user ??= User::create(['role_id' => $this->tourist->role_id, 'name' => 'U'.uniqid(), 'email' => uniqid().'@example.com', 'password' => 'x']);
        $rating = Rating::create(['user_id' => $user->id, 'rateable_type' => Destination::class, 'rateable_id' => $d->id, 'score' => $score, 'comment' => $comment]);
        if ($demo) {
            $rating->forceFill(['is_demo' => true])->save();
        }

        return $rating;
    }

    public function test_demo_reviews_are_hidden_and_excluded_from_averages_when_disabled(): void
    {
        $spot = $this->destination('Spot', $this->town);
        $this->review($spot, 2, 'Genuine but unhappy.', false, $this->tourist);
        $this->review($spot, 5, 'Demo Review: Sample.', true);

        config(['reviews.show_demo' => false]);
        $spot->recalculateRating();
        $page = $this->getJson("/api/v1/destinations/{$spot->id}")->assertOk();
        $this->assertSame(['Genuine but unhappy.'], array_column($page->json('destination.ratings'), 'comment'));
        $this->assertEquals(2, $page->json('destination.average_rating'));
        $this->assertEquals(1, $page->json('destination.total_reviews'));

        config(['reviews.show_demo' => true]);
        $spot->recalculateRating();
        $page = $this->getJson("/api/v1/destinations/{$spot->id}")->assertOk();
        $this->assertCount(2, $page->json('destination.ratings'));
        $this->assertEquals(3.5, $page->json('destination.average_rating'));
    }

    public function test_a_tourist_review_is_never_marked_as_demo(): void
    {
        $spot = $this->destination('Spot', $this->town);
        $this->actingAs($this->tourist)
            ->postJson('/api/v1/ratings', ['rateable_type' => 'destination', 'rateable_id' => $spot->id, 'score' => 4, 'comment' => 'Nice', 'is_demo' => true])
            ->assertCreated();

        $this->assertFalse(Rating::first()->is_demo);
    }

    public function test_seeder_adds_five_marked_reviews_once_skips_bulan_and_keeps_genuine_reviews(): void
    {
        config(['reviews.show_demo' => true]);
        $featured = $this->destination('Famous Beach', $this->town, true);
        $plain = $this->destination('Quiet Cove', $this->town);
        $bulanSpot = $this->destination('Bulan Spot', $this->bulan);
        $genuine = $this->review($plain, 2, 'Real visit, not great.', false, $this->tourist);
        $oldDemo = $this->review($plain, 4, 'Demo Review: Older sample.');
        $bulanDemo = $this->review($bulanSpot, 4, 'Demo Review: Bulan sample.');
        $bulanBefore = $bulanSpot->fresh()->toArray();

        $this->seed(DemoDestinationRatingSeeder::class);
        $this->seed(DemoDestinationRatingSeeder::class);

        // Five extra demo reviews each, created only once.
        $this->assertSame(5, Rating::where('rateable_id', $featured->id)->where('is_demo', true)->count());
        $this->assertSame(7, Rating::where('rateable_id', $plain->id)->count());
        $this->assertTrue($oldDemo->fresh()->is_demo);

        // Genuine review untouched and still counted.
        $this->assertSame($genuine->only(['score', 'comment', 'user_id']), $genuine->fresh()->only(['score', 'comment', 'user_id']));
        $this->assertFalse($genuine->fresh()->is_demo);

        // Averages come from the stored rows; featured profile is stronger.
        foreach ([$featured, $plain] as $d) {
            $this->assertEqualsWithDelta(Rating::where('rateable_id', $d->id)->avg('score'), (float) $d->fresh()->average_rating, 0.01);
        }
        $this->assertGreaterThan((float) $plain->fresh()->average_rating, (float) $featured->fresh()->average_rating);

        // Bulan: no new reviews, older row not re-marked, destination unchanged.
        $this->assertSame(1, Rating::where('rateable_id', $bulanSpot->id)->count());
        $this->assertFalse($bulanDemo->fresh()->is_demo);
        $this->assertSame($bulanBefore, $bulanSpot->fresh()->toArray());

        // Every generated reviewer has a different name.
        $names = User::where('email', 'like', 'sosogon.demo.d%')->pluck('name');
        $this->assertCount(10, $names);
        $this->assertSame($names->count(), $names->unique()->count());
    }

    public function test_preview_mode_writes_nothing(): void
    {
        $spot = $this->destination('Spot', $this->town);
        putenv('DEMO_PREVIEW=1');
        try {
            $this->seed(DemoDestinationRatingSeeder::class);
        } finally {
            putenv('DEMO_PREVIEW');
        }

        $this->assertSame(0, Rating::count());
        $this->assertSame(0, User::where('email', 'like', 'sosogon.demo.d%')->count());
        $this->assertEquals(0, $spot->fresh()->average_rating);
    }

    public function test_featured_places_come_first_ordered_by_rating(): void
    {
        $low = $this->destination('Featured Low', $this->town, true);
        $high = $this->destination('Featured High', $this->town, true);
        $other = $this->destination('Not Featured Top', $this->town);
        $low->forceFill(['average_rating' => 4.1])->save();
        $high->forceFill(['average_rating' => 4.8])->save();
        $other->forceFill(['average_rating' => 5.0])->save();

        $names = array_column($this->getJson('/api/v1/destinations?sort=featured&per_page=6')->assertOk()->json('data'), 'name');

        $this->assertSame(['Featured High', 'Featured Low', 'Not Featured Top'], $names);
    }
}

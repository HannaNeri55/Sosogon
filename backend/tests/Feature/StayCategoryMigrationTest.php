<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Placeholder "Accommodation" listings get a category only where their own name or description states one. */
class StayCategoryMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $migration;

    private function stay(string $name, string $type = 'Accommodation', ?string $classifications = null): int
    {
        static $town;
        $town ??= DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('accommodations')->insertGetId([
            'name' => $name, 'description' => 'A test stay.', 'type' => $type, 'classifications' => $classifications,
            'municipality_id' => $town, 'address' => 'Test St.', 'latitude' => 12.67, 'longitude' => 123.88, 'price_range' => 'moderate', 'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function row(int $id): object
    {
        return DB::table('accommodations')->where('id', $id)->first(['type', 'classifications']);
    }

    public function test_only_stated_kinds_are_set_and_reversing_puts_the_placeholder_back(): void
    {
        $this->migration = require database_path('migrations/2026_11_08_000000_classify_stays_from_their_stated_kind.php');
        $hotel = $this->stay('Caree Boutique Hotel');
        $beach = $this->stay('Elysia Beach Resort');
        $lodge = $this->stay('Birbeck Lodge');
        $owned = $this->stay('Casa Buenas Hotel', 'Hotel', '["Hotel","Resort"]');

        $this->migration->up();

        $this->assertEquals((object) ['type' => 'Hotel', 'classifications' => '["Hotel"]'], $this->row($hotel));
        $this->assertEquals((object) ['type' => 'Beach Resort', 'classifications' => '["Beach Resort"]'], $this->row($beach));
        $this->assertEquals((object) ['type' => 'Accommodation', 'classifications' => null], $this->row($lodge), 'A lodge is not one of the categories; it is not guessed.');
        $this->assertEquals((object) ['type' => 'Hotel', 'classifications' => '["Hotel","Resort"]'], $this->row($owned), 'Categories already chosen are kept.');
        $this->getJson('/api/v1/accommodations?type=Hotel')->assertOk()->assertJsonCount(2, 'data');

        $this->migration->down();

        $this->assertEquals((object) ['type' => 'Accommodation', 'classifications' => null], $this->row($hotel));
        $this->assertEquals((object) ['type' => 'Hotel', 'classifications' => '["Hotel","Resort"]'], $this->row($owned));
    }
}

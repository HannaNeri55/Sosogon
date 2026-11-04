<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The Photo Credits page lists stored credits for public listings only. */
class PhotoCreditTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_gallery_activity_and_room_credits_for_public_listings_only(): void
    {
        $userId = User::create([
            'role_id' => Role::create(['name' => 'admin', 'description' => 'Admin'])->id,
            'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
        ])->id;
        $town = DB::table('municipalities')->insertGetId(['name' => 'Testville', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('categories')->insert(['id' => 1, 'name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $place = fn (array $fields) => $fields + ['description' => 'A test listing.', 'municipality_id' => $town, 'address' => 'Test St.',
            'latitude' => 12.67, 'longitude' => 123.88, 'created_at' => now(), 'updated_at' => now()];

        DB::table('destinations')->insert($place(['id' => 1, 'name' => 'Lagoon', 'category_id' => 1, 'created_by' => $userId, 'status' => 'published']));
        DB::table('destinations')->insert($place(['id' => 2, 'name' => 'Draft Spot', 'category_id' => 1, 'created_by' => $userId, 'status' => 'draft']));
        DB::table('destination_images')->insert([
            ['destination_id' => 1, 'image_path' => 'a.webp', 'thumbnail_path' => 't/a.webp', 'credit' => 'Ann / Wikimedia Commons, CC BY-SA 4.0', 'source_url' => 'https://commons.wikimedia.org/wiki/File:A.jpg', 'sort_order' => 0, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
            ['destination_id' => 1, 'image_path' => 'b.webp', 'thumbnail_path' => 't/b.webp', 'credit' => null, 'source_url' => null, 'sort_order' => 1, 'is_primary' => false, 'created_at' => now(), 'updated_at' => now()],
            ['destination_id' => 2, 'image_path' => 'c.webp', 'thumbnail_path' => 't/c.webp', 'credit' => 'Hidden', 'source_url' => null, 'sort_order' => 0, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('activities')->insert(['destination_id' => 1, 'name' => 'Kayaking', 'image_path' => 'k.webp',
            'image_credit' => 'Representative photo: Bo / Wikimedia Commons, CC BY 2.0', 'image_source_url' => 'https://commons.wikimedia.org/wiki/File:K.jpg',
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('accommodations')->insert($place(['id' => 5, 'name' => 'Test Inn', 'type' => 'Inn', 'price_range' => 'moderate', 'status' => 'approved']));
        DB::table('accommodations')->insert($place(['id' => 6, 'name' => 'Pending Inn', 'type' => 'Inn', 'price_range' => 'moderate', 'status' => 'pending']));
        DB::table('accommodation_room_types')->insert([
            ['accommodation_id' => 5, 'name' => 'Family Room', 'image_path' => 'f.webp', 'image_credit' => 'Representative photo: Cy / Wikimedia Commons, CC0', 'image_source_url' => null, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['accommodation_id' => 6, 'name' => 'Suite', 'image_path' => 's.webp', 'image_credit' => 'Representative photo: Hidden', 'image_source_url' => null, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $listings = $this->getJson('/api/v1/photo-credits')->assertOk()->json('listings');

        $this->assertSame(['Lagoon', 'Test Inn'], array_column($listings, 'name'));
        $this->assertSame([
            ['subject' => 'Gallery photo', 'credit' => 'Ann / Wikimedia Commons, CC BY-SA 4.0', 'source_url' => 'https://commons.wikimedia.org/wiki/File:A.jpg', 'representative' => false],
            ['subject' => 'Kayaking', 'credit' => 'Bo / Wikimedia Commons, CC BY 2.0', 'source_url' => 'https://commons.wikimedia.org/wiki/File:K.jpg', 'representative' => true],
        ], $listings[0]['credits']);
        $this->assertSame([['subject' => 'Family Room', 'credit' => 'Cy / Wikimedia Commons, CC0', 'source_url' => null, 'representative' => true]], $listings[1]['credits']);
    }
}

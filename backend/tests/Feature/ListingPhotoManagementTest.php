<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\FoodBeverage;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** New photos can be ordered and chosen as cover before they are saved; saved photos stay intact. */
class ListingPhotoManagementTest extends TestCase
{
    use RefreshDatabase;

    private int $town;

    private int $ownerRole;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Business Owner'])->id;
        $this->town = DB::table('municipalities')->insertGetId(['name' => 'Donsol', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function types(): array
    {
        return [
            'accommodation' => [Accommodation::class, 'accommodation', 'accommodation_images', 'accommodation_id', ['type' => 'Inn', 'price_range' => 'moderate'], 'accommodations'],
            'food_beverage' => [FoodBeverage::class, 'food-beverage', 'food_beverage_images', 'food_beverage_id', ['price_range' => 'moderate'], 'food-beverages'],
            'retail_product' => [RetailShop::class, 'retail-shop', 'retail_shop_images', 'retail_shop_id', [], 'retail-shops'],
        ];
    }

    /** @return array{0: User, 1: Model, 2: int[]} owner, listing, saved image ids (first is the cover) */
    private function listing(string $type, int $saved, string $status = 'pending'): array
    {
        [$class, , $table, $fk, $extra] = $this->types()[$type];
        $owner = User::create(['role_id' => $this->ownerRole, 'name' => 'Owner', 'email' => "{$type}@example.com", 'password' => bcrypt('x'),
            'status' => 'active', 'business_type' => $type]);
        $listing = $class::create(['user_id' => $owner->id, 'name' => 'Whale Shark Inn', 'description' => str_repeat('A test listing description. ', 3),
            'municipality_id' => $this->town, 'address' => 'Dancalan, Donsol', 'latitude' => 12.9, 'longitude' => 123.57, 'status' => $status] + $extra);
        $listing->forceFill(['publication_review_status' => $status === 'approved' ? 'published' : 'draft'])->save();

        $ids = [];
        for ($i = 0; $i < $saved; $i++) {
            Storage::disk('public')->put("seed/{$type}-{$i}.webp", 'x');
            $ids[] = DB::table($table)->insertGetId([$fk => $listing->id, 'image_path' => "seed/{$type}-{$i}.webp", 'thumbnail_path' => "seed/{$type}-{$i}.webp",
                'is_primary' => $i === 0, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
        }

        return [$owner, $listing, $ids];
    }

    private function save(string $type, User $owner, array $data)
    {
        return $this->actingAs($owner)->post("/api/v1/business/{$this->types()[$type][1]}", $data + ['_method' => 'PUT'], ['Accept' => 'application/json']);
    }

    private function gallery(string $type, Model $listing): array
    {
        [, , $table, $fk] = $this->types()[$type];

        return DB::table($table)->where($fk, $listing->id)->orderBy('sort_order')->get(['id', 'is_primary', 'image_path'])->all();
    }

    private function newPhotos(int $count): array
    {
        return array_map(fn ($i) => UploadedFile::fake()->image("new-{$i}.jpg", 40, 30), range(0, $count - 1));
    }

    public function test_new_photos_can_be_ordered_and_set_as_cover_before_saving(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, $listing, [$a, $b]] = $this->listing($type, 2);

            $this->save($type, $owner, [
                'images' => $this->newPhotos(3),
                'photo_order' => ['n:2', "e:{$a}", 'n:0', "e:{$b}", 'n:1'],
                'cover' => 'n:2',
            ])->assertOk();

            $rows = $this->gallery($type, $listing);
            $this->assertCount(5, $rows, $type);
            $newIds = collect($rows)->pluck('id')->reject(fn ($id) => in_array($id, [$a, $b]))->sort()->values()->all(); // n:0, n:1, n:2 in upload order
            $this->assertSame([$newIds[2], $a, $newIds[0], $b, $newIds[1]], array_column($rows, 'id'), $type);
            $this->assertSame([$newIds[2]], collect($rows)->where('is_primary', 1)->pluck('id')->all(), "{$type}: exactly one cover, the chosen new photo");
            $this->assertSame(5, count(array_unique(array_column($rows, 'image_path'))), "{$type}: no duplicates");
        }
    }

    public function test_adding_photos_keeps_saved_photos_and_the_existing_cover(): void
    {
        [$owner, $listing, [$a, $b]] = $this->listing('accommodation', 2);

        $this->save('accommodation', $owner, ['images' => $this->newPhotos(2)])->assertOk();

        $rows = $this->gallery('accommodation', $listing);
        $this->assertSame([$a, $b], array_slice(array_column($rows, 'id'), 0, 2));
        $this->assertSame([$a], collect($rows)->where('is_primary', 1)->pluck('id')->all());
        $this->assertCount(4, $rows);
    }

    public function test_removing_the_cover_leaves_a_valid_cover_and_deletes_the_file(): void
    {
        [$owner, $listing, [$a, $b, $c]] = $this->listing('accommodation', 3);

        $this->save('accommodation', $owner, ['remove_image_ids' => [$a], 'photo_order' => ["e:{$c}", "e:{$b}"]])->assertOk();

        $rows = $this->gallery('accommodation', $listing);
        $this->assertSame([$c, $b], array_column($rows, 'id'));
        $this->assertSame([$c], collect($rows)->where('is_primary', 1)->pluck('id')->all());
        Storage::disk('public')->assertMissing('seed/accommodation-0.webp');
        Storage::disk('public')->assertExists('seed/accommodation-1.webp');
    }

    public function test_invalid_arrangements_are_rejected_without_storing_anything(): void
    {
        [$owner, $listing, [$a, $b]] = $this->listing('accommodation', 2);
        $filesBefore = count(Storage::disk('public')->allFiles());
        $invalid = [
            'photo missing from order' => ['images' => $this->newPhotos(1), 'photo_order' => ["e:{$a}", 'n:0']],
            'unknown upload index' => ['images' => $this->newPhotos(1), 'photo_order' => ["e:{$a}", "e:{$b}", 'n:5']],
            'cover not in gallery' => ['images' => $this->newPhotos(1), 'cover' => 'n:3'],
            'another listing\'s photo' => ['remove_image_ids' => [999999]],
            'bad token' => ['photo_order' => ["e:{$a}", 'x:1']],
        ];

        foreach ($invalid as $label => $data) {
            $this->save('accommodation', $owner, $data)->assertUnprocessable();
            $this->assertCount(2, $this->gallery('accommodation', $listing), $label);
            $this->assertCount($filesBefore, Storage::disk('public')->allFiles(), "{$label}: no files stored");
        }
    }

    public function test_photo_changes_to_a_published_listing_are_staged_for_review(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, $listing, $ids] = $this->listing($type, 5, 'approved');
            $liveBefore = $this->gallery($type, $listing);

            $this->save($type, $owner, ['images' => $this->newPhotos(1), 'photo_order' => array_merge(['n:0'], array_map(fn ($id) => "e:{$id}", array_reverse($ids))), 'cover' => 'n:0'])->assertOk();

            // Live gallery and public cover unchanged...
            $this->assertEquals($liveBefore, $this->gallery($type, $listing), $type);
            // ...the proposal holds the chosen order and cover.
            $proposal = $listing->revisions()->latest('id')->firstOrFail()->proposed_gallery;
            $this->assertCount(6, $proposal, $type);
            $this->assertStringStartsWith('new:', $proposal[0]['key'], $type);
            $this->assertTrue($proposal[0]['is_primary'], $type);
            $this->assertSame(array_map(fn ($id) => "existing:{$id}", array_reverse($ids)), array_column(array_slice($proposal, 1), 'key'), $type);
            $this->assertSame(1, collect($proposal)->where('is_primary', true)->count(), $type);
        }
    }

    public function test_publication_still_requires_five_photos(): void
    {
        foreach (array_keys($this->types()) as $type) {
            [$owner, $listing, [$a]] = $this->listing($type, 4);
            $this->actingAs($owner)->postJson("/api/v1/business/{$this->types()[$type][1]}/submit-for-review")->assertUnprocessable();

            $this->save($type, $owner, ['images' => $this->newPhotos(1), 'cover' => 'n:0'])->assertOk();
            $this->actingAs($owner)->postJson("/api/v1/business/{$this->types()[$type][1]}/submit-for-review")->assertOk();
            $this->assertSame('submitted', $listing->fresh()->publication_review_status, $type);
        }
    }
}

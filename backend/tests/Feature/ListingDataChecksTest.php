<?php

namespace Tests\Feature;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Models\Role;
use App\Models\User;
use App\Services\ItineraryService;
use App\Services\ListingDataChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Data checks (ListingDataChecks, `php artisan listings:data-checks`): problems in public listings
 * that make trips worse or mislead tourists. Read-only.
 */
class ListingDataChecksTest extends TestCase
{
    use RefreshDatabase;

    private int $adminRole;

    private int $bulan;

    private int $irosin;

    private User $provincial;

    private User $owner;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminRole = Role::create(['name' => 'admin', 'description' => 'Admin'])->id;
        $ownerRole = Role::create(['name' => 'business_owner', 'description' => 'Owner'])->id;
        $this->bulan = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $this->irosin = DB::table('municipalities')->insertGetId(['name' => 'Irosin', 'created_at' => now(), 'updated_at' => now()]);
        $this->provincial = $this->admin(User::ADMIN_SCOPE_PROVINCIAL);
        $this->owner = User::create(['role_id' => $ownerRole, 'name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $this->category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function admin(string $scope, ?int $municipality = null): User
    {
        $user = User::create(['role_id' => $this->adminRole, 'name' => 'Admin '.uniqid(), 'email' => uniqid().'@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $user->forceFill(['admin_scope' => $scope, 'municipality_id' => $municipality])->save();

        return $user->fresh();
    }

    /** A destination with everything the checks look for, plus $fields. */
    private function destination(int $town, array $fields = [], array $activities = [['Swimming', 90, 'Morning']]): Destination
    {
        $place = Destination::create($fields + [
            'name' => 'Otavi Beach', 'description' => str_repeat('A long white-sand beach with clear water and cottages. ', 3),
            'category_id' => $this->category, 'municipality_id' => $town, 'address' => 'Test St.', 'latitude' => 12.6, 'longitude' => 123.9,
            'operating_hours' => '6:00 AM - 6:00 PM', 'entrance_fee' => 20, 'created_by' => $this->provincial->id, 'status' => 'published',
        ]);
        foreach ($activities as [$name, $minutes, $best]) {
            DB::table('activities')->insert(['destination_id' => $place->id, 'name' => $name, 'duration_minutes' => $minutes, 'best_time_to_visit' => $best, 'type' => 'outdoor', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('destination_images')->insert(['destination_id' => $place->id, 'image_path' => "destinations/{$place->id}/a.jpg", 'thumbnail_path' => "destinations/{$place->id}/a-thumb.jpg", 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $place;
    }

    private function issues(?array $towns = null)
    {
        return app(ListingDataChecks::class)->run($towns);
    }

    private function codes(?int $id = null, string $type = 'destination'): array
    {
        return $this->issues()
            ->filter(fn ($i) => $i['type'] === $type && ($id === null || $i['id'] === $id))
            ->pluck('code')->sort()->values()->all();
    }

    public function test_a_complete_destination_has_no_problems(): void
    {
        $place = $this->destination($this->irosin);

        $this->assertSame([], $this->codes($place->id));
    }

    public function test_destination_problems_are_found_and_explained(): void
    {
        $place = $this->destination($this->irosin, [
            'name' => 'St. JamesThe greater Sprts Church', 'operating_hours' => 'Open daily (best in daylight)', 'entrance_fee' => null,
            'description' => 'A public sports facility. It is a recreation venue rather than a historical or sightseeing attraction.',
        ], [['Island hopping', 180, 'Morning'], ['Island Hopping', null, null], ['Swimming', null, 'Midday']]);
        DB::table('destinations')->where('id', $place->id)->update(['tourism_priority' => 'iconic']);
        $empty = $this->destination($this->irosin, ['name' => 'Quiet Cove', 'operating_hours' => null], []);

        $this->assertSame(['activity_no_best_time', 'activity_no_length', 'duplicate_activity', 'name_typo', 'no_fee', 'not_an_attraction', 'unreadable_hours'],
            $this->codes($place->id));
        $this->assertSame(['no_activities', 'no_hours'], $this->codes($empty->id));

        $issues = $this->issues()->where('id', $place->id)->keyBy('code');
        $this->assertStringContainsString('Island hopping', $issues['duplicate_activity']['message']);
        $this->assertStringContainsString('marked Iconic', $issues['not_an_attraction']['message']);
        $this->assertSame('high', $issues['not_an_attraction']['severity']);
        $this->assertSame('admin', $issues['no_fee']['fixed_by']);
    }

    public function test_listing_hours_the_planner_now_reads(): void
    {
        $planner = app(ItineraryService::class);
        foreach (['Daily, 11:00 AM – 12:00 MN', 'Tuesday – Sunday, 12:00 NN – 10:00 PM', '4:00 PM – 12:00 midnight', 'Always Open', '⏰Day Tour : from 8am until 5pm', '24 hours'] as $hours) {
            $this->assertTrue($planner->readsHours($hours), $hours);
        }
        foreach (['Opens at 2:00pm', 'Open daily (best visited in daylight)', ''] as $hours) {
            $this->assertFalse($planner->readsHours($hours), $hours);
        }
        $open = (new \ReflectionMethod(ItineraryService::class, 'openingHours'))->invoke($planner, '11:00 AM – 12:00 MN', null);
        $this->assertSame([660, 1440], $open, 'Open until midnight.');
    }

    public function test_business_problems_are_for_their_owners(): void
    {
        $place = ['municipality_id' => $this->irosin, 'address' => 'Test St.', 'description' => 'A test listing.', 'user_id' => $this->owner->id, 'status' => 'approved'];
        $inn = Accommodation::create($place + ['name' => 'Demo Inn', 'type' => 'Inn', 'price_range' => 'moderate', 'contact_number' => '(056) 000-0106', 'latitude' => 12.7, 'longitude' => 124.0]);
        $grill = FoodBeverage::create($place + ['name' => 'Irosin grill', 'price_range' => 'budget', 'operating_hours' => '9:00 AM - 9:00 PM', 'latitude' => 12.7, 'longitude' => 124.0]);

        $this->assertSame(['no_photo', 'no_priced_rooms', 'placeholder_contact'], $this->codes($inn->id, 'accommodation'));
        $this->assertSame(['name_capitals', 'no_cuisine', 'no_menu', 'no_photo'], $this->codes($grill->id, 'food_beverage'));
        $issue = $this->issues()->firstWhere('code', 'placeholder_contact');
        $this->assertSame('owner', $issue['fixed_by']);
    }

    public function test_a_town_report_has_only_that_towns_listings(): void
    {
        $bulanPlace = $this->destination($this->bulan, ['name' => 'Bulan Cove', 'operating_hours' => null]);
        $irosinPlace = $this->destination($this->irosin, ['name' => 'Irosin Falls', 'operating_hours' => null]);

        $this->assertSame([$bulanPlace->id], $this->issues([$this->bulan])->pluck('id')->unique()->values()->all());
        $this->assertEqualsCanonicalizing([$bulanPlace->id, $irosinPlace->id], $this->issues()->pluck('id')->unique()->all());
    }
}

<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Destination;
use App\Models\ExperienceTag;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ExperienceTagSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Experience tags on destinations (Phase 2C): the seeded vocabulary, choosing tags in the admin
 * destination form, and the per-destination review mark. Nothing here changes how itineraries
 * pick destinations.
 */
class AdminDestinationExperienceTest extends TestCase
{
    use RefreshDatabase;

    private int $adminRole;

    private int $bulan;

    private int $matnog;

    private int $category;

    private User $provincial;

    private User $bulanAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminRole = Role::create(['name' => 'admin', 'description' => 'Admin'])->id;
        Role::create(['name' => 'tourist', 'description' => 'Tourist']);
        $this->bulan = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $this->matnog = DB::table('municipalities')->insertGetId(['name' => 'Matnog', 'created_at' => now(), 'updated_at' => now()]);
        $this->category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $this->provincial = $this->admin('provincial');
        // Bulan has its own tourism office (an active municipal admin); Matnog doesn't.
        $this->bulanAdmin = $this->admin('municipal', $this->bulan);
    }

    private function admin(?string $scope, ?int $town = null): User
    {
        $user = User::create(['role_id' => $this->adminRole, 'name' => 'Admin '.uniqid(), 'email' => uniqid().'@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $user->forceFill(['admin_scope' => $scope, 'municipality_id' => $town])->save();

        return $user->fresh();
    }

    private function fields(int $town, array $extra = []): array
    {
        return $extra + [
            'name' => 'Test Cove', 'description' => 'A beach used for testing.', 'category_id' => $this->category,
            'municipality_id' => $town, 'address' => 'Test St.', 'latitude' => 12.67, 'longitude' => 123.88, 'status' => 'published',
        ];
    }

    private function destination(int $town, string $name = 'Test Cove'): Destination
    {
        return Destination::create($this->fields($town, ['name' => $name, 'created_by' => $this->provincial->id]));
    }

    private function create(User $admin, int $town, array $extra)
    {
        return $this->actingAs($admin)->post('/api/v1/admin/destinations', $this->fields($town, $extra), ['Accept' => 'application/json']);
    }

    /** A save from the admin form: multipart fields plus PUT override, like ManageDestinations.jsx. */
    private function save(User $admin, Destination $destination, array $extra)
    {
        return $this->actingAs($admin)->post("/api/v1/admin/destinations/{$destination->id}", $extra + ['_method' => 'PUT'], ['Accept' => 'application/json']);
    }

    private function slugs(Destination $destination): array
    {
        return $destination->fresh()->experienceTags()->pluck('slug')->sort()->values()->all();
    }

    public function test_the_vocabulary_is_seeded_once_with_stable_slugs_and_no_destination_is_tagged(): void
    {
        $destination = $this->destination($this->matnog);
        $slugs = array_column(ExperienceTagSeeder::TAGS, 0);

        $this->assertSame($slugs, ExperienceTag::ordered()->pluck('slug')->all());
        $this->assertCount(18, $slugs);
        $this->assertTrue(ExperienceTag::all()->every(fn ($t) => $t->name !== '' && strlen($t->description) > 20 && $t->group !== ''));

        // Running the seeder again neither duplicates tags nor tags anything.
        (new ExperienceTagSeeder)->run();
        (new ExperienceTagSeeder)->run();
        $this->assertSame(18, ExperienceTag::count());
        $this->assertSame(0, DB::table('destination_experience_tag')->count());
        $this->assertFalse($destination->fresh()->experiencesReviewed());
    }

    public function test_a_destination_has_several_tags_and_a_tag_several_destinations(): void
    {
        $cove = $this->destination($this->matnog, 'Cove');
        $bay = $this->destination($this->matnog, 'Bay');
        $beach = ExperienceTag::where('slug', 'beach')->first();
        $cove->experienceTags()->attach(ExperienceTag::whereIn('slug', ['beach', 'pool', 'camping'])->pluck('id'));
        $bay->experienceTags()->attach($beach->id);

        $this->assertSame(['beach', 'camping', 'pool'], $this->slugs($cove));
        $this->assertEqualsCanonicalizing(['Cove', 'Bay'], $beach->destinations()->pluck('name')->all());

        // Tags go with a deleted destination; a tag still in use can't be deleted.
        $bay->delete();
        $this->assertSame(['Cove'], $beach->destinations()->pluck('name')->all());
        $this->expectException(QueryException::class);
        $beach->delete();
    }

    public function test_tags_chosen_in_the_form_are_saved_but_do_not_mark_the_destination_reviewed(): void
    {
        $id = $this->create($this->provincial, $this->matnog, ['experiences_present' => '1', 'experience_tags' => ['beach', 'water_sports']])
            ->assertCreated()
            ->assertJsonPath('destination.experiences_reviewed_at', null)
            ->json('destination.id');

        $destination = Destination::findOrFail($id);
        $this->assertSame(['beach', 'water_sports'], $this->slugs($destination));
        $this->assertFalse($destination->experiencesReviewed());
        $this->assertNull($destination->experiences_reviewed_by);
    }

    public function test_unknown_or_repeated_tags_are_rejected(): void
    {
        $destination = $this->destination($this->matnog);

        $this->save($this->provincial, $destination, ['experiences_present' => '1', 'experience_tags' => ['beach', 'volcano']])
            ->assertUnprocessable()->assertJsonValidationErrors('experience_tags.1');
        $this->save($this->provincial, $destination, ['experiences_present' => '1', 'experience_tags' => ['beach', 'beach']])
            ->assertUnprocessable()->assertJsonValidationErrors('experience_tags.0');
        $this->save($this->provincial, $destination, ['experiences_present' => '1', 'experience_tags' => 'beach'])
            ->assertUnprocessable()->assertJsonValidationErrors('experience_tags');

        $this->assertSame([], $this->slugs($destination));
    }

    public function test_a_destination_can_only_be_marked_reviewed_with_at_least_one_tag(): void
    {
        $count = Destination::count();
        $this->create($this->provincial, $this->matnog, ['experiences_present' => '1', 'experiences_reviewed' => '1'])
            ->assertUnprocessable()->assertJsonValidationErrors('experiences_reviewed');
        $this->assertSame($count, Destination::count(), 'Nothing is created when the review mark is refused.');

        $destination = $this->destination($this->matnog);
        $this->save($this->provincial, $destination, ['name' => 'Renamed', 'experiences_present' => '1', 'experiences_reviewed' => '1'])
            ->assertUnprocessable()->assertJsonValidationErrors('experiences_reviewed');
        $this->assertSame('Test Cove', $destination->fresh()->name, 'The refused save changes nothing.');
        $this->assertFalse($destination->fresh()->experiencesReviewed());
    }

    public function test_marking_reviewed_records_who_and_when(): void
    {
        $destination = $this->destination($this->matnog);
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(9, 0));

        $this->save($this->provincial, $destination, ['experiences_present' => '1', 'experience_tags' => ['beach'], 'experiences_reviewed' => '1'])
            ->assertOk()
            ->assertJsonPath('destination.experiences_reviewed_by', $this->provincial->id)
            ->assertJsonPath('destination.experiences_reviewer.name', $this->provincial->name);

        $fresh = $destination->fresh();
        $this->assertTrue($fresh->experiencesReviewed());
        $this->assertSame('2026-11-16 09:00', $fresh->experiences_reviewed_at->format('Y-m-d H:i'));
        $this->assertSame($this->provincial->id, $fresh->experiences_reviewed_by);
        $this->assertTrue(ActivityLog::where('action', 'reviewed_destination_experiences')->where('model_id', $destination->id)->exists());

        // Saving the same tags again keeps the original review; changing them records the new reviewer.
        $other = $this->admin('provincial');
        $this->travel(2)->hours();
        $this->save($other, $destination, ['experiences_present' => '1', 'experience_tags' => ['beach'], 'experiences_reviewed' => '1'])->assertOk();
        $this->assertSame($this->provincial->id, $destination->fresh()->experiences_reviewed_by);
        $this->assertSame('2026-11-16 09:00', $destination->fresh()->experiences_reviewed_at->format('Y-m-d H:i'));

        $this->save($other, $destination, ['experiences_present' => '1', 'experience_tags' => ['beach', 'camping'], 'experiences_reviewed' => '1'])->assertOk();
        $this->assertSame($other->id, $destination->fresh()->experiences_reviewed_by);
        $this->assertSame('2026-11-16 11:00', $destination->fresh()->experiences_reviewed_at->format('Y-m-d H:i'));
    }

    public function test_a_reviewed_destination_can_return_to_not_reviewed_and_keeps_its_tags(): void
    {
        $destination = $this->destination($this->matnog);
        $this->save($this->provincial, $destination, ['experiences_present' => '1', 'experience_tags' => ['beach', 'pool'], 'experiences_reviewed' => '1'])->assertOk();

        $this->save($this->provincial, $destination, ['experiences_present' => '1', 'experience_tags' => ['beach', 'pool'], 'experiences_reviewed' => '0'])
            ->assertOk()->assertJsonPath('destination.experiences_reviewed_at', null);

        $fresh = $destination->fresh();
        $this->assertFalse($fresh->experiencesReviewed());
        $this->assertNull($fresh->experiences_reviewed_by);
        $this->assertSame(['beach', 'pool'], $this->slugs($destination));
        $this->assertTrue(ActivityLog::where('action', 'unreviewed_destination_experiences')->exists());
    }

    public function test_a_reviewed_destination_cannot_lose_its_last_tag_while_reviewed(): void
    {
        $destination = $this->destination($this->matnog);
        $this->save($this->provincial, $destination, ['experiences_present' => '1', 'experience_tags' => ['beach'], 'experiences_reviewed' => '1'])->assertOk();

        // The form sends no tags at all (every chip cleared) and leaves the review mark as it was.
        $this->save($this->provincial, $destination, ['experiences_present' => '1'])
            ->assertUnprocessable()->assertJsonValidationErrors('experiences_reviewed');
        $this->assertSame(['beach'], $this->slugs($destination));

        // Not reviewed first, then the tags can go.
        $this->save($this->provincial, $destination, ['experiences_present' => '1', 'experiences_reviewed' => '0'])->assertOk();
        $this->assertSame([], $this->slugs($destination));
        $this->assertFalse($destination->fresh()->experiencesReviewed());
    }

    public function test_other_saves_leave_tags_review_and_status_alone(): void
    {
        $destination = $this->destination($this->matnog);
        $this->save($this->provincial, $destination, ['experiences_present' => '1', 'experience_tags' => ['beach'], 'experiences_reviewed' => '1'])->assertOk();
        $reviewedAt = $destination->fresh()->experiences_reviewed_at;

        // A save that doesn't send the Experiences section (an older form, another client).
        $this->save($this->provincial, $destination, ['name' => 'Renamed Cove'])->assertOk();
        $this->assertSame(['beach'], $this->slugs($destination));
        $this->assertEquals($reviewedAt, $destination->fresh()->experiences_reviewed_at);

        // The review workflow never publishes or unpublishes.
        $draft = $this->destination($this->matnog, 'Draft Spot');
        $draft->update(['status' => 'draft']);
        $this->save($this->provincial, $draft, ['experiences_present' => '1', 'experience_tags' => ['viewpoint'], 'experiences_reviewed' => '1'])->assertOk();
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame('published', $destination->fresh()->status);
    }

    public function test_admin_list_and_detail_show_tags_and_review_status_but_the_public_api_does_not(): void
    {
        $reviewed = $this->destination($this->matnog, 'Reviewed Cove');
        $this->save($this->provincial, $reviewed, ['experiences_present' => '1', 'experience_tags' => ['beach'], 'experiences_reviewed' => '1'])->assertOk();
        $this->destination($this->matnog, 'Untagged Hill');

        $rows = collect($this->actingAs($this->provincial)->getJson('/api/v1/admin/destinations')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(['beach'], array_column($rows['Reviewed Cove']['experience_tags'], 'slug'));
        $this->assertNotNull($rows['Reviewed Cove']['experiences_reviewed_at']);
        $this->assertSame($this->provincial->name, $rows['Reviewed Cove']['experiences_reviewer']['name']);
        $this->assertSame([], $rows['Untagged Hill']['experience_tags']);
        $this->assertNull($rows['Untagged Hill']['experiences_reviewed_at']);

        $this->actingAs($this->provincial)->getJson("/api/v1/admin/destinations/{$reviewed->id}")
            ->assertOk()->assertJsonPath('experience_tags.0.slug', 'beach')->assertJsonPath('experiences_reviewed_by', $this->provincial->id);

        // Public pages are unchanged: no tags, no review fields.
        $public = $this->getJson("/api/v1/destinations/{$reviewed->id}")->assertOk()->json('destination');
        $this->assertArrayNotHasKey('experience_tags', $public);
        $this->assertArrayNotHasKey('experiences_reviewed_at', $public);
        $this->assertArrayNotHasKey('experiences_reviewed_by', $public);
        $listed = collect($this->getJson('/api/v1/destinations')->assertOk()->json('data'))->firstWhere('id', $reviewed->id);
        $this->assertArrayNotHasKey('experiences_reviewed_at', $listed);
        $this->assertArrayNotHasKey('experience_tags', $listed);
    }

    public function test_the_vocabulary_endpoint_is_for_admins(): void
    {
        $tags = $this->actingAs($this->bulanAdmin)->getJson('/api/v1/admin/experience-tags')->assertOk()->json();
        $this->assertCount(18, $tags);
        $this->assertSame(['slug', 'name', 'group', 'description'], array_keys($tags[0]));
        $this->assertSame('beach', $tags[0]['slug']);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/experience-tags')->assertUnauthorized();
        $tourist = User::create(['role_id' => Role::where('name', 'tourist')->value('id'), 'name' => 'Tourist', 'email' => 't@example.com', 'password' => 'Secret123!', 'status' => 'active']);
        $this->actingAs($tourist)->getJson('/api/v1/admin/experience-tags')->assertForbidden();
    }

    public function test_provincial_admins_tag_destinations_of_towns_without_their_own_office(): void
    {
        $matnogSpot = $this->destination($this->matnog, 'Matnog Spot');
        $this->save($this->provincial, $matnogSpot, ['experiences_present' => '1', 'experience_tags' => ['island'], 'experiences_reviewed' => '1'])->assertOk();
        $this->assertSame(['island'], $this->slugs($matnogSpot));

        $id = $this->create($this->provincial, $this->matnog, ['name' => 'New Matnog Spot', 'experiences_present' => '1', 'experience_tags' => ['beach'], 'experiences_reviewed' => '1'])
            ->assertCreated()->json('destination.id');
        $this->assertTrue(Destination::findOrFail($id)->experiencesReviewed());
    }

    public function test_municipal_admins_tag_only_their_own_towns_destinations(): void
    {
        $bulanSpot = $this->destination($this->bulan, 'Bulan Spot');
        $matnogSpot = $this->destination($this->matnog, 'Matnog Spot');

        $this->save($this->bulanAdmin, $bulanSpot, ['experiences_present' => '1', 'experience_tags' => ['beach'], 'experiences_reviewed' => '1'])->assertOk();
        $this->assertSame($this->bulanAdmin->id, $bulanSpot->fresh()->experiences_reviewed_by);

        $this->save($this->bulanAdmin, $matnogSpot, ['experiences_present' => '1', 'experience_tags' => ['beach'], 'experiences_reviewed' => '1'])->assertForbidden();
        $this->create($this->bulanAdmin, $this->matnog, ['experiences_present' => '1', 'experience_tags' => ['beach']])->assertForbidden();
        $this->assertSame([], $this->slugs($matnogSpot));
        $this->assertFalse($matnogSpot->fresh()->experiencesReviewed());

        // The existing rule: a town with its own office is edited by that office, not the province.
        $this->save($this->provincial, $bulanSpot, ['experiences_present' => '1', 'experiences_reviewed' => '0'])->assertForbidden();
        $this->assertTrue($bulanSpot->fresh()->experiencesReviewed());
    }

    public function test_existing_destination_saves_work_as_before(): void
    {
        // The form as it was before this phase: no Experiences fields at all.
        $id = $this->create($this->provincial, $this->matnog, ['amenities' => [['name' => 'Parking']], 'setting' => 'outdoor'])
            ->assertCreated()->json('destination.id');
        $destination = Destination::findOrFail($id);
        $this->assertSame(['Parking'], $destination->amenities()->pluck('name')->all());
        $this->assertSame([], $this->slugs($destination));

        $this->save($this->provincial, $destination, ['name' => 'Renamed', 'category_id' => $this->category, 'status' => 'draft', 'details_present' => '1'])
            ->assertOk()->assertJsonPath('destination.name', 'Renamed')->assertJsonPath('destination.status', 'draft');
        $this->assertSame([], $destination->fresh()->amenities()->pluck('name')->all(), 'details_present still replaces the detail lists.');
        $this->assertSame($this->category, (int) $destination->fresh()->category_id);
    }
}

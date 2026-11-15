<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The four Phase 2C migrations (docs/deployment/phase-2c-migration-recovery.md): a fresh install,
 * running them again, resuming after an interrupted run, and stopping with a clear error when an
 * existing object differs. SQLite here; the same scenarios were also run on local MariaDB.
 */
class ExperienceTagMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const STEPS = [
        '2026_11_16_000000_create_experience_tags.php',
        '2026_11_16_000100_create_destination_experience_tag_table.php',
        '2026_11_16_000200_add_experience_review_to_destinations.php',
        '2026_11_16_000300_seed_experience_tags.php',
    ];

    private function migration(int $step): object
    {
        return require database_path('migrations/'.self::STEPS[$step - 1]);
    }

    private function runAll(): void
    {
        foreach (array_keys(self::STEPS) as $index) {
            $this->migration($index + 1)->up();
        }
    }

    /** Index and foreign key signatures, to prove a rerun adds nothing. */
    private function keys(string $table): array
    {
        return [
            collect(Schema::getIndexes($table))->map(fn ($i) => implode(',', $i['columns']).($i['unique'] ? '!' : ''))->sort()->values()->all(),
            collect(Schema::getForeignKeys($table))->map(fn ($f) => implode(',', $f['columns']).'>'.$f['foreign_table'].':'.strtolower($f['on_delete']))->sort()->values()->all(),
        ];
    }

    private function destination(): Destination
    {
        $town = DB::table('municipalities')->insertGetId(['name' => 'Bulan', 'created_at' => now(), 'updated_at' => now()]);
        $category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination', 'created_at' => now(), 'updated_at' => now()]);
        $creator = User::create(['role_id' => Role::firstOrCreate(['name' => 'admin'], ['description' => 'Admin'])->id, 'name' => 'Creator', 'email' => 'creator@example.com', 'password' => 'Secret123!']);

        return Destination::create([
            'name' => 'Test Cove', 'description' => 'Test.', 'category_id' => $category, 'municipality_id' => $town,
            'address' => 'Test St.', 'latitude' => 12.6, 'longitude' => 123.8, 'status' => 'published', 'created_by' => $creator->id,
        ]);
    }

    private function expectStructureError(int $step, string $fragment): void
    {
        try {
            $this->migration($step)->up();
            $this->fail('The migration accepted a structure that differs from its own.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($fragment, $e->getMessage());
            $this->assertStringContainsString('docs/deployment/phase-2c-migration-recovery.md', $e->getMessage());
        }
    }

    public function test_a_fresh_install_creates_the_whole_structure_and_tags_nothing(): void
    {
        $destination = $this->destination();

        $this->assertTrue(Schema::hasTable('experience_tags'));
        $this->assertTrue(Schema::hasTable('destination_experience_tag'));
        $this->assertTrue(Schema::hasColumns('destinations', ['experiences_reviewed_at', 'experiences_reviewed_by']));
        $this->assertSame(18, DB::table('experience_tags')->count());
        $this->assertSame(0, DB::table('destination_experience_tag')->count());
        $this->assertNull($destination->fresh()->experiences_reviewed_at);
        $this->assertNull($destination->fresh()->experiences_reviewed_by);

        [, $pivotForeign] = $this->keys('destination_experience_tag');
        $this->assertSame(['destination_id>destinations:cascade', 'experience_tag_id>experience_tags:restrict'], $pivotForeign);
        [, $destinationForeign] = $this->keys('destinations');
        $this->assertContains('experiences_reviewed_by>users:set null', $destinationForeign);
    }

    public function test_running_the_migrations_again_changes_nothing_and_keeps_data(): void
    {
        $destination = $this->destination();
        $reviewer = User::create(['role_id' => Role::firstOrCreate(['name' => 'admin'], ['description' => 'Admin'])->id, 'name' => 'Admin', 'email' => 'a@example.com', 'password' => 'Secret123!']);
        $beach = DB::table('experience_tags')->where('slug', 'beach')->value('id');
        $destination->experienceTags()->attach($beach);
        $destination->forceFill(['experiences_reviewed_at' => now(), 'experiences_reviewed_by' => $reviewer->id])->save();
        $before = [$this->keys('experience_tags'), $this->keys('destination_experience_tag'), $this->keys('destinations'), DB::table('experience_tags')->orderBy('id')->get()->toArray()];

        $this->runAll();
        $this->runAll();

        $after = [$this->keys('experience_tags'), $this->keys('destination_experience_tag'), $this->keys('destinations'), DB::table('experience_tags')->orderBy('id')->get()->toArray()];
        $this->assertEquals($before, $after);
        $this->assertSame([$beach], $destination->fresh()->experienceTags()->pluck('experience_tags.id')->all());
        $this->assertSame($reviewer->id, $destination->fresh()->experiences_reviewed_by);
    }

    public function test_step_1_resumes_a_vocabulary_table_left_without_its_unique_index(): void
    {
        Schema::table('destination_experience_tag', fn (Blueprint $t) => $t->dropForeign(['experience_tag_id']));
        Schema::table('experience_tags', fn (Blueprint $t) => $t->dropUnique('experience_tags_slug_unique'));

        $this->migration(1)->up();

        $this->assertContains('slug!', $this->keys('experience_tags')[0]);
    }

    public function test_step_2_resumes_a_pivot_table_left_without_its_keys(): void
    {
        // As if the run stopped right after CREATE TABLE: the columns only.
        Schema::drop('destination_experience_tag');
        Schema::create('destination_experience_tag', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('destination_id');
            $table->unsignedBigInteger('experience_tag_id');
            $table->timestamps();
        });

        $this->migration(2)->up();
        $this->migration(2)->up();

        [$indexes, $foreign] = $this->keys('destination_experience_tag');
        $this->assertContains('destination_id,experience_tag_id!', $indexes);
        $this->assertContains('experience_tag_id', $indexes);
        $this->assertSame(['destination_id>destinations:cascade', 'experience_tag_id>experience_tags:restrict'], $foreign);
    }

    public function test_step_3_resumes_after_only_the_first_column_was_added(): void
    {
        $this->migration(3)->down();
        Schema::table('destinations', fn (Blueprint $t) => $t->timestamp('experiences_reviewed_at')->nullable());

        $this->migration(3)->up();

        $this->assertTrue(Schema::hasColumn('destinations', 'experiences_reviewed_by'));
        [$indexes, $foreign] = $this->keys('destinations');
        $this->assertContains('experiences_reviewed_at', $indexes);
        $this->assertContains('experiences_reviewed_by>users:set null', $foreign);
    }

    public function test_step_3_down_removes_only_what_is_there(): void
    {
        $this->migration(3)->down();
        $this->migration(3)->down();
        $this->assertFalse(Schema::hasColumn('destinations', 'experiences_reviewed_at'));

        Schema::table('destinations', fn (Blueprint $t) => $t->timestamp('experiences_reviewed_at')->nullable());
        $this->migration(3)->down();
        $this->assertFalse(Schema::hasColumn('destinations', 'experiences_reviewed_at'));
    }

    public function test_step_4_adds_only_missing_tags_and_keeps_edited_ones(): void
    {
        DB::table('experience_tags')->where('slug', 'beach')->update(['name' => 'Beach (edited)']);
        $beachId = DB::table('experience_tags')->where('slug', 'beach')->value('id');
        DB::table('experience_tags')->whereIn('slug', ['farm', 'camping', 'museum'])->delete();

        $this->migration(4)->up();
        $this->migration(4)->up();

        $this->assertSame(18, DB::table('experience_tags')->count());
        $this->assertSame(18, DB::table('experience_tags')->distinct()->count('slug'));
        $this->assertSame('Beach (edited)', DB::table('experience_tags')->where('slug', 'beach')->value('name'));
        $this->assertSame($beachId, DB::table('experience_tags')->where('slug', 'beach')->value('id'));
    }

    public function test_a_pivot_table_with_an_extra_column_stops_the_migration_unchanged(): void
    {
        Schema::drop('destination_experience_tag');
        Schema::create('destination_experience_tag', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('destination_id');
            $table->unsignedBigInteger('experience_tag_id');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        $this->expectStructureError(2, 'unexpected column(s): note');
        $this->assertSame([[], []], [array_values(array_filter($this->keys('destination_experience_tag')[0], fn ($i) => $i !== 'id!')), $this->keys('destination_experience_tag')[1]], 'Nothing was added.');
    }

    public function test_a_foreign_key_with_another_delete_rule_stops_the_migration(): void
    {
        Schema::drop('destination_experience_tag');
        Schema::create('destination_experience_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('destination_id')->constrained()->restrictOnDelete();
            $table->foreignId('experience_tag_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        $this->expectStructureError(2, 'on delete restrict, expected destinations(id) on delete cascade');
    }

    public function test_an_index_with_the_expected_name_on_other_columns_stops_the_migration(): void
    {
        Schema::table('destination_experience_tag', function (Blueprint $t) {
            $t->dropIndex('destination_experience_tag_experience_tag_id_index');
            $t->index('destination_id', 'destination_experience_tag_experience_tag_id_index');
        });

        $this->expectStructureError(2, 'index destination_experience_tag_experience_tag_id_index is on (destination_id)');
    }

    public function test_a_review_column_of_the_wrong_type_stops_the_migration(): void
    {
        $this->migration(3)->down();
        Schema::table('destinations', function (Blueprint $t) {
            $t->timestamp('experiences_reviewed_at')->nullable();
            $t->string('experiences_reviewed_by')->nullable();
        });

        $this->expectStructureError(3, 'column experiences_reviewed_by is varchar');
        $this->assertSame([], array_filter($this->keys('destinations')[1], fn ($f) => str_starts_with($f, 'experiences_reviewed_by')), 'No foreign key was added.');
    }

    public function test_a_vocabulary_table_missing_a_column_stops_the_migration(): void
    {
        Schema::table('destination_experience_tag', fn (Blueprint $t) => $t->dropForeign(['experience_tag_id']));
        Schema::table('experience_tags', fn (Blueprint $t) => $t->dropColumn('description'));

        $this->expectStructureError(1, 'missing column(s) description');
    }
}

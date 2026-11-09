<?php

namespace Tests\Feature;

use App\Console\Commands\ImportDataEnrichment;
use App\Models\FieldProvenance;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportDataEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private int $townId;

    private int $stayId;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->townId = DB::table('municipalities')->insertGetId(['name' => 'Irosin']);
        $this->stayId = $this->stay('Hilltop Inn');
        $this->file = tempnam(sys_get_temp_dir(), 'manifest');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function stay(string $name, array $extra = []): int
    {
        return DB::table('accommodations')->insertGetId($extra + [
            'name' => $name, 'description' => 'A place to stay.', 'type' => 'Inn', 'municipality_id' => $this->townId,
            'address' => 'Irosin', 'latitude' => 12.7, 'longitude' => 124.0, 'price_range' => 'moderate', 'status' => 'approved',
        ]);
    }

    private function change(string $field, mixed $old, mixed $new, string $source = 'generated'): array
    {
        return compact('field', 'source', 'old', 'new');
    }

    private function record(array $fields, ?int $id = null, string $name = 'Hilltop Inn'): array
    {
        return ['table' => 'accommodations', 'id' => $id ?? $this->stayId, 'name' => $name, 'parent' => null, 'fields' => $fields];
    }

    private function room(string $name = 'Family Room'): array
    {
        return [
            'table' => 'accommodation_rooms', 'id' => 900, 'name' => $name,
            'parent' => ['table' => 'accommodations', 'key' => 'accommodation_id', 'id' => $this->stayId, 'name' => 'Hilltop Inn'],
            'values' => ['room_number' => $name, 'name' => $name, 'status' => 'approved', 'max_guests' => 4],
        ];
    }

    private function manifest(array $records = [], array $creates = []): void
    {
        file_put_contents($this->file, json_encode(['batch' => 'test-batch', 'records' => $records, 'creates' => $creates]));
    }

    private function runImport(array $options = ['--apply' => true]): void
    {
        $this->artisan('data:import-enrichment', ['--file' => $this->file] + $options)->assertSuccessful();
    }

    private function stayField(string $field): mixed
    {
        return DB::table('accommodations')->where('id', $this->stayId)->value($field);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->manifest([$this->record([$this->change('check_in_time', null, '2:00 PM')])], [$this->room()]);

        $this->runImport([]);

        $this->assertNull($this->stayField('check_in_time'));
        $this->assertSame(0, DB::table('accommodation_rooms')->count());
        $this->assertSame(0, FieldProvenance::count());
    }

    public function test_it_fills_blanks_creates_rooms_and_records_the_source(): void
    {
        $this->manifest([$this->record([$this->change('check_in_time', null, '2:00 PM')])], [$this->room()]);

        $this->runImport();

        $this->assertSame('2:00 PM', $this->stayField('check_in_time'));
        $this->assertSame(1, DB::table('accommodation_rooms')->where('accommodation_id', $this->stayId)->where('id', 900)->count());
        $this->assertSame(FieldProvenance::GENERATED, FieldProvenance::sourceOf('accommodations', $this->stayId, 'check_in_time'));
        $this->assertSame(FieldProvenance::ORIGINAL, FieldProvenance::sourceOf('accommodations', $this->stayId, 'description'));
    }

    public function test_importing_twice_creates_no_duplicates(): void
    {
        $this->manifest([$this->record([$this->change('check_in_time', null, '2:00 PM')])], [$this->room()]);

        $this->runImport();
        $this->runImport();

        $this->assertSame(1, DB::table('accommodation_rooms')->count());
        $this->assertSame(2, FieldProvenance::count());
    }

    public function test_it_keeps_a_value_changed_on_the_target(): void
    {
        DB::table('accommodations')->where('id', $this->stayId)->update(['check_in_time' => '3:00 PM']);
        $this->manifest([$this->record([$this->change('check_in_time', null, '2:00 PM')])]);

        $this->runImport();

        $this->assertSame('3:00 PM', $this->stayField('check_in_time'));
        $this->assertSame(0, FieldProvenance::count());
    }

    public function test_it_replaces_text_only_when_it_still_matches_the_old_value(): void
    {
        $this->manifest([$this->record([$this->change('description', 'A place to stay.', 'A better description.')])]);

        $this->runImport();

        $this->assertSame('A better description.', $this->stayField('description'));
    }

    public function test_a_status_decision_does_not_override_an_approved_listing(): void
    {
        $this->manifest([$this->record([$this->change('status', 'pending', 'rejected', FieldProvenance::ADMIN_DECISION)])]);

        $this->runImport();

        $this->assertSame('approved', $this->stayField('status'));
    }

    public function test_it_finds_a_record_by_name_when_the_id_differs_and_skips_the_wrong_record(): void
    {
        $this->manifest([$this->record([$this->change('check_in_time', null, '2:00 PM')], $this->stayId + 50)]);
        $this->runImport();
        $this->assertSame('2:00 PM', $this->stayField('check_in_time'));

        $otherId = $this->stay('Someone Else');
        $this->manifest([$this->record([$this->change('check_out_time', null, '11:00 AM')], $otherId, 'Missing Place')]);
        $this->runImport();
        $this->assertNull(DB::table('accommodations')->where('id', $otherId)->value('check_out_time'));
    }

    public function test_it_refuses_fields_and_tables_outside_the_allowed_list(): void
    {
        $this->manifest([$this->record([$this->change('latitude', 12.7, 13.0)])]);
        $this->artisan('data:import-enrichment', ['--file' => $this->file, '--apply' => true])->assertFailed();
        $this->assertEquals(12.7, $this->stayField('latitude'));

        $this->manifest([['table' => 'users', 'id' => 1, 'name' => 'x', 'parent' => null, 'fields' => [$this->change('password', 'a', 'b')]]]);
        $this->artisan('data:import-enrichment', ['--file' => $this->file, '--apply' => true])->assertFailed();
    }

    public function test_it_never_touches_a_bulan_destination(): void
    {
        $bulan = DB::table('municipalities')->insertGetId(['name' => 'Bulan']);
        $category = DB::table('categories')->insertGetId(['name' => 'Beach', 'type' => 'destination']);
        $user = User::factory()->create(['role_id' => Role::create(['name' => 'admin', 'description' => 'Admin'])->id]);
        $id = DB::table('destinations')->insertGetId([
            'name' => 'Bulan Spot', 'description' => 'x', 'category_id' => $category, 'municipality_id' => $bulan,
            'address' => 'Bulan', 'latitude' => 12.6, 'longitude' => 123.8, 'created_by' => $user->id,
        ]);
        $this->manifest([['table' => 'destinations', 'id' => $id, 'name' => 'Bulan Spot', 'parent' => null, 'fields' => [$this->change('operating_hours', null, 'Daily, 6:00 AM – 6:00 PM')]]]);

        $this->runImport();

        $this->assertNull(DB::table('destinations')->where('id', $id)->value('operating_hours'));
    }

    public function test_rollback_restores_only_unedited_values_and_removes_created_rooms(): void
    {
        $this->manifest([$this->record([
            $this->change('check_in_time', null, '2:00 PM'),
            $this->change('check_out_time', null, '11:00 AM'),
        ])], [$this->room()]);
        $this->runImport();
        DB::table('accommodations')->where('id', $this->stayId)->update(['check_out_time' => '10:00 AM']);

        $this->runImport(['--rollback' => true, '--apply' => true]);

        $this->assertNull($this->stayField('check_in_time'));
        $this->assertSame('10:00 AM', $this->stayField('check_out_time'));
        $this->assertSame(0, DB::table('accommodation_rooms')->count());
    }

    public function test_rollback_leaves_values_and_rooms_this_import_did_not_write(): void
    {
        DB::table('accommodations')->where('id', $this->stayId)->update(['check_in_time' => '2:00 PM']);
        DB::table('accommodation_rooms')->insert(['accommodation_id' => $this->stayId, 'room_number' => 'Family Room', 'name' => 'Family Room']);
        $this->manifest([$this->record([$this->change('check_in_time', null, '2:00 PM')])], [$this->room()]);
        $this->runImport();

        $this->runImport(['--rollback' => true, '--apply' => true]);

        $this->assertSame('2:00 PM', $this->stayField('check_in_time'));
        $this->assertSame(1, DB::table('accommodation_rooms')->where('name', 'Family Room')->count());
    }

    public function test_a_listing_that_has_its_own_rooms_does_not_get_generated_ones(): void
    {
        DB::table('accommodation_rooms')->insert(['accommodation_id' => $this->stayId, 'room_number' => 'Owner Suite', 'name' => 'Owner Suite']);
        $this->manifest([], [$this->room()]);

        $this->runImport();

        $this->assertSame(0, DB::table('accommodation_rooms')->where('name', 'Family Room')->count());
    }

    public function test_rollback_keeps_rooms_that_are_in_use_or_edited(): void
    {
        $this->manifest([], [$this->room('Family Room'), ['id' => 901] + $this->room('Twin Room')]);
        $this->runImport();
        $inUse = DB::table('accommodation_rooms')->where('name', 'Family Room')->value('id');
        DB::table('accommodation_room_images')->insert(['room_id' => $inUse, 'image_path' => 'rooms/a.jpg', 'thumbnail_path' => 'rooms/a-thumb.jpg']);
        DB::table('accommodation_rooms')->where('name', 'Twin Room')->update(['max_guests' => 9]);

        $this->runImport(['--rollback' => true, '--apply' => true]);

        $this->assertSame(2, DB::table('accommodation_rooms')->count());
    }

    public function test_the_report_lists_every_outcome(): void
    {
        DB::table('accommodations')->where('id', $this->stayId)->update(['check_out_time' => '10:00 AM']);
        $this->manifest([
            $this->record([$this->change('check_in_time', null, '2:00 PM'), $this->change('check_out_time', null, '11:00 AM')]),
            $this->record([$this->change('check_in_time', null, '2:00 PM')], 4040, 'Missing Place'),
        ], [$this->room()]);
        $report = tempnam(sys_get_temp_dir(), 'report');

        $this->runImport(['--report' => $report]);

        $text = file_get_contents($report);
        @unlink($report);
        foreach (['eligible', 'conflict_existing_preserved', 'record_not_found', 'new_room', '10:00 AM', 'Missing Place'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
    }

    public function test_a_verified_field_survives_repeat_imports_and_rollback(): void
    {
        $this->manifest([$this->record([$this->change('check_in_time', null, '2:00 PM')])]);
        $this->runImport();
        $this->artisan('data:provenance', ['--verify' => "accommodations:{$this->stayId}:check_in_time"])->assertSuccessful();

        $this->runImport();
        $this->runImport(['--rollback' => true, '--apply' => true]);

        $this->assertSame('2:00 PM', $this->stayField('check_in_time'));
        $this->assertSame(FieldProvenance::VERIFIED, FieldProvenance::sourceOf('accommodations', $this->stayId, 'check_in_time'));
    }

    public function test_the_public_api_does_not_expose_provenance(): void
    {
        $this->manifest([$this->record([$this->change('check_in_time', null, '2:00 PM')])]);
        $this->runImport();

        $response = $this->getJson("/api/v1/accommodations/{$this->stayId}")->assertOk();

        $this->assertStringContainsString('2:00 PM', $response->getContent());
        $this->assertStringNotContainsString('previous_value', $response->getContent());
        $this->assertStringNotContainsString('provenance', $response->getContent());
    }

    public function test_the_shipped_manifest_holds_only_the_approved_changes(): void
    {
        $manifest = json_decode(file_get_contents(database_path(ImportDataEnrichment::DEFAULT_FILE)), true);
        $fields = collect($manifest['records'])->flatMap(fn ($r) => array_map(fn ($f) => $f + ['table' => $r['table']], $r['fields']));

        $this->assertSame(1587, $fields->where('source', FieldProvenance::GENERATED)->count());
        $this->assertSame(4, $fields->where('source', FieldProvenance::ADMIN_DECISION)->count());
        $this->assertCount(14, $manifest['creates']);
        $this->assertSame([], array_values(array_diff($fields->pluck('table')->unique()->all(), array_keys(ImportDataEnrichment::FIELDS))));
        $this->assertSame(0, $fields->filter(fn ($f) => ! in_array($f['field'], ImportDataEnrichment::FIELDS[$f['table']], true))->count());
    }
}

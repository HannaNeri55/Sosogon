<?php

namespace App\Console\Commands;

use App\Models\FieldProvenance;
use App\Models\Municipality;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Applies a reviewed data manifest (database/data/*.json) to this database, field by field.
 * Dry run unless --apply, and a dry run needs no schema change and writes nothing. A field is
 * written only when it is blank here or still holds the value the manifest replaced, so anything
 * changed on this database since is reported, never overwritten. Safe to run repeatedly. Only the
 * tables and fields in FIELDS can be touched, and Bulan destinations are always refused.
 * Rollback undoes only what an import wrote here.
 */
class ImportDataEnrichment extends Command
{
    use ConfirmableTrait;

    public const DEFAULT_FILE = 'data/enrichment_2026_11_09.json';

    public const FIELDS = [
        'accommodations' => ['description', 'check_in_time', 'check_out_time', 'operating_hours', 'amenities', 'dining_details', 'offering_arrangement', 'classifications', 'status', 'publication_review_status'],
        'accommodation_rooms' => ['description', 'notes', 'max_guests', 'bed_configuration'],
        'destinations' => ['operating_hours', 'visitor_guidelines', 'dining_details'],
        'activities' => ['description', 'best_time_to_visit', 'what_to_wear', 'things_to_do', 'duration_minutes'],
        'food_beverages' => ['cuisine_type', 'operating_hours', 'specialties', 'meals_served', 'status', 'publication_review_status'],
        'menu_items' => ['description'],
        'retail_shops' => ['operating_hours', 'product_categories'],
        'retail_products' => ['price'],
    ];

    private const REPORT_HEADINGS = ['outcome', 'table', 'manifest_id', 'target_id', 'name', 'field', 'existing_value', 'manifest_value', 'detail'];

    protected $signature = 'data:import-enrichment
        {--file= : Manifest path (default: database/data/enrichment_2026_11_09.json)}
        {--apply : Write the changes; without this nothing is written}
        {--rollback : Undo what this manifest\'s batch wrote, instead of importing}
        {--report= : Also write every outcome, one per field, to this CSV path}
        {--force : Skip the production confirmation}';

    protected $description = 'Apply or roll back a listing data manifest without touching unrelated records';

    private array $counts = [
        'records_matched' => 0, 'records_matched_by_name' => 0, 'records_not_found' => 0,
        'fields_eligible' => 0, 'fields_already_same' => 0, 'fields_in_missing_records' => 0, 'fields_protected' => 0, 'conflicts_preserved' => 0,
        'rooms_new' => 0, 'rooms_existing' => 0, 'rooms_listing_has_own_rooms' => 0, 'rooms_no_parent' => 0,
    ];

    private array $outcomes = [];

    private array $columns = [];

    private array $problems = [];

    private ?array $bulanDestinations = null;

    public function handle(): int
    {
        $path = $this->option('file') ?: database_path(self::DEFAULT_FILE);
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($manifest) || empty($manifest['batch'])) {
            $this->error("Cannot read a manifest at {$path}.");

            return self::FAILURE;
        }
        if (($this->option('apply') || $this->option('rollback')) && ! Schema::hasTable('field_provenance')) {
            $this->error('The field_provenance table is missing. Run its migration first.');

            return self::FAILURE;
        }
        if ($this->option('apply') && ! $this->confirmToProceed('This writes to the production database.')) {
            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->warn('Dry run: nothing will be written. Add --apply to write.');
        }

        return $this->option('rollback') ? $this->rollback($manifest['batch']) : $this->import($manifest);
    }

    private function import(array $manifest): int
    {
        $this->validateManifest($manifest);
        if ($this->problems !== []) {
            return $this->abort();
        }

        DB::beginTransaction();

        try {
            foreach ($manifest['records'] ?? [] as $record) {
                $this->importRecord($record, $manifest['batch']);
            }
            foreach ($manifest['creates'] ?? [] as $create) {
                $this->createRecord($create, $manifest['batch']);
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->printSummary();
        $this->writeReport();

        if ($this->problems !== []) {
            DB::rollBack();

            return $this->abort();
        }

        DB::commit();

        return self::SUCCESS;
    }

    /** Stops on anything outside the allowed tables and fields, before a single row is read for writing. */
    private function validateManifest(array $manifest): void
    {
        foreach ($manifest['records'] ?? [] as $record) {
            foreach ($record['fields'] as $field) {
                if (! in_array($field['field'], self::FIELDS[$record['table']] ?? [], true)) {
                    $this->problems[] = "{$record['table']}.{$field['field']} is not an allowed import field.";
                }
            }
        }
        foreach ($manifest['creates'] ?? [] as $create) {
            if ($create['table'] !== 'accommodation_rooms') {
                $this->problems[] = "Creating {$create['table']} records is not allowed.";
            }
        }
    }

    private function importRecord(array $record, string $batch): void
    {
        $table = $record['table'];
        $row = $this->findRecord($record);

        if (! $row) {
            $this->counts['records_not_found']++;
            $this->counts['fields_in_missing_records'] += count($record['fields']);
            foreach ($record['fields'] as $change) {
                $this->note('record_not_found', $table, $record['id'], null, $record['name'], $change['field'], null, $change['new']);
            }

            return;
        }

        $this->counts[$row->id == $record['id'] ? 'records_matched' : 'records_matched_by_name']++;

        if ($this->isProtected($table, $row)) {
            $this->counts['fields_protected'] += count($record['fields']);
            foreach ($record['fields'] as $change) {
                $this->note('protected', $table, $record['id'], $row->id, $record['name'], $change['field'], null, $change['new'], 'Bulan destination');
            }

            return;
        }

        foreach ($record['fields'] as $change) {
            $this->importField($table, $row, $change, $batch, $record);
        }
    }

    private function importField(string $table, object $row, array $change, string $batch, array $record): void
    {
        $field = $change['field'];
        $new = $change['new'];

        if (! $this->fits($table, $field, $new, $row->id)) {
            return;
        }

        $current = $row->$field;

        if ($this->same($current, $new)) {
            // This import did not put the value here, so a rollback must leave it alone.
            $this->counts['fields_already_same']++;
            $this->note('already_same', $table, $record['id'], $row->id, $record['name'], $field, $current, $new);

            return;
        }
        if (! $this->isBlank($current) && ! $this->same($current, $change['old'])) {
            $this->counts['conflicts_preserved']++;
            $this->note('conflict_existing_preserved', $table, $record['id'], $row->id, $record['name'], $field, $current, $new, 'Changed on this database; left as is.');

            return;
        }

        $this->counts['fields_eligible']++;
        $this->note('eligible', $table, $record['id'], $row->id, $record['name'], $field, $current, $new, $change['source']);

        if ($this->option('apply')) {
            DB::table($table)->where('id', $row->id)->update([$field => $new]);
            $this->remember($table, $row->id, $field, $change['source'], $new, $current, $batch);
        }
    }

    private function createRecord(array $create, string $batch): void
    {
        $table = $create['table'];
        $parent = $this->findExact($create['parent']['table'], $create['parent']['id'], $create['parent']['name'])
            ?? $this->findUniqueByName($create['parent']['table'], $create['parent']['name']);

        if (! $parent) {
            $this->counts['rooms_no_parent']++;
            $this->note('room_no_parent_listing', $table, $create['id'], null, $create['name'], null, null, null, $create['parent']['name']);

            return;
        }

        $key = $create['parent']['key'];
        $existing = DB::table($table)->where($key, $parent->id)->where('name', $create['name'])->first();

        if ($existing) {
            $this->counts['rooms_existing']++;
            $this->note('room_already_exists', $table, $create['id'], $existing->id, $create['name'], null, null, null, $parent->name);

            return;
        }
        if ($this->listingHasOtherRooms($table, $key, $parent->id, $batch)) {
            $this->counts['rooms_listing_has_own_rooms']++;
            $this->note('room_skipped_listing_has_own_rooms', $table, $create['id'], null, $create['name'], null, null, null, $parent->name);

            return;
        }

        foreach ($create['values'] as $field => $value) {
            if (! $this->fits($table, $field, $value, $create['id'])) {
                return;
            }
        }

        $this->counts['rooms_new']++;
        $this->note('new_room', $table, $create['id'], null, $create['name'], null, null, json_encode($create['values'], JSON_UNESCAPED_UNICODE), $parent->name);

        if (! $this->option('apply')) {
            return;
        }

        $values = $create['values'];
        $values[$key] = $parent->id;

        // A room approved with its listing there is only approved here if this listing is too.
        if (($values['status'] ?? null) === 'approved' && $parent->status !== 'approved') {
            $values['status'] = 'pending';
            $values['approved_at'] = null;
            $values['approved_by'] = null;
        } elseif (($values['approved_by'] ?? null) !== null) {
            $values['approved_by'] = DB::table('users')->where('id', $values['approved_by'])->exists() ? $values['approved_by'] : null;
            $values['approved_at'] = now();
        }

        $snapshot = array_diff_key($values, array_flip(['approved_at']));
        $values['created_at'] = $values['updated_at'] = now();

        if (! DB::table($table)->where('id', $create['id'])->exists()) {
            $values['id'] = $create['id'];
        }

        $id = DB::table($table)->insertGetId($values);
        $this->remember($table, $values['id'] ?? $id, FieldProvenance::WHOLE_RECORD, FieldProvenance::GENERATED, json_encode($snapshot, JSON_UNESCAPED_UNICODE), null, $batch);
    }

    /** A listing that gained its own rooms here does not also get the generated ones. */
    private function listingHasOtherRooms(string $table, string $key, int $parentId, string $batch): bool
    {
        $query = DB::table($table)->where($key, $parentId);

        if (Schema::hasTable('field_provenance')) {
            $query->whereNotIn('id', FieldProvenance::where(['record_table' => $table, 'batch' => $batch, 'field' => FieldProvenance::WHOLE_RECORD])->pluck('record_id'));
        }

        return $query->exists();
    }

    private function rollback(string $batch): int
    {
        $tally = ['restored' => 0, 'removed' => 0, 'verified' => 0, 'edited' => 0, 'in_use' => 0];

        DB::transaction(function () use ($batch, &$tally) {
            foreach (FieldProvenance::where('batch', $batch)->orderByDesc('id')->get() as $entry) {
                $row = DB::table($entry->record_table)->find($entry->record_id);

                if ($entry->source === FieldProvenance::VERIFIED) {
                    $tally['verified']++;
                    $this->note('kept_verified', $entry->record_table, $entry->record_id, $entry->record_id, '', $entry->field, $entry->recorded_value, null);
                } elseif (! $row) {
                    if ($this->option('apply')) {
                        $entry->delete();
                    }
                } elseif ($entry->field === FieldProvenance::WHOLE_RECORD) {
                    $this->rollbackRoom($entry, $row, $tally);
                } elseif ($this->same($row->{$entry->field}, $entry->recorded_value)) {
                    $tally['restored']++;
                    $this->note('restored', $entry->record_table, $entry->record_id, $entry->record_id, (string) ($row->name ?? ''), $entry->field, $row->{$entry->field}, $entry->previous_value);
                    if ($this->option('apply')) {
                        DB::table($entry->record_table)->where('id', $entry->record_id)->update([$entry->field => $entry->previous_value]);
                        $entry->delete();
                    }
                } else {
                    $tally['edited']++;
                    $this->note('kept_edited_since_import', $entry->record_table, $entry->record_id, $entry->record_id, (string) ($row->name ?? ''), $entry->field, $row->{$entry->field}, null);
                }
            }
        });

        foreach ($this->outcomes as $outcome) {
            if (str_starts_with($outcome[0], 'kept')) {
                $this->line("  {$outcome[0]}: {$outcome[1]} #{$outcome[2]} {$outcome[5]}");
            }
        }

        $this->table(['Fields restored', 'Rooms removed', 'Kept: verified', 'Kept: edited since import', 'Kept: room in use'], [array_values($tally)]);
        $this->writeReport();

        return self::SUCCESS;
    }

    private function rollbackRoom(FieldProvenance $entry, object $row, array &$tally): void
    {
        if ($this->roomInUse($entry->record_id)) {
            $tally['in_use']++;
            $this->note('kept_room_in_use', $entry->record_table, $entry->record_id, $entry->record_id, (string) $row->name, $entry->field, null, null);

            return;
        }
        if ($this->roomEdited($entry, $row)) {
            $tally['edited']++;
            $this->note('kept_edited_since_import', $entry->record_table, $entry->record_id, $entry->record_id, (string) $row->name, $entry->field, null, null);

            return;
        }

        $tally['removed']++;
        $this->note('room_removed', $entry->record_table, $entry->record_id, $entry->record_id, (string) $row->name, $entry->field, null, null);

        if ($this->option('apply')) {
            DB::table($entry->record_table)->where('id', $entry->record_id)->delete();
            $entry->delete();
        }
    }

    private function roomInUse(int $id): bool
    {
        return DB::table('itinerary_rooms')->where('accommodation_room_id', $id)->exists()
            || DB::table('accommodation_room_images')->where('room_id', $id)->exists();
    }

    private function roomEdited(FieldProvenance $entry, object $row): bool
    {
        if ((string) $row->updated_at !== (string) $row->created_at) {
            return true;
        }

        foreach ((array) json_decode((string) $entry->recorded_value, true) as $field => $value) {
            if (! $this->same($row->$field ?? null, is_array($value) ? json_encode($value) : $value)) {
                return true;
            }
        }

        return false;
    }

    /** The record with this id and name; otherwise the only one with that name under the same parent. */
    private function findRecord(array $record): ?object
    {
        $row = $this->findExact($record['table'], $record['id'], $record['name']);
        if ($row) {
            return $row;
        }

        if (empty($record['parent'])) {
            return $this->findUniqueByName($record['table'], $record['name']);
        }

        $parent = $this->findExact($record['parent']['table'], $record['parent']['id'], $record['parent']['name'])
            ?? $this->findUniqueByName($record['parent']['table'], $record['parent']['name']);
        if (! $parent) {
            return null;
        }

        $matches = DB::table($record['table'])->where($record['parent']['key'], $parent->id)->where('name', $record['name'])->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function findExact(string $table, int $id, string $name): ?object
    {
        $row = DB::table($table)->find($id);

        return $row && mb_strtolower(trim((string) $row->name)) === mb_strtolower(trim($name)) ? $row : null;
    }

    private function findUniqueByName(string $table, string $name): ?object
    {
        $matches = DB::table($table)->where('name', $name)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function isProtected(string $table, object $row): bool
    {
        $this->bulanDestinations ??= DB::table('destinations')
            ->where('municipality_id', Municipality::where('name', 'Bulan')->value('id'))
            ->pluck('id')->all();

        return match ($table) {
            'destinations' => in_array($row->id, $this->bulanDestinations),
            'activities' => in_array($row->destination_id, $this->bulanDestinations),
            default => false,
        };
    }

    /** A missing column, a too-long value or a value outside an enum would fail on MySQL, so they stop the import first. */
    private function fits(string $table, string $field, mixed $value, int $id): bool
    {
        $this->columns[$table] ??= collect(Schema::getColumns($table))->pluck('type', 'name')->all();
        $type = $this->columns[$table][$field] ?? null;

        $problem = match (true) {
            $type === null => 'column does not exist on this database',
            preg_match('/char\((\d+)\)/i', $type, $m) === 1 && mb_strlen((string) $value) > (int) $m[1] => "value is longer than {$type}",
            preg_match('/^enum\((.*)\)/i', $type, $m) === 1 && $value !== null && ! in_array("'{$value}'", explode(',', $m[1]), true) => "value is not allowed by {$type}",
            default => null,
        };

        if ($problem !== null) {
            $this->problems[] = "{$table} #{$id} {$field}: {$problem}.";
            $this->note('schema_problem', $table, $id, $id, '', $field, null, is_scalar($value) ? $value : json_encode($value), $problem);

            return false;
        }

        return true;
    }

    /** An existing row is left alone, so a repeat run keeps the true earlier value and any verification. */
    private function remember(string $table, int $id, string $field, string $source, mixed $value, mixed $previous, string $batch): void
    {
        FieldProvenance::firstOrCreate(
            ['record_table' => $table, 'record_id' => $id, 'field' => $field],
            ['source' => $source, 'recorded_value' => $value, 'previous_value' => $previous, 'batch' => $batch],
        );
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || in_array(trim((string) $value), ['', '[]'], true);
    }

    private function same(mixed $a, mixed $b): bool
    {
        if ($this->isBlank($a) || $this->isBlank($b)) {
            return $this->isBlank($a) && $this->isBlank($b);
        }
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        $decodedA = json_decode((string) $a, true);
        $decodedB = json_decode((string) $b, true);
        if (is_array($decodedA) && is_array($decodedB)) {
            return $decodedA === $decodedB;
        }

        return trim((string) $a) === trim((string) $b);
    }

    private function note(string $outcome, string $table, int $manifestId, ?int $targetId, string $name, ?string $field, mixed $existing, mixed $new, string $detail = ''): void
    {
        $this->outcomes[] = [$outcome, $table, $manifestId, $targetId, $name, $field, $existing, $new, $detail];
    }

    private function printSummary(): void
    {
        $c = $this->counts;

        $this->table(['Check', 'Count'], [
            ['Records matched (same id and name)', $c['records_matched']],
            ['Records matched by name (different id)', $c['records_matched_by_name']],
            ['Records not found', $c['records_not_found']],
            [$this->option('apply') ? 'Fields written' : 'Fields eligible for import', $c['fields_eligible']],
            ['Fields skipped: already this value', $c['fields_already_same']],
            ['Fields skipped: record not found', $c['fields_in_missing_records']],
            ['Fields skipped: protected (Bulan)', $c['fields_protected']],
            ['Conflicts: existing values preserved', $c['conflicts_preserved']],
            [$this->option('apply') ? 'New room types created' : 'New room types to be created', $c['rooms_new']],
            ['Room types already present', $c['rooms_existing']],
            ['Room types skipped: listing has its own rooms', $c['rooms_listing_has_own_rooms']],
            ['Room types skipped: listing not found', $c['rooms_no_parent']],
            ['Schema or column-size problems', count($this->problems)],
        ]);

        $byTable = collect($this->outcomes)->groupBy(1)->map(fn ($rows, $table) => [
            $table,
            $rows->where(0, 'eligible')->count() + $rows->where(0, 'new_room')->count(),
            $rows->where(0, 'already_same')->count() + $rows->where(0, 'room_already_exists')->count(),
            $rows->where(0, 'record_not_found')->count() + $rows->where(0, 'room_no_parent_listing')->count(),
            $rows->where(0, 'conflict_existing_preserved')->count() + $rows->where(0, 'room_skipped_listing_has_own_rooms')->count(),
            $rows->where(0, 'schema_problem')->count(),
        ])->values()->all();

        $this->table(['Table', 'Eligible', 'Already', 'Not found', 'Preserved', 'Schema'], $byTable);
    }

    private function writeReport(): void
    {
        if (! $this->option('report')) {
            return;
        }

        $handle = fopen($this->option('report'), 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::REPORT_HEADINGS, ',', '"', '');
        foreach ($this->outcomes as $outcome) {
            fputcsv($handle, $outcome, ',', '"', '');
        }
        fclose($handle);

        $this->info("Report: {$this->option('report')} (".count($this->outcomes).' rows)');
    }

    private function abort(): int
    {
        foreach ($this->problems as $problem) {
            $this->error($problem);
        }
        $this->error('Stopped. Nothing was written.');

        return self::FAILURE;
    }
}

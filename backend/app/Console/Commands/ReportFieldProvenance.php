<?php

namespace App\Console\Commands;

use App\Models\FieldProvenance;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Shows how many listing fields are generated or verified, and marks fields as verified once
 * someone has confirmed them. Everything with no provenance row is original data.
 */
class ReportFieldProvenance extends Command
{
    protected $signature = 'data:provenance
        {--verify= : Mark as verified: table:id for a whole record, or table:id:field}
        {--by= : Email of the person who confirmed the value}';

    protected $description = 'Report generated and verified listing fields, or mark them verified';

    public function handle(): int
    {
        return $this->option('verify') ? $this->verify((string) $this->option('verify')) : $this->report();
    }

    private function report(): int
    {
        $rows = [];

        foreach (FieldProvenance::where('field', '!=', FieldProvenance::WHOLE_RECORD)->get()->groupBy('record_table') as $table => $entries) {
            $current = DB::table($table)->whereIn('id', $entries->pluck('record_id')->unique())->get()->keyBy('id');
            $generated = $entries->where('source', FieldProvenance::GENERATED);
            $edited = $generated->filter(function ($entry) use ($current) {
                $row = $current[$entry->record_id] ?? null;

                return $row && (string) $row->{$entry->field} !== (string) $entry->recorded_value;
            })->count();

            $rows[] = [$table, $generated->count() - $edited, $edited, $entries->where('source', FieldProvenance::VERIFIED)->count(), $entries->where('source', FieldProvenance::ADMIN_DECISION)->count()];
        }

        $this->table(['Table', 'Generated', 'Generated, since edited', 'Verified', 'Admin decisions'], $rows);
        $this->line('Rooms created by import: '.FieldProvenance::where('field', FieldProvenance::WHOLE_RECORD)->count().'. Fields with no row are original data.');

        return self::SUCCESS;
    }

    private function verify(string $target): int
    {
        [$table, $id, $field] = array_pad(explode(':', $target, 3), 3, null);

        if (! isset(ImportDataEnrichment::FIELDS[$table]) || ! ctype_digit((string) $id) || ! DB::table($table)->where('id', $id)->exists()) {
            $this->error("No such record: {$target}");

            return self::FAILURE;
        }
        if ($field !== null && ! in_array($field, ImportDataEnrichment::FIELDS[$table], true)) {
            $this->error("{$field} is not a tracked field of {$table}.");

            return self::FAILURE;
        }

        $by = $this->option('by') ? User::where('email', $this->option('by'))->value('id') : null;
        if ($this->option('by') && ! $by) {
            $this->error('No user has that email.');

            return self::FAILURE;
        }

        $marks = ['source' => FieldProvenance::VERIFIED, 'verified_by' => $by, 'verified_at' => now()];

        if ($field !== null) {
            FieldProvenance::updateOrCreate(
                ['record_table' => $table, 'record_id' => $id, 'field' => $field],
                $marks + ['recorded_value' => DB::table($table)->where('id', $id)->value($field)],
            );
            $count = 1;
        } else {
            $count = FieldProvenance::where(['record_table' => $table, 'record_id' => $id, 'source' => FieldProvenance::GENERATED])
                ->where('field', '!=', FieldProvenance::WHOLE_RECORD)
                ->update($marks);
        }

        $this->info("{$count} field(s) marked verified.");

        return self::SUCCESS;
    }
}

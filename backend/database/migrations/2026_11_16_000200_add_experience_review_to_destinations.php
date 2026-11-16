<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2C, step 3 of 4: whether an admin has reviewed a destination's experience tags, who and
 * when. Both columns are nullable and no existing destination is given a value, so all of them
 * stay "not reviewed".
 *
 * Safe to retry: each column, the index and the foreign key is its own statement on MySQL/TiDB.
 * Columns already present are checked (type, nullability), the missing parts are added, and a
 * column or key that differs stops the migration with a clear error
 * (docs/deployment/phase-2c-migration-recovery.md).
 */
return new class extends Migration
{
    private function structure(): array
    {
        return [
            'table' => 'destinations',
            // Only these columns are checked: the rest of the table isn't this migration's.
            'columns' => [
                'experiences_reviewed_at' => ['kind' => 'timestamp', 'nullable' => true],
                'experiences_reviewed_by' => ['kind' => 'bigint', 'unsigned' => true, 'nullable' => true],
            ],
            'indexes' => [
                ['name' => 'destinations_experiences_reviewed_at_index', 'columns' => ['experiences_reviewed_at'], 'unique' => false],
            ],
            'foreign' => [
                ['column' => 'experiences_reviewed_by', 'on' => 'users', 'references' => 'id', 'on_delete' => 'set null'],
            ],
        ];
    }

    public function up(): void
    {
        $missing = SchemaGuard::missing($this->structure());

        if (SchemaGuard::hasMissing($missing)) {
            Schema::table('destinations', function (Blueprint $table) use ($missing) {
                if (in_array('experiences_reviewed_at', $missing['columns'], true)) {
                    $table->timestamp('experiences_reviewed_at')->nullable()->after('setting');
                }
                if (in_array('experiences_reviewed_by', $missing['columns'], true)) {
                    $table->unsignedBigInteger('experiences_reviewed_by')->nullable()->after('experiences_reviewed_at');
                }
                SchemaGuard::addMissingKeys($table, $missing);
            });
        }

        SchemaGuard::assertComplete($this->structure());
    }

    public function down(): void
    {
        // Only what this migration adds, and only what is there (an interrupted run may have left part of it).
        $foreign = collect(Schema::getForeignKeys('destinations'))->filter(fn ($f) => $f['columns'] === ['experiences_reviewed_by']);
        if ($foreign->isNotEmpty()) {
            // By its actual name; SQLite (the test suite) can only drop a foreign key by its columns.
            $sqlite = Schema::getConnection()->getDriverName() === 'sqlite';
            Schema::table('destinations', fn (Blueprint $table) => $foreign->each(fn ($f) => $table->dropForeign($sqlite ? $f['columns'] : $f['name'])));
        }
        // Drop their indexes before the columns: some TiDB versions refuse to drop an indexed column.
        $indexes = collect(Schema::getIndexes('destinations'))
            ->filter(fn ($i) => ! $i['primary'] && array_intersect($i['columns'], ['experiences_reviewed_at', 'experiences_reviewed_by']) !== []);
        if ($indexes->isNotEmpty()) {
            Schema::table('destinations', fn (Blueprint $table) => $indexes->each(fn ($i) => $table->dropIndex($i['name'])));
        }
        $columns = array_values(array_filter(['experiences_reviewed_at', 'experiences_reviewed_by'], fn ($c) => Schema::hasColumn('destinations', $c)));
        if ($columns !== []) {
            Schema::table('destinations', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};

<?php

namespace App\Support\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Structural checks for migrations that must be safe to retry after a partial failure (MySQL and
 * TiDB don't roll back DDL, so a failed migration can leave some of its objects behind without
 * being recorded as run). A migration describes the structure it creates; this class then reports
 * what is missing, so the migration can add exactly that, and stops with a clear error when an
 * existing object doesn't match: it never accepts an incomplete or different object silently.
 *
 * Structure array:
 *   'table'   => name
 *   'columns' => [name => ['kind' => bigint|smallint|string|timestamp, 'nullable' => bool,
 *                          'unsigned' => bool, 'length' => int, 'auto_increment' => bool]]
 *   'exact'   => true when the table must have exactly these columns (a table this migration owns)
 *   'primary' => [columns]
 *   'indexes' => [['name' => …, 'columns' => […], 'unique' => bool]]
 *   'foreign' => [['column' => …, 'on' => table, 'references' => column, 'on_delete' => cascade|restrict|set null]]
 *
 * Recovery steps: docs/deployment/phase-2c-migration-recovery.md.
 */
class SchemaGuard
{
    public const RECOVERY_DOC = 'docs/deployment/phase-2c-migration-recovery.md';

    /** Column types each kind may be reported as, by database driver. */
    private const TYPES = [
        'mysql' => ['bigint' => ['bigint'], 'smallint' => ['smallint'], 'string' => ['varchar'], 'timestamp' => ['timestamp']],
        // SQLite (the test suite) stores integers as INTEGER and timestamps as DATETIME.
        'sqlite' => ['bigint' => ['integer', 'bigint'], 'smallint' => ['integer', 'smallint'], 'string' => ['varchar'], 'timestamp' => ['datetime', 'timestamp']],
    ];

    /**
     * What the existing table lacks: ['columns' => [names], 'indexes' => [specs], 'foreign' => [specs]].
     * Throws, before anything is changed, when something present doesn't match the structure.
     */
    public static function missing(array $structure): array
    {
        $table = $structure['table'];
        $problems = [];

        $existing = collect(Schema::getColumns($table))->keyBy('name');
        $missingColumns = [];
        foreach ($structure['columns'] as $name => $expected) {
            if (! $existing->has($name)) {
                $missingColumns[] = $name;

                continue;
            }
            if ($problem = self::columnProblem($existing[$name], $expected)) {
                $problems[] = "column {$name} {$problem}";
            }
        }
        if (($structure['exact'] ?? false) && ($extra = $existing->keys()->diff(array_keys($structure['columns']))->values()->all())) {
            $problems[] = 'unexpected column(s): '.implode(', ', $extra);
        }
        if (($structure['exact'] ?? false) && $missingColumns !== []) {
            // A table this migration creates gets all its columns in one CREATE statement.
            $problems[] = 'missing column(s) '.implode(', ', $missingColumns).' (this table was not created by this migration)';
        }

        $indexes = Schema::getIndexes($table);
        if (isset($structure['primary'])) {
            $primary = collect($indexes)->firstWhere('primary', true);
            if (! $primary || $primary['columns'] !== $structure['primary']) {
                $problems[] = 'primary key is not ('.implode(', ', $structure['primary']).')';
            }
        }

        $missingIndexes = [];
        foreach ($structure['indexes'] ?? [] as $index) {
            $byName = collect($indexes)->first(fn ($i) => strcasecmp($i['name'], $index['name']) === 0);
            if ($byName && ($byName['columns'] !== $index['columns'] || (bool) $byName['unique'] !== $index['unique'])) {
                $problems[] = "index {$index['name']} is on (".implode(', ', $byName['columns']).')'.($byName['unique'] ? ' unique' : '')
                    .', expected ('.implode(', ', $index['columns']).')'.($index['unique'] ? ' unique' : '');

                continue;
            }
            $equivalent = collect($indexes)->first(fn ($i) => $i['columns'] === $index['columns'] && (bool) $i['unique'] === $index['unique']);
            if (! $byName && ! $equivalent) {
                $missingIndexes[] = $index;
            }
        }

        $foreignKeys = Schema::getForeignKeys($table);
        $missingForeign = [];
        foreach ($structure['foreign'] ?? [] as $foreign) {
            $onColumn = collect($foreignKeys)->filter(fn ($f) => $f['columns'] === [$foreign['column']]);
            if ($onColumn->isEmpty()) {
                $missingForeign[] = $foreign;

                continue;
            }
            $matching = $onColumn->first(fn ($f) => strcasecmp($f['foreign_table'], $foreign['on']) === 0
                && $f['foreign_columns'] === [$foreign['references']]
                && self::rule($f['on_delete']) === $foreign['on_delete']);
            if (! $matching) {
                $f = $onColumn->first();
                $problems[] = "foreign key on {$foreign['column']} references {$f['foreign_table']}(".implode(', ', $f['foreign_columns']).') on delete '.self::rule($f['on_delete'])
                    .", expected {$foreign['on']}({$foreign['references']}) on delete {$foreign['on_delete']}";
            }
        }

        if ($problems !== []) {
            self::fail($table, $problems);
        }

        return ['columns' => $missingColumns, 'indexes' => $missingIndexes, 'foreign' => $missingForeign];
    }

    /**
     * Adds the indexes and foreign keys missing() reported, with the same names and rules the
     * original definition gives them (foreign keys keep Laravel's "{table}_{column}_foreign" name).
     */
    public static function addMissingKeys(Blueprint $table, array $missing): void
    {
        foreach ($missing['indexes'] as $index) {
            $index['unique'] ? $table->unique($index['columns'], $index['name']) : $table->index($index['columns'], $index['name']);
        }
        foreach ($missing['foreign'] as $foreign) {
            $table->foreign($foreign['column'])->references($foreign['references'])->on($foreign['on'])->onDelete($foreign['on_delete']);
        }
    }

    /** Whether missing() found anything to add. */
    public static function hasMissing(array $missing): bool
    {
        return $missing['columns'] !== [] || $missing['indexes'] !== [] || $missing['foreign'] !== [];
    }

    /** Throws unless the table now has the whole structure (run after creating or completing it). */
    public static function assertComplete(array $structure): void
    {
        $missing = self::missing($structure);
        $parts = array_merge(
            $missing['columns'],
            array_map(fn ($i) => "index {$i['name']}", $missing['indexes']),
            array_map(fn ($f) => "foreign key on {$f['column']}", $missing['foreign']),
        );
        if ($parts !== []) {
            self::fail($structure['table'], ['still missing after this migration ran: '.implode(', ', $parts)
                .'. The database accepted the statements but did not create these objects; check that it supports them (for TiDB: foreign keys need tidb_enable_foreign_key and v6.6+)'], true);
        }
    }

    private static function columnProblem(array $actual, array $expected): ?string
    {
        $driver = Schema::getConnection()->getDriverName() === 'sqlite' ? 'sqlite' : 'mysql';
        $type = strtolower((string) $actual['type_name']);
        $full = strtolower((string) $actual['type']);

        if (! in_array($type, self::TYPES[$driver][$expected['kind']], true)) {
            return "is {$full}, expected {$expected['kind']}";
        }
        if ((bool) $actual['nullable'] !== (bool) ($expected['nullable'] ?? false)) {
            return ($actual['nullable'] ? 'allows' : 'does not allow').' NULL, expected '.(($expected['nullable'] ?? false) ? 'nullable' : 'NOT NULL');
        }
        if ($driver === 'mysql') {
            if (isset($expected['unsigned']) && str_contains($full, 'unsigned') !== $expected['unsigned']) {
                return "is {$full}, expected ".($expected['unsigned'] ? 'unsigned' : 'signed');
            }
            if (isset($expected['length']) && preg_match('/\((\d+)\)/', $full, $m) && (int) $m[1] !== $expected['length']) {
                return "is {$full}, expected length {$expected['length']}";
            }
        }
        if (! empty($expected['auto_increment']) && empty($actual['auto_increment'])) {
            return 'is not auto-incrementing';
        }

        return null;
    }

    private static function rule(?string $rule): string
    {
        return strtolower(trim((string) $rule));
    }

    private static function fail(string $table, array $problems, bool $changed = false): never
    {
        throw new RuntimeException("Table {$table} exists but does not have the expected structure: ".implode('; ', $problems)
            .($changed ? '. The objects this migration did create were left in place.' : '. Nothing was changed by this migration.')
            .' Inspect and fix the table as described in '.self::RECOVERY_DOC
            .' (section 4, "Resuming, and handling an incomplete or different object"), then run the migrations again.');
    }
}

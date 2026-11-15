<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2C, step 2 of 4: which experience tags a destination has. Starts empty: no destination
 * is tagged by any migration. Tags go with their destination; a tag still in use can't be deleted.
 *
 * Safe to retry: on MySQL/TiDB the table, its foreign keys and its indexes are separate
 * statements, so an interrupted run can leave the table without some of them. An existing table
 * with exactly these columns is completed; anything different stops the migration with a clear
 * error (docs/deployment/phase-2c-migration-recovery.md).
 */
return new class extends Migration
{
    private function structure(): array
    {
        return [
            'table' => 'destination_experience_tag',
            'exact' => true,
            'columns' => [
                'id' => ['kind' => 'bigint', 'unsigned' => true, 'auto_increment' => true],
                'destination_id' => ['kind' => 'bigint', 'unsigned' => true],
                'experience_tag_id' => ['kind' => 'bigint', 'unsigned' => true],
                'created_at' => ['kind' => 'timestamp', 'nullable' => true],
                'updated_at' => ['kind' => 'timestamp', 'nullable' => true],
            ],
            'primary' => ['id'],
            'indexes' => [
                // Named: the generated name is longer than MySQL/TiDB's 64-character limit.
                ['name' => 'destination_experience_tag_unique', 'columns' => ['destination_id', 'experience_tag_id'], 'unique' => true],
                ['name' => 'destination_experience_tag_experience_tag_id_index', 'columns' => ['experience_tag_id'], 'unique' => false],
            ],
            'foreign' => [
                ['column' => 'destination_id', 'on' => 'destinations', 'references' => 'id', 'on_delete' => 'cascade'],
                ['column' => 'experience_tag_id', 'on' => 'experience_tags', 'references' => 'id', 'on_delete' => 'restrict'],
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('destination_experience_tag')) {
            Schema::create('destination_experience_tag', function (Blueprint $table) {
                $table->id();
                $table->foreignId('destination_id')->constrained()->cascadeOnDelete();
                $table->foreignId('experience_tag_id')->constrained()->restrictOnDelete();
                $table->timestamps();
                $table->unique(['destination_id', 'experience_tag_id'], 'destination_experience_tag_unique');
                $table->index('experience_tag_id');
            });
        } else {
            $missing = SchemaGuard::missing($this->structure());
            if (SchemaGuard::hasMissing($missing)) {
                Schema::table('destination_experience_tag', fn (Blueprint $table) => SchemaGuard::addMissingKeys($table, $missing));
            }
        }

        SchemaGuard::assertComplete($this->structure());
    }

    public function down(): void
    {
        Schema::dropIfExists('destination_experience_tag');
    }
};

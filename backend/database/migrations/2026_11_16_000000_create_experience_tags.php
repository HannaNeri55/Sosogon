<?php

use App\Support\Migrations\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2C, step 1 of 4: the experience tag vocabulary table
 * (docs/data-quality/classification-design-2026-11-15.md).
 *
 * Steps 2–4 are separate migrations, so each is recorded as soon as it succeeds and a failure
 * leaves as little as possible to recover (docs/deployment/phase-2c-migration-recovery.md).
 * Safe to retry: an existing table is checked against the structure below and completed, or
 * the migration stops with a clear error when it differs. The vocabulary itself is step 4.
 */
return new class extends Migration
{
    private function structure(): array
    {
        return [
            'table' => 'experience_tags',
            'exact' => true,
            'columns' => [
                'id' => ['kind' => 'bigint', 'unsigned' => true, 'auto_increment' => true],
                'slug' => ['kind' => 'string', 'length' => 40],
                'name' => ['kind' => 'string', 'length' => 60],
                'group' => ['kind' => 'string', 'length' => 40],
                'description' => ['kind' => 'string', 'length' => 255],
                'sort_order' => ['kind' => 'smallint', 'unsigned' => true],
                'created_at' => ['kind' => 'timestamp', 'nullable' => true],
                'updated_at' => ['kind' => 'timestamp', 'nullable' => true],
            ],
            'primary' => ['id'],
            'indexes' => [
                ['name' => 'experience_tags_slug_unique', 'columns' => ['slug'], 'unique' => true],
            ],
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('experience_tags')) {
            Schema::create('experience_tags', function (Blueprint $table) {
                $table->id();
                // Stable identifier used by the API and, later, by the planner's interest rules.
                $table->string('slug', 40)->unique();
                $table->string('name', 60);
                $table->string('group', 40);
                $table->string('description', 255);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
            });
        } else {
            // Left by an earlier, interrupted run: add only what is missing, or stop if it differs.
            $missing = SchemaGuard::missing($this->structure());
            if (SchemaGuard::hasMissing($missing)) {
                Schema::table('experience_tags', fn (Blueprint $table) => SchemaGuard::addMissingKeys($table, $missing));
            }
        }

        SchemaGuard::assertComplete($this->structure());
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_tags');
    }
};

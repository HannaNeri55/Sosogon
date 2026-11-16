<?php

use Database\Seeders\ExperienceTagSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 2C, step 4 of 4: the 18 vocabulary entries, here because the live server only migrates
 * (it never runs db:seed). Data only, kept apart from the schema steps so a failure here (for
 * example a dropped connection) is retried by simply migrating again: the seeder adds only the
 * tags that are missing and never changes existing ones or any destination.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new ExperienceTagSeeder)->run();
    }

    public function down(): void
    {
        // Nothing to undo on its own: the vocabulary goes with the experience_tags table (step 1),
        // and deleting tags here could break destinations that use them.
    }
};

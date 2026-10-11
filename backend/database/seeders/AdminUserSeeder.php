<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Skipped in production — this seeds a known personal dev account with a
     * known password, which must never end up live on a production database.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Skipping AdminUserSeeder in production. Create the initial admin account manually.');

            return;
        }

        $password = config('app.admin_seed_password');
        if (blank($password)) {
            $this->command?->warn('Skipping AdminUserSeeder: set ADMIN_SEED_PASSWORD in .env first.');

            return;
        }

        DB::table('users')->updateOrInsert(
            ['email' => 'forgalidaddiether@gmail.com'],
            [
                'role_id' => 1,
                'name' => 'Diether B. Forgalidad',
                'password' => Hash::make($password),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}

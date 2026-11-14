<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Development and testing only: one provincial-scope admin account for trying the provincial
 * side of the admin area (and the defense), next to a municipal one. It is a test account, never
 * a real office's: its email is at example.com (which never receives mail) and it is not part of
 * DatabaseSeeder, so it is only created when run by name:
 *
 *   TEST_PROVINCIAL_ADMIN_PASSWORD="…" php artisan db:seed --class=TestProvincialAdminSeeder
 *   TEST_PROVINCIAL_ADMIN=remove php artisan db:seed --class=TestProvincialAdminSeeder
 *
 * It refuses to run in production, never touches another account (an existing non-admin with this
 * email stops it), and takes its password from TEST_PROVINCIAL_ADMIN_PASSWORD, never from code.
 */
class TestProvincialAdminSeeder extends Seeder
{
    public const EMAIL = 'provincial.test@example.com';

    private const NAME = 'Provincial Administrator';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('TestProvincialAdminSeeder is for development and testing only; it does not run in production.');
        }

        $existing = User::where('email', self::EMAIL)->first();
        if ($existing && ! $existing->isAdmin()) {
            throw new RuntimeException(self::EMAIL.' belongs to a non-admin account; nothing was changed.');
        }

        if (env('TEST_PROVINCIAL_ADMIN') === 'remove') {
            if ($existing) {
                $existing->tokens()->delete();
                $existing->delete();
            }
            $this->command?->info($existing ? 'Removed the test provincial admin account.' : 'There was no test provincial admin account.');

            return;
        }

        $password = (string) env('TEST_PROVINCIAL_ADMIN_PASSWORD', '');
        if ($password === '' && ! $existing) {
            throw new RuntimeException('Set TEST_PROVINCIAL_ADMIN_PASSWORD (it must meet the password rules) to create the test provincial admin.');
        }
        if ($password !== '') {
            $check = Validator::make(['password' => $password], ['password' => [new StrongPassword]]);
            if ($check->fails()) {
                throw new RuntimeException('TEST_PROVINCIAL_ADMIN_PASSWORD: '.$check->errors()->first('password'));
            }
        }

        $user = $existing ?? new User;
        $user->fill(['name' => self::NAME, 'email' => self::EMAIL, 'status' => 'active'] + ($password !== '' ? ['password' => $password] : []));
        $user->forceFill([
            'role_id' => Role::where('name', 'admin')->value('id') ?? throw new RuntimeException('The admin role is missing; run RoleSeeder first.'),
            'admin_scope' => User::ADMIN_SCOPE_PROVINCIAL,
            'municipality_id' => null,
            'admin_title' => null,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->command?->info(($existing ? 'Updated' : 'Created').' the test provincial admin: '.self::EMAIL.' (password from TEST_PROVINCIAL_ADMIN_PASSWORD; not shown).');
    }
}

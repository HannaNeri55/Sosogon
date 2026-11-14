<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ManagesAdminScope;
use App\Models\Role;
use App\Models\User;
use App\Rules\StrongPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Creates an admin account with an explicit scope: provincial, or municipal with its
 * municipality. Every detail comes from the command line; the password is typed at a hidden
 * prompt (never an option, so it stays out of shell history and logs) and follows the same rules
 * as registration. Shows the account first, asks for confirmation and logs the creation.
 *
 *   php artisan admin:create maria@example.com --name="Maria Santos" --municipality=Bulan --dry-run
 *   php artisan admin:create maria@example.com --name="Maria Santos" --municipality=Bulan --title="Bulan Tourism Office"
 *   php artisan admin:create juan@example.com --name="Juan Cruz" --provincial
 */
class CreateAdmin extends Command
{
    use ManagesAdminScope;

    protected $signature = 'admin:create
        {email : The new admin\'s email address}
        {--name= : Their full name}
        {--provincial : Manage every municipality}
        {--municipality= : Manage only this municipality (its id or exact name)}
        {--title= : Designation shown in the admin area (default: from the scope)}
        {--phone= : Their contact number (optional)}
        {--dry-run : Show the account without creating it}';

    protected $description = 'Create an admin account with a provincial or municipal scope';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $name = trim((string) $this->option('name'));
        $phone = $this->option('phone') !== null ? trim((string) $this->option('phone')) : null;

        $details = Validator::make(compact('email', 'name', 'phone'), [
            'email' => ['required', 'string', 'email:filter', 'max:255', function ($attribute, $value, $fail) {
                if (User::whereRaw('LOWER(email) = ?', [mb_strtolower($value)])->exists()) {
                    $fail('An account with this email already exists. To change an existing admin, use admin:assign.');
                }
            }],
            'name' => 'required|string|min:2|max:100',
            'phone' => 'nullable|string|max:20',
        ], ['name.required' => 'Give the admin\'s full name with --name="…".']);
        if ($details->fails()) {
            foreach ($details->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $scope = $this->requestedScope();
        $title = $this->requestedTitle();
        if ($scope === false || $title === false) {
            return self::FAILURE;
        }
        if ($scope === null) {
            $this->error('Choose the admin\'s scope: --provincial, or --municipality=<name or id>. Accounts are never created without one.');

            return self::FAILURE;
        }
        [$scopeName, $municipality] = $scope;

        $role = Role::where('name', 'admin')->first();
        if (! $role) {
            $this->error('The admin role is missing; nothing was created.');

            return self::FAILURE;
        }

        $preview = new User(['name' => $name]);
        $preview->forceFill(['admin_scope' => $scopeName, 'municipality_id' => $municipality?->id, 'admin_title' => $title])
            ->setRelation('role', $role)->setRelation('municipality', $municipality);
        $this->table(['New admin account', ''], [
            ['Name', $name],
            ['Email', $email],
            ['Phone', $phone ?: '(none)'],
            ['Role', 'admin'],
            ['Scope', $this->describeScope($scopeName, $municipality)],
            ['Designation', $preview->adminProfile()['designation']],
        ]);
        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was created, and no password was asked for.');

            return self::SUCCESS;
        }

        $password = $this->secret('Password for the new admin (hidden; at least 8 characters with upper and lower case, a number and a symbol)');
        if (! is_string($password) || $password === '') {
            $this->error('A password is required (run this in an interactive terminal); nothing was created.');

            return self::FAILURE;
        }
        $check = Validator::make(['password' => $password], ['password' => [new StrongPassword]]);
        if ($check->fails()) {
            $this->error($check->errors()->first('password').' Nothing was created.');

            return self::FAILURE;
        }
        if ($this->secret('Type the password again') !== $password) {
            $this->error('The passwords do not match; nothing was created.');

            return self::FAILURE;
        }
        if (! $this->confirmChange("Create this admin account for {$name} <{$email}>?", $municipality)) {
            $this->warn('Not confirmed: nothing was created.');

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($role, $name, $email, $phone, $password, $scopeName, $municipality, $title) {
            $user = User::create([
                'role_id' => $role->id, 'name' => $name, 'email' => $email, 'phone' => $phone ?: null,
                'password' => $password, 'status' => 'active',
            ]);
            $user->forceFill([
                'email_verified_at' => now(),
                'admin_scope' => $scopeName,
                'municipality_id' => $municipality?->id,
                'admin_title' => $title,
            ])->save();
            $this->logScopeChange($user, 'admin_created', 'New account', $this->describeScope($scopeName, $municipality), [
                'scope' => $scopeName, 'municipality_id' => $municipality?->id, 'admin_title' => $title,
            ]);

            return $user;
        });

        $this->info("Created admin #{$user->id} {$user->name} <{$user->email}>: {$this->describeScope($scopeName, $municipality)}. They can sign in at /login/admin.");

        return self::SUCCESS;
    }
}

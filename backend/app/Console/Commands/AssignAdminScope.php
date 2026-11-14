<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ManagesAdminScope;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Sets an existing admin account's authority (App\Support\AdminScope): provincial (every
 * municipality) or municipal (one municipality), and/or its designation. Shows the account and
 * the change first, asks for confirmation (a municipal assignment also asks for the
 * municipality's name), and logs it. Only the scope, municipality and designation change: never
 * the role, credentials or anything else. Other roles are refused.
 *
 *   php artisan admin:assign maria@example.com --municipality=Bulan --dry-run
 *   php artisan admin:assign maria@example.com --municipality=Bulan
 *   php artisan admin:assign 1 --provincial --title="System Administrator"
 *   php artisan admin:assign 1 --clear-title
 */
class AssignAdminScope extends Command
{
    use ManagesAdminScope;

    protected $signature = 'admin:assign
        {user : The admin\'s email address or user id}
        {--provincial : Manage every municipality}
        {--municipality= : Manage only this municipality (its id or exact name)}
        {--title= : Designation shown in the admin area, e.g. "System Administrator"}
        {--clear-title : Use the default designation again}
        {--dry-run : Show the change without saving it}';

    protected $description = 'Assign an existing admin account a provincial or municipal scope, and/or a designation';

    public function handle(): int
    {
        $key = trim((string) $this->argument('user'));
        $user = ctype_digit($key) ? User::find((int) $key) : User::whereRaw('LOWER(email) = ?', [mb_strtolower($key)])->first();
        if (! $user) {
            $this->error("No account matches \"{$key}\".");

            return self::FAILURE;
        }

        $municipality = $user->municipality;
        $this->table(['Account', 'Current value'], [
            ['Id', $user->id],
            ['Name', $user->name],
            ['Email', $user->email],
            ['Role', $user->role?->name ?? '(none)'],
            ['Scope', $user->isAdmin() ? $this->describeScope($user->admin_scope, $municipality) : '(not an admin)'],
            ['Designation', $user->isAdmin() ? $user->adminProfile()['designation'] : '(not an admin)'],
        ]);

        if (! $user->isAdmin()) {
            $this->error("Only admin accounts can have an admin scope. This account's role is \"{$user->role?->name}\"; nothing was changed.");

            return self::FAILURE;
        }

        $scope = $this->requestedScope();
        $title = $this->requestedTitle();
        if ($scope === false || $title === false) {
            return self::FAILURE;
        }
        if ($title !== null && $this->option('clear-title')) {
            $this->error('Choose --title or --clear-title, not both.');

            return self::FAILURE;
        }
        if ($scope === null && $title === null && ! $this->option('clear-title')) {
            $this->error('Nothing to change: give --provincial, --municipality=<name or id>, --title=<designation> or --clear-title.');

            return self::FAILURE;
        }

        // The new values; a provincial admin keeps no municipality.
        [$newScope, $newMunicipality] = $scope ?? [$user->admin_scope, $municipality];
        $newTitle = $this->option('clear-title') ? null : ($title ?? $user->admin_title);
        if ($newScope !== User::ADMIN_SCOPE_MUNICIPAL) {
            $newMunicipality = null;
        }

        $from = $this->describeScope($user->admin_scope, $municipality);
        $to = $this->describeScope($newScope, $newMunicipality);
        $changes = array_filter([
            $from !== $to ? ['Scope', $from, $to] : null,
            $user->admin_title !== $newTitle ? ['Designation', $user->admin_title ?? '(default)', $newTitle ?? '(default)'] : null,
        ]);
        if ($changes === []) {
            $this->info('Nothing to change: the account already has this scope and designation.');

            return self::SUCCESS;
        }

        $this->table(['Change', 'From', 'To'], array_values($changes));
        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was saved.');

            return self::SUCCESS;
        }
        if (! $this->confirmChange("Apply this to {$user->name} <{$user->email}>?", $from !== $to ? $newMunicipality : null)) {
            $this->warn('Not confirmed: nothing was changed.');

            return self::FAILURE;
        }

        $user->forceFill([
            'admin_scope' => $newScope,
            'municipality_id' => $newMunicipality?->id,
            'admin_title' => $newTitle,
        ])->save();
        $this->logScopeChange($user, 'admin_scope_changed', $from, $to, [
            'scope' => $newScope, 'municipality_id' => $newMunicipality?->id, 'admin_title' => $newTitle,
        ]);

        $this->info("Saved. {$user->name} is now: {$to}; designation \"{$user->fresh()->adminProfile()['designation']}\". They see it the next time the admin area loads.");

        return self::SUCCESS;
    }
}

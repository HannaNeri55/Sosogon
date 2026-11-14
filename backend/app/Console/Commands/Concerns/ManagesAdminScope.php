<?php

namespace App\Console\Commands\Concerns;

use App\Models\AccountActionLog;
use App\Models\Municipality;
use App\Models\User;

/**
 * Shared by admin:assign and admin:create: reading the scope options, finding the municipality
 * named (never a default one), describing an admin's authority, and the confirmation and audit
 * log entry every change of authority gets.
 */
trait ManagesAdminScope
{
    /**
     * The scope the options ask for: [scope, municipality] or null when none is given. Refuses
     * both --provincial and --municipality, and an unknown or ambiguous municipality.
     *
     * @return array{0: string, 1: Municipality|null}|null|false false after reporting an error
     */
    protected function requestedScope(): array|null|false
    {
        $provincial = (bool) $this->option('provincial');
        $town = $this->option('municipality');

        if ($provincial && $town !== null) {
            $this->error('Choose one scope: --provincial or --municipality, not both.');

            return false;
        }
        if ($provincial) {
            return [User::ADMIN_SCOPE_PROVINCIAL, null];
        }
        if ($town === null) {
            return null;
        }

        $municipality = $this->findMunicipality((string) $town);

        return $municipality ? [User::ADMIN_SCOPE_MUNICIPAL, $municipality] : false;
    }

    /** The municipality with this id or exact name (any letter case); reports an error when there isn't exactly one. */
    protected function findMunicipality(string $value): ?Municipality
    {
        $value = trim($value);
        $matches = ctype_digit($value)
            ? Municipality::whereKey((int) $value)->get()
            : Municipality::whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $this->error($matches->isEmpty()
            ? "No municipality matches \"{$value}\". Use its id or exact name: ".Municipality::orderBy('name')->pluck('name')->implode(', ').'.'
            : "\"{$value}\" matches more than one municipality; use its id.");

        return null;
    }

    /** A designation from --title: trimmed, 1–100 characters; false after reporting an error. */
    protected function requestedTitle(): string|null|false
    {
        $title = $this->option('title');
        if ($title === null) {
            return null;
        }
        $title = trim((string) $title);
        if ($title === '' || mb_strlen($title) > 100) {
            $this->error('The designation (--title) must be 1 to 100 characters.');

            return false;
        }

        return $title;
    }

    /** How an admin's scope reads, e.g. "Municipal: Bulan", "Provincial", "Not assigned (full access)". */
    protected function describeScope(?string $scope, ?Municipality $municipality): string
    {
        return match ($scope) {
            User::ADMIN_SCOPE_PROVINCIAL => 'Provincial (every municipality)',
            User::ADMIN_SCOPE_MUNICIPAL => 'Municipal: '.($municipality?->name ?? 'none set (manages nothing)'),
            null => 'Not assigned (full access until assigned)',
            default => "Invalid value \"{$scope}\" (no admin access)",
        };
    }

    /**
     * Asks for confirmation. A municipal assignment also asks for the municipality's name to be
     * typed, so a wrong id can't slip through. Without an interactive terminal nothing is confirmed.
     */
    protected function confirmChange(string $question, ?Municipality $municipality): bool
    {
        if (! $this->confirm($question, false)) {
            return false;
        }
        if (! $municipality) {
            return true;
        }
        $typed = (string) $this->ask("Type the municipality's name to confirm ({$municipality->name})");

        if (mb_strtolower(trim($typed)) !== mb_strtolower($municipality->name)) {
            $this->error("That doesn't match \"{$municipality->name}\".");

            return false;
        }

        return true;
    }

    /** Records the change in the account log (Account Activity Log in the admin area). */
    protected function logScopeChange(User $user, string $action, string $from, string $to, array $context): void
    {
        AccountActionLog::create([
            'admin_id' => null,
            'admin_name' => 'Console (artisan '.$this->getName().')',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'action' => $action,
            'previous_status' => mb_substr($from, 0, 30),
            'new_status' => mb_substr($to, 0, 30),
            'reason' => "{$from} → {$to}",
            'context' => $context,
        ]);
    }
}

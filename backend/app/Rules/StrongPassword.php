<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * SOSOGON's password requirements, the same list the registration form checks while the user
 * types (frontend/src/utils/authValidation.js): at least 8 characters, an uppercase letter, a
 * lowercase letter, a number, a special character, and no spaces. The password is checked as
 * typed: never trimmed or changed.
 */
class StrongPassword implements ValidationRule
{
    /** Requirement => [pattern the password must match, what is missing when it doesn't]. */
    private const REQUIREMENTS = [
        'length' => ['/^.{8,}$/su', 'at least 8 characters'],
        'uppercase' => ['/[A-Z]/', 'an uppercase letter (A–Z)'],
        'lowercase' => ['/[a-z]/', 'a lowercase letter (a–z)'],
        'number' => ['/[0-9]/', 'a number (0–9)'],
        'special' => ['/[^A-Za-z0-9\s]/u', 'a special character (e.g. ! @ # $ % & *)'],
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The password must be text.');

            return;
        }

        $missing = [];
        foreach (self::REQUIREMENTS as [$pattern, $label]) {
            if (! preg_match($pattern, $value)) {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            $last = array_pop($missing);
            $fail('Your password needs '.($missing ? implode(', ', $missing).' and ' : '').$last.'.');
        }
        if (preg_match('/\s/u', $value)) {
            $fail('Your password must not contain spaces.');
        }
    }
}

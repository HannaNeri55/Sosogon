<?php

namespace App\Support;

/**
 * A listing value in the form "Last Updated" compares (TracksInfoUpdates, UpdatesListingInfo), so
 * saving the same information again (other spacing, "12.670000" for 12.67) is not an update.
 */
class InfoValue
{
    public static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return json_encode(array_map([self::class, 'normalize'], $value));
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_numeric($value)) {
            return (string) round((float) $value, 6);
        }
        $text = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $text === '' ? null : $text;
    }

    public static function differs(mixed $before, mixed $after): bool
    {
        return self::normalize($before) !== self::normalize($after);
    }
}

<?php

namespace App\Support;

/**
 * Cleans a list of amenity names typed or picked by an owner or admin: strips markup, trims,
 * collapses spaces, drops blanks, and keeps the first of any entries that differ only by capitals.
 * Names are otherwise kept word for word; none is ever added.
 */
class AmenityList
{
    public const MAX_PROPERTY = 40;

    public const MAX_UNIT = 30;

    public static function clean(mixed $names): mixed
    {
        if (! is_array($names)) {
            return $names;
        }

        $clean = [];
        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }
            $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)));
            $key = mb_strtolower($name);
            if ($name !== '' && ! isset($clean[$key])) {
                $clean[$key] = $name;
            }
        }

        return array_values($clean);
    }
}

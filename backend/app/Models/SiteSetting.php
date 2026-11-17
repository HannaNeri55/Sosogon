<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A value the Tourism Office can change from the admin side without a code change. */
class SiteSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** The stored value of $key, or $default when it was never saved. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::where('key', $key)->first();

        return $row ? $row->value : $default;
    }

    public static function put(string $key, mixed $value, ?User $by = null): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by?->id]);
    }
}

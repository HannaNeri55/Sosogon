<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Where a listing field's value came from. Internal only: no public endpoint reads this table.
 * A field without a row is original data entered by an owner or the Tourism Office.
 */
class FieldProvenance extends Model
{
    public const ORIGINAL = 'original';

    public const GENERATED = 'generated';

    public const VERIFIED = 'verified';

    public const ADMIN_DECISION = 'admin_decision';

    /** The field name used for a row that records a whole created record. */
    public const WHOLE_RECORD = '__record';

    protected $table = 'field_provenance';

    protected $fillable = [
        'record_table',
        'record_id',
        'field',
        'source',
        'recorded_value',
        'previous_value',
        'batch',
        'verified_by',
        'verified_at',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public static function sourceOf(string $table, int $id, string $field): string
    {
        return static::where(['record_table' => $table, 'record_id' => $id, 'field' => $field])->value('source')
            ?? self::ORIGINAL;
    }
}

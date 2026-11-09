<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    /** Event dates are Philippine calendar dates. */
    public const LOCAL_TIMEZONE = 'Asia/Manila';

    protected $fillable = [
        'title',
        'description',
        'location',
        'municipality_id',
        'image_path',
        'start_date',
        'end_date',
        'is_featured',
        'status',
        'created_by',
        'what_to_wear',
        'what_to_bring',
        'travel_tips',
        'contact_name',
        'contact_phone',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'is_featured' => 'boolean',
            'travel_tips' => 'array',
        ];
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * Events that have not finished yet, judged by Philippine dates (the app clock is UTC):
     * future events and ones happening today. An event drops out the day after its end
     * date, or after its start date when it has no end date.
     */
    public function scopeUpcoming($query)
    {
        return $query->whereRaw('COALESCE(end_date, start_date) >= ?', [now(self::LOCAL_TIMEZONE)->toDateString()]);
    }
}

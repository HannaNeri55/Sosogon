<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoodBeverageRevision extends Model
{
    protected $fillable = [
        'food_beverage_id',
        'submitted_by',
        'proposed_data',
        'proposed_gallery',
        'status',
        'admin_notes',
        'reviewed_by',
        'submitted_at',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'proposed_data' => 'array',
            'proposed_gallery' => 'array',
            'base_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function foodBeverage(): BelongsTo
    {
        return $this->belongsTo(FoodBeverage::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

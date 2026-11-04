<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetailShopRevision extends Model
{
    protected $fillable = [
        'retail_shop_id',
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

    public function retailShop(): BelongsTo
    {
        return $this->belongsTo(RetailShop::class);
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

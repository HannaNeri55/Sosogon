<?php

namespace App\Models;

use App\Models\Concerns\HasEstimates;
use App\Models\Concerns\HasPendingChanges;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItem extends Model
{
    use HasEstimates;
    use HasPendingChanges;

    protected $fillable = [
        'food_beverage_id',
        'name',
        'description',
        'price',
        'image_path',
        'thumbnail_path',
        'status',
        'admin_notes',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function foodBeverage(): BelongsTo
    {
        return $this->belongsTo(FoodBeverage::class);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}

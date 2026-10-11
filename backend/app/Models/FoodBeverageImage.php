<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoodBeverageImage extends Model
{
    protected $fillable = ['food_beverage_id', 'image_path', 'thumbnail_path', 'is_primary', 'sort_order'];

    public function foodBeverage(): BelongsTo
    {
        return $this->belongsTo(FoodBeverage::class);
    }
}

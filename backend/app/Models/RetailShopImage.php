<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetailShopImage extends Model
{
    protected $fillable = ['retail_shop_id', 'image_path', 'thumbnail_path', 'is_primary', 'sort_order'];

    public function retailShop(): BelongsTo
    {
        return $this->belongsTo(RetailShop::class);
    }
}

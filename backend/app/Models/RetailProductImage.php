<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetailProductImage extends Model
{
    protected $fillable = ['retail_product_id', 'image_path', 'thumbnail_path', 'is_primary', 'sort_order'];

    public function retailProduct(): BelongsTo
    {
        return $this->belongsTo(RetailProduct::class);
    }
}

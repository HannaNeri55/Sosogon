<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['name', 'type', 'icon'];

    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class);
    }
}

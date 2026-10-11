<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['query', 'filters', 'results_count', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}

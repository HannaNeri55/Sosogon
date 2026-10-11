<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class VisitorStatistic extends Model
{
    protected $fillable = ['trackable_type', 'trackable_id', 'page_views', 'date'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function trackable(): MorphTo
    {
        return $this->morphTo();
    }
}

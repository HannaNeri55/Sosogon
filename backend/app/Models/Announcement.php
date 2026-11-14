<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    // municipality_id: the municipality it belongs to; null is province-wide.
    protected $fillable = ['title', 'content', 'is_pinned', 'status', 'created_by', 'municipality_id'];

    protected function casts(): array
    {
        return ['is_pinned' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }
}

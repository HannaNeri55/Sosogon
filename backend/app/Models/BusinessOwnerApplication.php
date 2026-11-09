<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Business Owner's registration and business permit, reviewed by an admin.
 * The permit path is never serialized; the file is only streamed by the
 * permit endpoints after an ownership/admin check.
 */
class BusinessOwnerApplication extends Model
{
    protected $fillable = [
        'user_id',
        'business_name',
        'business_type',
        'business_address',
        'municipality_id',
        'barangay_id',
        'business_permit_number',
        'business_permit_path',
    ];

    protected $hidden = ['business_permit_path'];

    protected $appends = ['has_permit'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'resubmitted_at' => 'datetime'];
    }

    public function getHasPermitAttribute(): bool
    {
        return (bool) $this->business_permit_path;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}

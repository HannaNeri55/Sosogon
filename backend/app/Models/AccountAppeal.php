<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A user's appeal against one enforcement action, and the admin's decision on it. */
class AccountAppeal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'account_violation_id', 'user_id', 'explanation', 'attachments',
        'status', 'decision_note', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function violation(): BelongsTo
    {
        return $this->belongsTo(AccountViolation::class, 'account_violation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit trail of account management actions. Existing rows can never be
 * updated or deleted through the application; names are copied in so a record stays
 * readable even if the admin or user account is later removed.
 */
class AccountActionLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_id', 'admin_name', 'user_id', 'user_name', 'user_email', 'action',
        'previous_status', 'new_status', 'reason', 'context', 'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Account action logs cannot be modified.'));
        static::deleting(fn () => throw new LogicException('Account action logs cannot be deleted.'));
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

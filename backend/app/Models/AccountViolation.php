<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One enforcement action against an account (warning, temporary suspension or
 * deactivation). Records are never deleted; resolving one only changes its status.
 */
class AccountViolation extends Model
{
    public const ACTION_WARNING = 'warning';

    public const ACTION_SUSPENSION = 'suspension';

    public const ACTION_DEACTIVATION = 'deactivation';

    public const ACTIONS = [self::ACTION_WARNING, self::ACTION_SUSPENSION, self::ACTION_DEACTIVATION];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_LIFTED = 'lifted';

    public const STATUS_REVERSED = 'reversed';

    /** How long a warning stays on the account (shown as "Warning") unless a review date is set. */
    public const WARNING_DAYS = 90;

    /** Violation categories offered to admins (key => label). */
    public const CATEGORIES = [
        'fake_or_misleading_listing' => 'Fake or misleading listing information',
        'inappropriate_content' => 'Inappropriate or offensive content',
        'fake_reviews' => 'Fake or manipulated reviews',
        'harassment' => 'Harassment or abusive behaviour',
        'spam' => 'Spam or unsolicited promotion',
        'fraud_or_scam' => 'Fraud, scam or misuse of payments',
        'impersonation' => 'Impersonation or false identity',
        'policy_violation' => 'Other violation of platform policies',
    ];

    protected $fillable = [
        'user_id', 'reviewed_by', 'category', 'reason', 'details', 'evidence_notes', 'evidence_files',
        'action', 'suspension_days', 'starts_at', 'ends_at', 'review_date', 'reported_at', 'reviewed_at',
        'status', 'resolved_at', 'resolved_by', 'resolution_note',
    ];

    protected $appends = ['category_label'];

    protected function casts(): array
    {
        return [
            'evidence_files' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'review_date' => 'date:Y-m-d',
            'reported_at' => 'date:Y-m-d',
            'reviewed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'suspension_days' => 'integer',
        ];
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst(str_replace('_', ' ', (string) $this->category));
    }

    /** Still in force: active and not past its end date (warnings and suspensions end; deactivations don't). */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(AccountAppeal::class);
    }

    public function latestAppeal(): HasOne
    {
        return $this->hasOne(AccountAppeal::class)->latestOfMany();
    }
}

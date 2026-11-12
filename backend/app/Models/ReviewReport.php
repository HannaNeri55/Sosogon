<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A listing owner's report about a review on their listing, and the Tourism Office's decision.
 * A low rating alone is not a reason: reports are about content that breaks the rules.
 */
class ReviewReport extends Model
{
    public const REASONS = [
        'offensive' => 'Offensive or abusive language',
        'false_information' => 'False or misleading information',
        'personal_information' => 'Shares personal information',
        'spam' => 'Spam or advertising',
        'not_a_visit' => 'Not about a visit to this place',
        'other' => 'Other',
    ];

    public const STATUSES = [
        'pending' => 'Pending Review',
        'reviewed' => 'Reviewed',
        'action_taken' => 'Action Taken',
        'dismissed' => 'Dismissed',
    ];

    /** Reports still waiting for a final decision. */
    public const OPEN = ['pending', 'reviewed'];

    // status and the decision fields are set by the Tourism Office only (forceFill).
    protected $fillable = ['rating_id', 'reported_by', 'reason', 'details', 'evidence_path'];

    protected $hidden = ['evidence_path'];

    protected $appends = ['has_evidence'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function getHasEvidenceAttribute(): bool
    {
        return (bool) $this->evidence_path;
    }

    public function rating(): BelongsTo
    {
        return $this->belongsTo(Rating::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

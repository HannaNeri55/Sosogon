<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An internal note by the Tourism Office on a feedback submission. Never shown to the sender. */
class FeedbackNote extends Model
{
    protected $fillable = ['feedback_submission_id', 'user_id', 'note'];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

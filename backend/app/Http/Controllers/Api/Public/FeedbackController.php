<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\FeedbackSubmission;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Feedback & Concerns from the public site, signed in or not. Name and email are optional (email
 * is needed only for a reply). The sender gets a reference number; nothing they send is shown
 * publicly. The same text sent again from the same device or account within a day is not saved
 * twice: the first reference is returned. Sent messages are limited per sender; mistakes on the
 * form never count towards it. See FeedbackSubmission.
 */
class FeedbackController extends Controller
{
    /** Field bots fill in and people never see (hidden on the form). */
    public const TRAP_FIELD = 'contact_preference_check';

    private const DUPLICATE_HOURS = 24;

    /** The form's choices. */
    public function options(): JsonResponse
    {
        return response()->json([
            'categories' => FeedbackSubmission::CATEGORIES,
            'modules' => FeedbackSubmission::MODULES,
            'listing_types' => array_map(fn ($type) => $type[1], FeedbackSubmission::LISTING_TYPES),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (filled($request->input(self::TRAP_FIELD))) {
            return response()->json(['message' => 'Your message could not be sent. Please try again.'], 422);
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'wants_reply' => ['sometimes', 'boolean'],
            'email' => ['nullable', 'email:rfc', 'max:255', Rule::requiredIf($request->boolean('wants_reply'))],
            'category' => ['required', Rule::in(array_keys(FeedbackSubmission::CATEGORIES))],
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'module' => ['nullable', Rule::in(array_keys(FeedbackSubmission::MODULES))],
            'listing_type' => ['nullable', 'required_with:listing_id', Rule::in(array_keys(FeedbackSubmission::LISTING_TYPES))],
            'listing_id' => ['nullable', 'required_with:listing_type', 'integer'],
        ], [
            'email.required' => 'Please enter your email address so we can reply.',
            'category.required' => 'Please choose what your feedback is about.',
            'subject.required' => 'Please enter a subject.',
            'message.required' => 'Please describe your feedback or concern.',
            'message.min' => 'Please describe your feedback in a few more words (at least 10 characters).',
        ]);

        $listing = null;
        if (! empty($validated['listing_type'])) {
            [$class] = FeedbackSubmission::LISTING_TYPES[$validated['listing_type']];
            $public = $class === Destination::class ? $class::published() : $class::approved();
            $listing = $public->find($validated['listing_id']);
            if (! $listing) {
                return response()->json(['message' => 'That place could not be found. Please remove it and try again.', 'errors' => ['listing_id' => ['That place could not be found.']]], 422);
            }
        }

        $user = $request->user('sanctum');
        $ipHash = FeedbackSubmission::ipHash($request->ip());
        $contentHash = FeedbackSubmission::contentHash($validated['subject'], $validated['message']);

        // A resend (double click, retry after a slow connection) of the same text from the same sender.
        $earlier = FeedbackSubmission::where('content_hash', $contentHash)
            ->where('created_at', '>=', now()->subHours(self::DUPLICATE_HOURS))
            ->where(fn ($q) => $user ? $q->where('user_id', $user->id) : $q->whereNull('user_id')->where('ip_hash', $ipHash))
            ->first();
        if ($earlier) {
            return response()->json([
                'message' => "We already received this message. Your reference number is {$earlier->reference}.",
                'reference' => $earlier->reference,
                'duplicate' => true,
            ]);
        }

        // Only messages actually sent count, per account when signed in so people sharing a school
        // or office network don't use up each other's limit; guests share their network's, set generously.
        $sender = $user ? 'user:'.$user->id : 'ip:'.$ipHash;
        $limits = [['feedback-minute:'.$sender, 5, 60], ['feedback-day:'.$sender, $user ? 20 : 60, 86400]];
        foreach ($limits as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                return response()->json(['message' => 'You have sent several messages in a short time. Please wait a little and try again.'], 429);
            }
        }
        foreach ($limits as [$key, , $seconds]) {
            RateLimiter::hit($key, $seconds);
        }

        $clean = fn (?string $text) => ($text = trim((string) $text)) === '' ? null : $text;
        $submission = FeedbackSubmission::create([
            'reference' => FeedbackSubmission::newReference(),
            'name' => $clean($validated['name'] ?? null),
            'email' => $clean($validated['email'] ?? null),
            'wants_reply' => (bool) ($validated['wants_reply'] ?? false),
            'category' => $validated['category'],
            'subject' => trim($validated['subject']),
            'message' => trim($validated['message']),
            'module' => $listing ? FeedbackSubmission::LISTING_TYPES[$validated['listing_type']][1] : ($validated['module'] ?? null),
            'listing_type' => $listing ? $validated['listing_type'] : null,
            'listing_id' => $listing?->id,
            'municipality_id' => $listing?->municipality_id,
            'user_id' => $user?->id,
            'ip_hash' => $ipHash,
            'content_hash' => $contentHash,
        ]);

        Notify::admins(
            $submission->municipality_id,
            'feedback',
            'New feedback: '.FeedbackSubmission::CATEGORIES[$submission->category],
            Str::limit(($listing ? $listing->name.': ' : '').$submission->subject, 120),
            '/admin/feedback?open='.$submission->id,
        );

        return response()->json([
            'message' => "Thank you! Your feedback was sent. Your reference number is {$submission->reference}.",
            'reference' => $submission->reference,
            'duplicate' => false,
        ], 201);
    }
}

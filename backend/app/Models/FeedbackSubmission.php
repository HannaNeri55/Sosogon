<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Feedback or a concern sent from the public site (Feedback & Concerns). Name and email are
 * optional; nothing here is ever shown publicly. A submission about a listing belongs to that
 * listing's municipality, so its office handles it; the rest go to the provincial admins.
 */
class FeedbackSubmission extends Model
{
    public const CATEGORIES = [
        'general' => 'General Feedback',
        'suggestion' => 'Suggestions for Improvement',
        'incorrect_information' => 'Incorrect Information',
        'incorrect_pricing' => 'Incorrect Pricing',
        'incorrect_location' => 'Incorrect Location',
        'technical' => 'Technical Issues',
        'complaint' => 'Complaints and Concerns',
        'other' => 'Other',
    ];

    public const STATUSES = [
        'pending' => 'Pending',
        'under_review' => 'Under Review',
        'resolved' => 'Resolved',
        'rejected' => 'Rejected',
    ];

    /** Still waiting for the office: not resolved or rejected yet. */
    public const OPEN_STATUSES = ['pending', 'under_review'];

    public const MODULES = [
        'destinations' => 'Destinations',
        'accommodations' => 'Accommodations',
        'food_beverage' => 'Food & Beverage',
        'shops' => 'Shops',
        'events' => 'Events',
        'trip_planner' => 'Trip Planner',
        'website' => 'The website in general',
    ];

    /** The listings a submission can be about: model and the module it belongs to. */
    public const LISTING_TYPES = [
        'destination' => [Destination::class, 'destinations'],
        'accommodation' => [Accommodation::class, 'accommodations'],
        'food_beverage' => [FoodBeverage::class, 'food_beverage'],
        'retail_shop' => [RetailShop::class, 'shops'],
    ];

    /** Letters and digits that can't be mistaken for each other when read out or typed. */
    private const REFERENCE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected $fillable = [
        'reference', 'name', 'email', 'wants_reply', 'category', 'subject', 'message', 'module',
        'listing_type', 'listing_id', 'municipality_id', 'user_id', 'ip_hash', 'content_hash',
    ];

    protected $hidden = ['ip_hash', 'content_hash'];

    protected function casts(): array
    {
        return ['wants_reply' => 'boolean', 'handled_at' => 'datetime'];
    }

    public function notes(): HasMany
    {
        return $this->hasMany(FeedbackNote::class)->latest()->orderByDesc('id');
    }

    public function municipality(): BelongsTo
    {
        return $this->belongsTo(Municipality::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /** The listing it is about, published or not (it may have been unpublished since). */
    public function listing(): ?Model
    {
        $class = self::LISTING_TYPES[$this->listing_type][0] ?? null;

        return $class && $this->listing_id ? $class::find($this->listing_id) : null;
    }

    /** "FB-20261117-7K3Q": the day it was sent and four characters, unique. */
    public static function newReference(): string
    {
        do {
            $code = collect(range(1, 4))->map(fn () => self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)])->implode('');
            $reference = 'FB-'.now()->timezone('Asia/Manila')->format('Ymd').'-'.$code;
        } while (self::where('reference', $reference)->exists());

        return $reference;
    }

    /** The same subject and message, ignoring case and spacing: a resend of the same text. */
    public static function contentHash(string $subject, string $message): string
    {
        $normal = fn (string $text) => Str::lower(trim(preg_replace('/\s+/u', ' ', $text)));

        return hash_hmac('sha256', $normal($subject)."\n".$normal($message), (string) config('app.key'));
    }

    public static function ipHash(?string $ip): ?string
    {
        return $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null;
    }
}

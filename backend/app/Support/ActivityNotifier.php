<?php

namespace App\Support;

use App\Models\Accommodation;
use App\Models\AccommodationRevision;
use App\Models\AccommodationRoom;
use App\Models\AccountAppeal;
use App\Models\BusinessOwnerApplication;
use App\Models\BusinessSubmission;
use App\Models\FoodBeverage;
use App\Models\FoodBeverageRevision;
use App\Models\MenuItem;
use App\Models\Rating;
use App\Models\RatingReply;
use App\Models\RetailProduct;
use App\Models\RetailShop;
use App\Models\RetailShopRevision;
use App\Models\ReviewReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Turns what happens on the site into notifications (see Notify): an owner or tourist doing
 * something the admin must act on notifies the admins; an admin's decision notifies the owner;
 * a review notifies the listing's owner and a reply notifies the reviewer; a reported review notifies
 * the admins and their decision notifies the owner who reported it.
 *
 * Hooked to model events so every route to a change is covered (single and bulk reviews alike).
 * Only changes made through the website notify anyone: seeders and console commands don't.
 */
class ActivityNotifier
{
    private const LISTINGS = [
        Accommodation::class => 'accommodation',
        FoodBeverage::class => 'food_beverage',
        RetailShop::class => 'retail_product',
    ];

    /** Item model => [label, portal page, relation to its listing]. */
    private const ITEMS = [
        AccommodationRoom::class => ['room', 'rooms', 'accommodation'],
        MenuItem::class => ['menu item', 'menu', 'foodBeverage'],
        RetailProduct::class => ['product', 'products', 'shop'],
    ];

    private const REVISIONS = [
        AccommodationRevision::class => 'accommodation',
        FoodBeverageRevision::class => 'foodBeverage',
        RetailShopRevision::class => 'retailShop',
    ];

    public static function register(): void
    {
        foreach (array_keys(self::LISTINGS) as $listing) {
            $listing::updated(fn (Model $model) => self::guard(fn () => self::listingUpdated($model)));
        }
        foreach (array_keys(self::REVISIONS) as $revision) {
            $revision::created(fn (Model $model) => self::guard(fn () => self::revisionChanged($model)));
            $revision::updated(fn (Model $model) => self::guard(fn () => $model->wasChanged('status') && self::revisionChanged($model)));
        }
        foreach (array_keys(self::ITEMS) as $item) {
            $item::created(fn (Model $model) => self::guard(fn () => self::itemCreated($model)));
            $item::updated(fn (Model $model) => self::guard(fn () => self::itemUpdated($model)));
        }
        BusinessSubmission::created(fn (BusinessSubmission $model) => self::guard(fn () => self::submissionCreated($model)));
        BusinessSubmission::updated(fn (BusinessSubmission $model) => self::guard(fn () => self::submissionUpdated($model)));
        BusinessOwnerApplication::updated(fn (BusinessOwnerApplication $model) => self::guard(fn () => self::applicationUpdated($model)));
        Rating::created(fn (Rating $model) => self::guard(fn () => self::ratingCreated($model)));
        RatingReply::created(fn (RatingReply $model) => self::guard(fn () => self::replyCreated($model)));
        AccountAppeal::created(fn (AccountAppeal $model) => self::guard(fn () => self::appealCreated($model)));
        ReviewReport::created(fn (ReviewReport $model) => self::guard(fn () => self::reportCreated($model)));
        ReviewReport::updated(fn (ReviewReport $model) => self::guard(fn () => self::reportDecided($model)));
    }

    private static function guard(callable $handler): void
    {
        if (request()->route() !== null) {
            $handler();
        }
    }

    private static function actor(): ?User
    {
        return request()->user();
    }

    private static function byAdmin(): bool
    {
        return (bool) self::actor()?->isAdmin();
    }

    // ---------- Listings (accommodations, food & beverage, local products shops) ----------

    private static function listingUpdated(Model $listing): void
    {
        $type = self::LISTINGS[$listing::class];
        $owner = $listing->user;

        if ($listing->wasChanged('publication_review_status') && $listing->publication_review_status === 'submitted' && ! self::byAdmin()) {
            Notify::admins('listing_submitted', 'Listing ready for review',
                self::name($owner)." submitted {$listing->name} (".Notify::typeLabel($type).') for publication review.',
                '/admin/listing-reviews', self::actor());
        }

        if (! self::byAdmin()) {
            return;
        }
        if ($listing->wasChanged('status') && $listing->status === 'approved') {
            Notify::user($owner, 'listing_published', 'Your listing is live',
                "The Tourism Office approved {$listing->name}. Tourists can now find it on the site.",
                Notify::portal($type), self::actor());
        } elseif ($listing->wasChanged('publication_review_status') && $listing->publication_review_status === 'needs_revision') {
            Notify::user($owner, 'listing_returned', 'Your listing needs changes',
                "The Tourism Office returned {$listing->name} for changes before it can be published. Open it to see what to fix.",
                Notify::portal($type), self::actor());
        }
    }

    private static function revisionChanged(Model $revision): void
    {
        $listing = $revision->{self::REVISIONS[$revision::class]};
        if (! $listing) {
            return;
        }
        $type = self::LISTINGS[$listing::class];

        if ($revision->status === 'submitted' && ! self::byAdmin()) {
            Notify::admins('revision_submitted', 'Listing changes to review',
                self::name($revision->submitter ?? $listing->user)." submitted changes to {$listing->name}.",
                '/admin/listing-reviews', self::actor());

            return;
        }
        if (! self::byAdmin()) {
            return;
        }
        $owner = $revision->submitter ?? $listing->user;
        if ($revision->status === 'approved') {
            Notify::user($owner, 'revision_approved', 'Your changes are live',
                "The Tourism Office approved your changes to {$listing->name}.", Notify::portal($type), self::actor());
        } elseif (in_array($revision->status, ['needs_revision', 'rejected'], true)) {
            Notify::user($owner, 'revision_returned', 'Your changes were returned',
                "The Tourism Office returned your changes to {$listing->name}".self::reason($revision->admin_notes),
                Notify::portal($type), self::actor());
        }
    }

    // ---------- Rooms, menu items, products ----------

    private static function itemCreated(Model $item): void
    {
        if ($item->status !== 'pending' || self::byAdmin()) {
            return;
        }
        [$label, , $relation] = self::ITEMS[$item::class];
        $listing = $item->{$relation};

        Notify::admins('item_submitted', 'New '.$label.' to review',
            self::name($listing?->user)." added the {$label} “{$item->name}”".($listing ? " to {$listing->name}" : '').'.',
            '/admin/item-reviews', self::actor());
    }

    private static function itemUpdated(Model $item): void
    {
        [$label, $page, $relation] = self::ITEMS[$item::class];
        $listing = $item->{$relation};
        $owner = $listing?->user ?? ($item->user_id ? User::find($item->user_id) : null);
        $at = $listing ? " at {$listing->name}" : '';
        $link = $listing ? Notify::portal(self::LISTINGS[$listing::class], $page) : '/business';

        if ($item->wasChanged('pending_changes')) {
            $old = $item->getOriginal('pending_changes');
            $new = $item->pending_changes;
            $wasPending = ($old['status'] ?? null) === 'pending';

            if (($new['status'] ?? null) === 'pending' && ! $wasPending && ! self::byAdmin()) {
                Notify::admins('item_changes_submitted', ucfirst($label).' changes to review',
                    self::name($owner)." edited the {$label} “{$item->name}”{$at}.", '/admin/item-reviews', self::actor());
            } elseif ($wasPending && self::byAdmin() && $new === null) {
                Notify::user($owner, 'item_changes_approved', 'Your changes are live',
                    "The Tourism Office approved your changes to the {$label} “{$item->name}”{$at}.", $link, self::actor());
            } elseif ($wasPending && self::byAdmin() && ($new['status'] ?? null) === 'rejected') {
                Notify::user($owner, 'item_changes_returned', 'Your changes were returned',
                    "The Tourism Office returned your changes to the {$label} “{$item->name}”{$at}".self::reason($new['notes'] ?? null),
                    $link, self::actor());
            }
        }

        if ($item->wasChanged('status') && $item->getOriginal('status') === 'pending' && self::byAdmin()) {
            if ($item->status === 'approved') {
                Notify::user($owner, 'item_approved', ucfirst($label).' approved',
                    "The Tourism Office approved the {$label} “{$item->name}”{$at}. It is now shown on your listing.", $link, self::actor());
            } elseif ($item->status === 'rejected') {
                Notify::user($owner, 'item_rejected', ucfirst($label).' not approved',
                    "The Tourism Office did not approve the {$label} “{$item->name}”{$at}".self::reason($item->admin_notes),
                    $link, self::actor());
            }
        }
    }

    // ---------- Business submissions and owner registrations ----------

    private static function submissionCreated(BusinessSubmission $submission): void
    {
        if (! self::byAdmin()) {
            Notify::admins('submission_new', 'New business submission',
                self::name($submission->user)." submitted {$submission->business_name}, a new ".Notify::typeLabel($submission->type).' listing.',
                '/admin/submissions', self::actor());
        }
    }

    private static function submissionUpdated(BusinessSubmission $submission): void
    {
        $owner = $submission->user;

        if ($submission->wasChanged('status')) {
            if ($submission->status === 'pending' && ! self::byAdmin()) {
                Notify::admins('submission_updated', 'Submission updated',
                    self::name($owner)." updated {$submission->business_name}; it needs review again.",
                    '/admin/submissions', self::actor());
            } elseif (self::byAdmin()) {
                match ($submission->status) {
                    'approved' => Notify::user($owner, 'submission_approved', 'Your business was approved',
                        "The Tourism Office approved {$submission->business_name}.", '/business', self::actor()),
                    'rejected' => Notify::user($owner, 'submission_rejected', 'Your submission was not approved',
                        "The Tourism Office did not approve {$submission->business_name}".self::reason($submission->admin_notes),
                        '/business', self::actor()),
                    'removed' => Notify::user($owner, 'submission_removed', 'Your business was removed',
                        "The Tourism Office removed {$submission->business_name} from the site".self::reason($submission->admin_notes),
                        '/business', self::actor()),
                    default => null,
                };
            }
        }

        if ($submission->wasChanged('warned_at') && $submission->warned_at && self::byAdmin()) {
            Notify::user($owner, 'submission_warned', 'Warning about your listing',
                "The Tourism Office sent a warning about {$submission->business_name}".self::reason($submission->warning_notes),
                '/business', self::actor());
        }
    }

    private static function applicationUpdated(BusinessOwnerApplication $application): void
    {
        if (! $application->wasChanged('status')) {
            return;
        }
        $owner = $application->user;

        if ($application->status === 'pending' && ! self::byAdmin()) {
            Notify::admins('owner_resubmitted', 'Registration resubmitted',
                self::name($owner)." corrected and resubmitted the registration for {$application->business_name}.",
                '/admin/owner-registrations', self::actor());
        } elseif ($application->status === 'approved' && self::byAdmin()) {
            Notify::user($owner, 'owner_approved', 'Your registration was approved',
                "Welcome! The Tourism Office approved {$application->business_name}. You can now submit your listing.",
                '/business', self::actor());
        } elseif ($application->status === 'rejected' && self::byAdmin()) {
            Notify::user($owner, 'owner_rejected', 'Your registration needs changes',
                "The Tourism Office returned the registration for {$application->business_name}".self::reason($application->rejection_reason),
                '/business', self::actor());
        }
    }

    // ---------- Reviews, replies and appeals ----------

    private static function ratingCreated(Rating $rating): void
    {
        $listing = $rating->rateable;
        if (! $listing) {
            return;
        }
        $excerpt = $rating->comment ? ': “'.Str::limit(trim($rating->comment), 120).'”' : '.';
        $who = self::name($rating->user);

        Notify::admins('review_new', 'New guest review',
            "{$who} rated {$listing->name} {$rating->score}/5{$excerpt}", '/admin/guest-reviews', self::actor());

        if (isset(self::LISTINGS[$listing::class])) {
            Notify::user($listing->user, 'review_new', "New review on {$listing->name}",
                "{$who} gave {$rating->score}/5{$excerpt}", Notify::portal(self::LISTINGS[$listing::class], 'reviews'), self::actor());
        }
    }

    private static function replyCreated(RatingReply $reply): void
    {
        $rating = $reply->rating;
        $listing = $rating?->rateable;
        if (! $rating) {
            return;
        }

        Notify::user($rating->user, 'review_reply', ($listing->name ?? 'The business').' replied to your review',
            '“'.Str::limit(trim($reply->body), 140).'”', '/my-reviews', self::actor());
    }

    private static function appealCreated(AccountAppeal $appeal): void
    {
        Notify::admins('appeal_new', 'New account appeal',
            self::name($appeal->user).' appealed a decision on their account.', '/admin/appeals', self::actor());
    }

    private static function reportCreated(ReviewReport $report): void
    {
        $rating = $report->rating;
        Notify::admins('review_reported', 'Review reported',
            self::name($report->reporter).' reported '.self::name($rating?->user)."'s {$rating?->score}/5 review of ".($rating?->rateable?->name ?? 'their listing')
            .': '.(ReviewReport::REASONS[$report->reason] ?? $report->reason).'.',
            '/admin/guest-reviews?tab=reports', self::actor());
    }

    private static function reportDecided(ReviewReport $report): void
    {
        if (! $report->wasChanged('status') || ! in_array($report->status, ['action_taken', 'dismissed'], true) || ! self::byAdmin()) {
            return;
        }
        $rating = $report->rating;
        $listing = $rating?->rateable;
        $about = 'the review'.($rating?->user ? ' by '.$rating->user->name : '').($listing ? " of {$listing->name}" : '');
        [$title, $message] = $report->status === 'action_taken'
            ? ['Reported review hidden', "The Tourism Office hid {$about} from the public".self::reason($report->decision_note)]
            : ['Report dismissed', "The Tourism Office kept {$about}".self::reason($report->decision_note)];

        Notify::user($report->reporter, 'review_report_decided', $title, $message,
            $listing && isset(self::LISTINGS[$listing::class]) ? Notify::portal(self::LISTINGS[$listing::class], 'reviews') : '/business', self::actor());
    }

    private static function name(?User $user): string
    {
        return $user?->name ?? 'A business owner';
    }

    private static function reason(?string $notes): string
    {
        $notes = trim((string) $notes);

        return $notes === '' ? '.' : ': '.Str::limit($notes, 160);
    }
}

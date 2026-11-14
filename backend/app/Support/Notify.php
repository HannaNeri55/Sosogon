<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends in-app notifications (see ActivityNotification). A notification that fails to send is
 * reported and skipped: it never stops the registration, submission or decision it is about.
 */
class Notify
{
    /**
     * The admins responsible for something in $municipalityId (AdminScope::adminsFor): that
     * municipality's admins and every provincial admin. Null (province-wide, e.g. a tourist
     * account) and municipalities without a municipal admin reach the provincial admins. The
     * admin who did it is left out.
     */
    public static function admins(?int $municipalityId, string $kind, string $title, string $message, ?string $link = null, ?User $except = null): void
    {
        $admins = AdminScope::adminsFor($municipalityId)
            ->when($except, fn ($q) => $q->whereKeyNot($except->getKey()))
            ->get();

        self::send($admins, new ActivityNotification($kind, $title, $message, $link));
    }

    /** One user, e.g. the owner of a listing or the author of a review. Nothing is sent to null or to $except. */
    public static function user(?User $user, string $kind, string $title, string $message, ?string $link = null, ?User $except = null): void
    {
        if (! $user || ($except && $user->is($except))) {
            return;
        }

        self::send(collect([$user]), new ActivityNotification($kind, $title, $message, $link));
    }

    /** How a business type reads in a notification. */
    public static function typeLabel(?string $businessType): string
    {
        return match ($businessType) {
            'accommodation' => 'accommodation',
            'food_beverage' => 'food & beverage',
            'retail_product', 'retail_shop' => 'local products shop',
            default => 'business',
        };
    }

    /** The owner's portal path for a listing type: accommodation, food_beverage or retail_product. */
    public static function portal(string $businessType, string $page = ''): string
    {
        $base = match ($businessType) {
            'accommodation' => '/business/accommodation',
            'food_beverage' => '/business/food-beverage',
            'retail_product', 'retail_shop' => '/business/retail-shop',
            default => '/business',
        };

        return $page === '' ? $base : "{$base}/{$page}";
    }

    /** Sent once the change it is about is saved: nothing goes out for a change that is rolled back. */
    private static function send(Collection $users, ActivityNotification $notification): void
    {
        DB::afterCommit(function () use ($users, $notification) {
            foreach ($users as $user) {
                try {
                    $user->notify(clone $notification);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });
    }
}

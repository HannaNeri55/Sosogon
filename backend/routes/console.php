<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Only runs where `php artisan schedule:run` is set up; logins also prune each user's
// expired tokens (AuthController::issueToken), so nothing depends on this.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Ends temporary suspensions whose period is over and notifies the user. Requests and
// logins already do this per user (EnsureAccountActive), so this only makes it timely.
Artisan::command('accounts:expire-suspensions', function () {
    $count = 0;
    App\Models\User::where('status', 'suspended')
        ->whereNotNull('suspended_until')
        ->where('suspended_until', '<=', now())
        ->whereNull('closed_at')
        ->each(function (App\Models\User $user) use (&$count) {
            $count += (int) app(App\Services\AccountEnforcementService::class)->expireSuspension($user);
        });
    $this->info("Restored {$count} account(s).");
})->purpose('Restore accounts whose temporary suspension has ended');

Schedule::command('accounts:expire-suspensions')->hourly();

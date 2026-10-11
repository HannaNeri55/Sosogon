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

<?php

namespace App\Providers;

use App\Models\User;
use App\Services\GeminiAIService;
use App\Services\GoogleMapsService;
use App\Services\ItineraryService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Bridge\Brevo\Transport\BrevoTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeminiAIService::class, fn () => new GeminiAIService);
        $this->app->singleton(GoogleMapsService::class, fn () => new GoogleMapsService);

        $this->app->singleton(ItineraryService::class, function ($app) {
            return new ItineraryService(
                $app->make(GeminiAIService::class),
                $app->make(GoogleMapsService::class),
            );
        });
    }

    public function boot(): void
    {
        // Admins and business owners are logged out after a stretch of inactivity, on top of
        // the token expiration everyone has. Sanctum calls this before it refreshes
        // last_used_at, so the check sees how long the token has actually sat unused.
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $isValid) {
            if (! $isValid) {
                return false;
            }

            $user = $token->tokenable;
            if (! $user instanceof User || ! ($user->isAdmin() || $user->isBusinessOwner())) {
                return true;
            }

            $lastActivity = $token->last_used_at ?? $token->created_at;

            return $lastActivity->gt(now()->subMinutes(config('sanctum.idle_timeout_privileged')));
        });

        // MAIL_MAILER=brevo: email over Brevo's HTTPS API instead of SMTP.
        Mail::extend('brevo', function () {
            return (new BrevoTransportFactory)->create(
                new Dsn('brevo+api', 'default', config('services.brevo.key'))
            );
        });
    }
}

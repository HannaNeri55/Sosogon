<?php

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use Anthropic\RequestOptions;
use App\Contracts\ItineraryAI;
use App\Models\User;
use App\Services\ClaudeAIService;
use App\Services\GeminiAIService;
use App\Services\GoogleMapsService;
use App\Services\ItineraryService;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
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

        // The AI behind itinerary generation, chosen by services.itinerary.ai_provider.
        $this->app->singleton(ItineraryAI::class, function ($app) {
            $provider = config('services.itinerary.ai_provider', 'gemini');

            return match ($provider) {
                'gemini' => $app->make(GeminiAIService::class),
                'claude' => $app->make(ClaudeAIService::class),
                default => throw new InvalidArgumentException("Unknown AI_PROVIDER \"{$provider}\". Supported: gemini, claude."),
            };
        });

        // Built only when AI_PROVIDER=claude asks for it. Guzzle enforces the timeout (the SDK's
        // own timeout option is advisory); a missing key is reported when generating, not here.
        $this->app->singleton(ClaudeAIService::class, function () {
            $config = config('services.anthropic');
            $timeout = (float) ($config['timeout'] ?? 120);

            $client = new AnthropicClient(
                apiKey: (string) ($config['api_key'] ?? ''),
                requestOptions: RequestOptions::with(
                    timeout: $timeout,
                    maxRetries: (int) ($config['max_retries'] ?? 1),
                    transporter: new GuzzleClient(['timeout' => $timeout, 'connect_timeout' => 10]),
                ),
                openTelemetry: false,
            );

            return new ClaudeAIService($client, (string) ($config['model'] ?? 'claude-sonnet-5-5'), $config['effort'] ?? null);
        });

        $this->app->singleton(ItineraryService::class, function ($app) {
            return new ItineraryService(
                $app->make(ItineraryAI::class),
                $app->make(GoogleMapsService::class),
            );
        });
    }

    public function boot(): void
    {
        // Controllers take these as int, so "/destinations/abc" would crash with a TypeError (500).
        // Requiring digits makes such URLs a plain 404 instead.
        Route::patterns(array_fill_keys(['id', 'image', 'imageId', 'index', 'item', 'product', 'rating', 'room'], '[0-9]+'));

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

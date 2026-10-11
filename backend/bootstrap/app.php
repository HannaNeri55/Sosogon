<?php

use App\Http\Middleware\EnsureBusinessType;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\TrackVisitorMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'business.type' => EnsureBusinessType::class,
            'track.visitor' => TrackVisitorMiddleware::class,
        ]);

        // Hosts like Render terminate HTTPS at their proxy; trusting it lets Laravel see the
        // original scheme, so $request->secure() (HSTS) and generated URLs use https.
        $middleware->trustProxies(at: '*');

        $middleware->append(SecurityHeadersMiddleware::class);

        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();

<?php

use App\Http\Middleware\EnsureBusinessType;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
            'owner.approved' => \App\Http\Middleware\EnsureOwnerApproved::class,
            'account.active' => \App\Http\Middleware\EnsureAccountActive::class,
            'admin.provincial' => \App\Http\Middleware\EnsureProvincialAdmin::class,
            'admin.scope' => \App\Http\Middleware\EnsureValidAdminScope::class,
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

        // findOrFail() misses would otherwise reach the user as "No query results for model
        // [App\Models\...] 12", and unknown URLs as "The route api/v1/... could not be found",
        // naming internals. Say it in plain words instead.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e->getPrevious() instanceof ModelNotFoundException) {
                return response()->json(['message' => 'This item could not be found. It may have been removed.'], 404);
            }

            // abort(404, '...') messages are written for users; only Laravel's own route miss is replaced.
            if ($e->getMessage() === '' || str_starts_with($e->getMessage(), 'The route ')) {
                return response()->json(['message' => 'This page could not be found.'], 404);
            }
        });
    })->create();

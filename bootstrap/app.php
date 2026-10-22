<?php

use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Middleware\EncryptHistory;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Let the web frontend call /api with its session cookie (Sanctum SPA authentication).
        $middleware->statefulApi();

        // A password change (including a reset) signs out the sessions that were open with the old one.
        $middleware->authenticateSessions();

        // JSON gets the headers that apply to it (no page policy), also on errors.
        $middleware->api(prepend: [
            ApiSecurityHeaders::class,
        ]);

        $middleware->web(prepend: [
            SecurityHeaders::class,
        ], append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            // The browser history of Inertia pages is encrypted; logout clears the key (see AuthenticatedSessionController).
            EncryptHistory::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

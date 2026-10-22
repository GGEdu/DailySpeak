<?php

use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\EnsureEmailVerifiedAndConsented;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Ai\AiCapReached;
use App\Services\Ai\AiNotConfigured;
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

        // Every route that needs a confirmed account (web and API) also needs the privacy notice accepted.
        $middleware->alias(['verified' => EnsureEmailVerifiedAndConsented::class]);

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

        // A missing connection or a used-up cap is the user's to fix, so the API says what to do (409 and 429 with a
        // code), and the page points at the settings (an error under the "ai" key, see App\Services\Ai\AiNotConfigured).
        $exceptions->render(function (AiNotConfigured $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage(), 'code' => AiNotConfigured::CODE], 409)
                : back()->withErrors(['ai' => $e->getMessage()]);
        });

        $exceptions->render(function (AiCapReached $e, Request $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage(), 'code' => AiCapReached::CODE], 429)
                : back()->withErrors(['ai' => $e->getMessage()]);
        });
    })->create();

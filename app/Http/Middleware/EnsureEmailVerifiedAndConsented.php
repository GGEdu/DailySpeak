<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's `verified` middleware, plus the privacy notice: a confirmed account may use the app only after it
 * accepted the notice in its current version. It replaces the `verified` alias, so every route that needs a
 * confirmed account is covered at once, the web pages and the API, and so is any route added later.
 */
class EnsureEmailVerifiedAndConsented extends EnsureEmailIsVerified
{
    public function handle($request, Closure $next, $redirectToRoute = null)
    {
        return parent::handle(
            $request,
            fn (Request $request) => $this->hasConsented($request) ? $next($request) : $this->consentRequired($request),
            $redirectToRoute,
        );
    }

    private function hasConsented(Request $request): bool
    {
        $user = $request->user();

        // Settings stays open, so a user who has not accepted yet can still download their data or delete the account.
        return ($user instanceof User && $user->hasCurrentConsent()) || $request->routeIs('settings.edit');
    }

    private function consentRequired(Request $request): Response
    {
        if ($request->expectsJson()) {
            abort(403, 'Accept the privacy notice to use DailySpeak.');
        }

        return to_route('consent.show');
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Providers\AppServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Show the login page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/Login');
    }

    /**
     * Log the user in.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Only failed sign-ins count, per email and address, so nobody else's guesses lock this account out.
        $failures = 'login-failures:'.Str::lower(trim($credentials['email'])).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($failures, AppServiceProvider::FAILED_LOGINS_PER_EMAIL_AND_ADDRESS_PER_HOUR)) {
            $seconds = RateLimiter::availableIn($failures);

            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]),
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($failures, 60 * 60);

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($failures);
        $request->session()->regenerate();

        return redirect()->intended(route('feed'));
    }

    /**
     * Log the user out.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // The next page forgets the key of the encrypted history, so "Back" cannot show the last account.
        Inertia::clearHistory();

        return to_route('home');
    }
}

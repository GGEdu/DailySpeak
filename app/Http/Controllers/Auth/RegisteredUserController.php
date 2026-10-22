<?php

namespace App\Http\Controllers\Auth;

use App\Enums\EnglishLevel;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ConsentController;
use App\Models\User;
use App\Services\Captcha\AltchaChallenges;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the sign-up page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/Register', [
            'levels' => array_column(EnglishLevel::cases(), 'value'),
        ]);
    }

    /**
     * Create the account and log the user in.
     */
    public function store(Request $request, AltchaChallenges $challenges): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'confirmed', Password::defaults()],
            'current_level' => ['required', Rule::enum(EnglishLevel::class)],
            // GDPR: the voice and the texts go to AI providers, so the user must accept that notice themselves.
            'consent' => ['accepted'],
        ], ['consent.accepted' => ConsentController::NOT_ACCEPTED]);

        // After the form itself is valid, so a typo in another field does not use up the human check.
        $challenges->ensureSolved($request->input('altcha'));

        $user = User::create(Arr::except($validated, ['consent']));
        $user->recordConsent();

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        // Nothing that costs AI calls is available until the address is confirmed.
        return to_route('verification.notice');
    }
}

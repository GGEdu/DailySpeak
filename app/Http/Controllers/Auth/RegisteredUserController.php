<?php

namespace App\Http\Controllers\Auth;

use App\Enums\EnglishLevel;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ConsentController;
use App\Models\User;
use App\Services\Captcha\AltchaChallenges;
use App\Services\Registration;
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
     * Show the sign-up page, or the notice that sign-up is closed (an admin opens it on /admin).
     */
    public function create(Registration $registration): Response
    {
        if (! $registration->isOpen()) {
            return Inertia::render('auth/RegistrationClosed');
        }

        return Inertia::render('auth/Register', [
            'levels' => array_column(EnglishLevel::cases(), 'value'),
        ]);
    }

    /**
     * Create the account and log the user in. While sign-up is closed the form is refused outright (403), before the
     * human check is even looked at. The first account of an install is its owner: an admin, already verified.
     */
    public function store(Request $request, AltchaChallenges $challenges, Registration $registration): RedirectResponse
    {
        abort_unless($registration->isOpen(), 403);

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

        // Null when sign-up closed while the form was open: nothing was written.
        $user = $registration->create(Arr::except($validated, ['consent']));
        abort_if($user === null, 403);

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        // The owner goes to the panel to set up the AI connection. Anyone else confirms the address first: nothing
        // that costs AI calls is available until then.
        return $user->is_admin ? to_route('admin.index') : to_route('verification.notice');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The privacy notice, for the accounts that signed up before it existed: they accept it once, and until then the
 * app asks for nothing else (see EnsureEmailVerifiedAndConsented).
 */
class ConsentController extends Controller
{
    public const NOT_ACCEPTED = 'Tick the box to accept the privacy notice.';

    /**
     * Show the notice and the box to accept it.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasCurrentConsent()) {
            return to_route('feed');
        }

        return Inertia::render('auth/Consent', ['version' => User::CONSENT_VERSION]);
    }

    /**
     * Record the acceptance with its date and the version of the notice.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['consent' => ['accepted']], ['consent.accepted' => self::NOT_ACCEPTED]);

        /** @var User $user */
        $user = $request->user();
        $user->recordConsent();

        return to_route('feed');
    }
}

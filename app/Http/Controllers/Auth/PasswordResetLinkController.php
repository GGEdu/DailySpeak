<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Captcha\AltchaChallenges;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    /**
     * Show the "forgot password" page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/ForgotPassword');
    }

    /**
     * Email a password reset link to the user.
     */
    public function store(Request $request, AltchaChallenges $challenges): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        // Every address this form is given gets an email, so it needs the human check too.
        $challenges->ensureSolved($request->input('altcha'));

        Password::sendResetLink($request->only('email'));

        // The same answer whether or not the account exists (or was just sent a link),
        // so the form cannot be used to find out who is registered.
        return back()->with('status', 'If an account exists for that email, we have sent it a link to reset the password.');
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * New accounts confirm their email before they can use anything that costs AI calls.
 */
class EmailVerificationController extends Controller
{
    /**
     * "Check your inbox" page, for signed-in users whose address is not verified yet.
     */
    public function notice(Request $request): Response|RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return to_route('feed');
        }

        return Inertia::render('auth/VerifyEmail', [
            'email' => $request->user()->email,
        ]);
    }

    /**
     * The signed link from the email (the request checks the user id and the address hash).
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return to_route('feed')->with('status', 'Your email is verified. Welcome to DailySpeak!');
    }

    /**
     * Send the verification email again.
     */
    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return to_route('feed');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'We sent you a new verification link.');
    }
}

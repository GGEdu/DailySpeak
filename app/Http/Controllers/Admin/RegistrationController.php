<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Registration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Opens or closes sign-up for new accounts (App\Services\Registration).
 */
class RegistrationController extends Controller
{
    public function update(Request $request, Registration $registration): RedirectResponse
    {
        $request->validate([
            'open' => ['required', 'boolean'],
        ], [
            'open.required' => 'Choose whether sign-up is open.',
            'open.boolean' => 'Choose whether sign-up is open.',
        ]);

        // boolean(), not (bool): the text "false" must close sign-up, not open it.
        $open = $request->boolean('open');

        $registration->setOpen($open, $request->user());

        return back()->with('status', $open ? 'Sign-up is open.' : 'Sign-up is closed.');
    }
}

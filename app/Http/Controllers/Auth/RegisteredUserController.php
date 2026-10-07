<?php

namespace App\Http\Controllers\Auth;

use App\Enums\EnglishLevel;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'confirmed', Password::defaults()],
            'current_level' => ['required', Rule::enum(EnglishLevel::class)],
        ]);

        $user = User::create($validated);

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('feed');
    }
}

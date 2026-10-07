<?php

namespace App\Http\Controllers;

use App\Enums\EnglishLevel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /**
     * Show the user's settings.
     */
    public function edit(): Response
    {
        return Inertia::render('Settings', [
            'levels' => collect(EnglishLevel::cases())->map(fn (EnglishLevel $level) => [
                'value' => $level->value,
                'label' => $level->label(),
                'description' => $level->description(),
            ]),
        ]);
    }

    /**
     * Change the English level the tutor speaks at.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_level' => ['required', Rule::enum(EnglishLevel::class)],
        ]);

        $request->user()->update($validated);

        return back()->with('status', "Your level is now {$validated['current_level']}. The tutor adapts from your next turn.");
    }
}

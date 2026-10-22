<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EnglishLevel;
use App\Http\Controllers\Controller;
use App\Services\Guided\GuidedSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Admin switch of the guided debate flow, one per English level (App\Services\Guided\GuidedSettings).
 */
class GuidedDebateController extends Controller
{
    /**
     * Turn the guided flow on or off for new debates at the level. Debates already started keep their flow.
     */
    public function update(Request $request, string $level, GuidedSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ], [
            'enabled.required' => 'Choose whether the guided flow is on.',
            'enabled.boolean' => 'Choose whether the guided flow is on.',
        ]);

        $settings->save(EnglishLevel::from($level), (bool) $validated['enabled'], $request->user());

        return back()->with('status', 'Guided debate setting saved. It applies to debates started from now on.');
    }
}

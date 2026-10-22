<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Privacy\Responsible;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin page for who is responsible for the data, shown on the privacy notice (App\Services\Privacy\Responsible).
 */
class PrivacySettingsController extends Controller
{
    public function edit(Responsible $responsible): Response
    {
        return Inertia::render('admin/Privacy', $responsible->settings());
    }

    public function update(Request $request, Responsible $responsible): RedirectResponse
    {
        $validated = $request->validate([
            'controller' => ['nullable', 'string', 'max:200'],
            'contact_email' => ['nullable', 'email', 'max:255'],
        ], [
            'contact_email.email' => 'Enter a valid address.',
        ]);

        $responsible->save($validated['controller'] ?? null, $validated['contact_email'] ?? null, $request->user());

        return back()->with('status', 'Privacy notice saved.');
    }
}

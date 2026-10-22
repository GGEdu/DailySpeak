<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AiBudget;
use App\Services\Guided\GuidedSettings;
use App\Services\Limits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin page to read and change the limits on the users' use of the app (App\Services\Limits).
 */
class LimitsController extends Controller
{
    public function index(Limits $limits, AiBudget $budget, GuidedSettings $guided): Response
    {
        return Inertia::render('admin/Limits', [
            'limits' => $limits->overview(),
            'guidedDebate' => $guided->overview(),
            'aiBudget' => [
                'used' => $budget->usedToday(),
                'limit' => $limits->value('ai_daily_global'),
                'resets_at' => $budget->resetsAt()->format('Y-m-d H:i T'),
            ],
        ]);
    }

    /**
     * Save a limit. It applies from the next request.
     */
    public function update(Request $request, string $key, Limits $limits): RedirectResponse
    {
        abort_unless(Limits::has($key), 404);

        $validated = $request->validate([
            'value' => ['required', 'integer', 'min:1', 'max:'.Limits::max($key)],
        ], [
            'value.required' => 'Enter a number.',
            'value.integer' => 'Use a whole number, without decimals.',
            'value.min' => 'The lowest value is 1.',
            'value.max' => 'The highest value is :max.',
        ]);

        $limits->save($key, (int) $validated['value'], $request->user());

        return back()->with('status', 'Limit saved. It applies from the next request.');
    }

    /**
     * Go back to the default in config/.
     */
    public function reset(Request $request, string $key, Limits $limits): RedirectResponse
    {
        abort_unless(Limits::has($key), 404);

        $limits->resetToDefault($key, $request->user());

        return back()->with('status', 'Limit set back to its default.');
    }
}

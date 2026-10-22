<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Services\Ai\AiCalls;
use App\Services\Ai\ProviderCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The user's AI usage this calendar month, without any content, and the monthly cap they set for themselves.
 */
class UsageController extends Controller
{
    /**
     * How many of the latest calls the page lists.
     */
    private const RECENT = 20;

    public function show(Request $request, AiCalls $calls): Response
    {
        $user = $request->user();
        $month = AiUsage::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', now()->startOfMonth());

        $totals = (clone $month)
            ->selectRaw(
                "task, count(*) as calls, sum(case when paid_by = 'user' then 1 else 0 end) as paid_by_user, "
                ."sum(case when paid_by = 'platform' then 1 else 0 end) as paid_by_platform, "
                .'sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens',
            )
            ->groupBy('task')
            ->get()
            ->keyBy('task');

        return Inertia::render('settings/Usage', [
            'month' => now()->format('Y-m'),
            'cap' => $user->ai_monthly_cap,
            'used' => $calls->usedThisMonth($user),
            'by_task' => collect(array_keys(ProviderCatalog::TASK_CAPABILITY))
                ->map(fn (string $task) => [
                    'task' => $task,
                    'calls' => (int) ($totals[$task]->calls ?? 0),
                    'paid_by_user' => (int) ($totals[$task]->paid_by_user ?? 0),
                    'paid_by_platform' => (int) ($totals[$task]->paid_by_platform ?? 0),
                    'input_tokens' => (int) ($totals[$task]->input_tokens ?? 0),
                    'output_tokens' => (int) ($totals[$task]->output_tokens ?? 0),
                ])
                ->values()
                ->all(),
            'recent' => AiUsage::query()
                ->where('user_id', $user->getKey())
                ->latest('created_at')
                ->latest('id')
                ->limit(self::RECENT)
                ->get(['task', 'provider', 'model', 'ok', 'created_at'])
                ->map(fn (AiUsage $row) => [
                    'task' => $row->task,
                    'provider' => $row->provider,
                    'model' => $row->model,
                    'ok' => $row->ok,
                    'created_at' => $row->created_at?->toIso8601String(),
                ])
                ->all(),
        ]);
    }

    /**
     * Set the monthly cap: a number of calls, or null for no cap.
     */
    public function updateCap(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cap' => ['present', 'nullable', 'integer', 'min:1', 'max:1000000'],
        ]);

        $request->user()->forceFill(['ai_monthly_cap' => $data['cap']])->save();

        return back()->with('status', $data['cap'] === null ? 'No monthly cap.' : 'Monthly cap saved.');
    }
}

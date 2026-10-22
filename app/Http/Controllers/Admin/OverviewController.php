<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NewsSource;
use App\Services\AiBudget;
use App\Services\AiSettings;
use App\Services\Limits;
use App\Services\News\SourcePolicyResult;
use App\Services\Privacy\Responsible;
use App\Services\Registration;
use Carbon\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin panel's front page: one card per area, each with what an admin needs to see at a glance.
 */
class OverviewController extends Controller
{
    public function index(AiSettings $ai, AiBudget $budget, Limits $limits, Registration $registration, Responsible $responsible): Response
    {
        return Inertia::render('admin/Index', [
            'cards' => [
                'ai' => [
                    'configured' => $ai->configured(),
                    'host' => $ai->host(),
                ],
                'budget' => [
                    'used' => $budget->usedToday(),
                    'limit' => $limits->value('ai_daily_global'),
                ],
                'sources' => $this->sources(),
                'registration' => [
                    'open' => $registration->isOpen(),
                ],
                'privacy' => [
                    'configured' => $responsible->configured(),
                ],
            ],
        ]);
    }

    /**
     * The feeds: how many there are, how many need a look (a failed fetch or a usage-policy warning), and when the
     * newest one was fetched.
     *
     * @return array{total: int, warnings: int, last_fetched_at: ?string}
     */
    private function sources(): array
    {
        $lastFetched = NewsSource::query()->max('last_fetched_at');

        return [
            'total' => NewsSource::query()->count(),
            'warnings' => NewsSource::query()
                ->whereNotNull('last_error')
                ->orWhere('policy_status', SourcePolicyResult::WARNING)
                ->count(),
            'last_fetched_at' => $lastFetched === null ? null : Carbon::parse($lastFetched)->toIso8601String(),
        ];
    }
}

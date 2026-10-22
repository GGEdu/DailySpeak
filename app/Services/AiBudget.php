<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The number of AI calls per day that all users share (the limit an admin sets on /admin/limits, by default
 * config limits.ai_daily_global). A controller takes one call from it right before the paid call it is about to
 * make, so a request refused earlier, or answered from a cache, costs nothing. Admins are exempt.
 *
 * The day is a calendar day in the application's timezone: the count starts again at midnight.
 */
class AiBudget
{
    public const USED_UP_MESSAGE = 'The daily AI budget is used up. Try again tomorrow.';

    public function __construct(private Limits $limits) {}

    /**
     * Take one call from the budget. Returns false when it is used up: the caller then refuses its request with
     * USED_UP_MESSAGE, and the reaching of the limit is logged once a day.
     */
    public function spend(?User $user): bool
    {
        if ($user?->is_admin) {
            return true;
        }

        $taken = RateLimiter::attempt(
            $this->key(),
            max(1, $this->limits->value('ai_daily_global')),
            fn () => true,
            $this->secondsUntilReset(),
        );

        if (! $taken && Cache::add('ai-budget-exhausted:'.now()->toDateString(), true, now()->endOfDay())) {
            Log::critical('The daily AI call budget is used up. AI routes answer 429 until it resets.');
        }

        return $taken === true;
    }

    /**
     * The calls taken today, by everyone who is not an admin.
     */
    public function usedToday(): int
    {
        return (int) RateLimiter::attempts($this->key());
    }

    /**
     * When today's count starts again.
     */
    public function resetsAt(): Carbon
    {
        return now()->startOfDay()->addDay();
    }

    /**
     * Take one call from the budget, or throw AiBudgetUsedUp. For a paid call that only happens on a cache miss:
     * pass this as the callback that runs right before it, so an answer from the cache costs nothing.
     */
    public function spendOrFail(?User $user): void
    {
        if (! $this->spend($user)) {
            throw new AiBudgetUsedUp;
        }
    }

    private function key(): string
    {
        return 'ai-budget:'.now()->toDateString();
    }

    private function secondsUntilReset(): int
    {
        return max(1, $this->resetsAt()->getTimestamp() - now()->getTimestamp());
    }
}

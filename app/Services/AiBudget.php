<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The daily number of AI calls that all users share (config limits.ai_daily_global). A controller takes one call
 * from it right before the paid call it is about to make, so a request refused earlier, or answered from a cache,
 * costs nothing. Admins are exempt.
 */
class AiBudget
{
    public const USED_UP_MESSAGE = 'The daily AI budget is used up. Try again tomorrow.';

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
            'ai-budget',
            max(1, (int) config('limits.ai_daily_global')),
            fn () => true,
            60 * 60 * 24,
        );

        if (! $taken && Cache::add('ai-budget-exhausted:'.now()->toDateString(), true, now()->endOfDay())) {
            Log::critical('The daily AI call budget is used up. AI routes answer 429 until it resets.');
        }

        return $taken === true;
    }
}

<?php

namespace App\Services\Guided;

use App\Events\DebateBriefFailed;
use App\Events\DebateBriefReady;
use App\Jobs\OpenGuidedDebate;
use App\Jobs\PrepareDebateBrief;
use App\Models\Debate;
use App\Models\DebateBrief;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The state of a guided debate's brief, as the Debate page shows it: preparing, ready or failed, and why it failed.
 *
 * The brief itself is stored once per article and level. Its state (preparing or failed, with the reason) is kept
 * per article and level too, in the cache, so that a page reload still shows the reason of a failure. A missing state
 * with no brief reads as failed: the learner can then retry it.
 */
class DebateBriefs
{
    public const PREPARING = 'preparing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    private const PREPARING_MINUTES = 30;

    private const FAILED_HOURS = 24;

    /**
     * The brief's status for the page: null for a classic debate.
     */
    public function status(Debate $debate): ?string
    {
        if (! $debate->isGuided()) {
            return null;
        }

        if (DebateBrief::forDebate($debate) !== null) {
            return self::READY;
        }

        return $this->state($debate)['status'] ?? self::FAILED;
    }

    /**
     * Why the brief failed, for the page: "budget" or "failed". Null unless the brief failed.
     */
    public function failureReason(Debate $debate): ?string
    {
        if ($this->status($debate) !== self::FAILED) {
            return null;
        }

        return $this->state($debate)['reason'] ?? DebateBriefFailed::FAILED;
    }

    /**
     * The brief as the page shows it, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function brief(Debate $debate): ?array
    {
        return DebateBrief::forDebate($debate)?->payload();
    }

    /**
     * Start on a guided debate: prepare its brief, or open the debate at once when the brief is already written.
     */
    public function prepare(Debate $debate): void
    {
        if (DebateBrief::forDebate($debate) !== null) {
            $this->open($debate);

            return;
        }

        $this->remember($debate, self::PREPARING, null);

        try {
            PrepareDebateBrief::dispatch($debate);
        } catch (Throwable $e) {
            report($e);

            $this->failed($debate, DebateBriefFailed::FAILED);
        }
    }

    /**
     * The learner's retry: open the debate when the brief exists, prepare it again when it failed. A brief that is
     * being prepared is left alone, so a retry does not pay twice.
     */
    public function retry(Debate $debate): void
    {
        if (DebateBrief::forDebate($debate) !== null || $this->status($debate) === self::FAILED) {
            $this->prepare($debate);
        }
    }

    /**
     * The brief is written: say so, and the debate can open.
     */
    public function ready(Debate $debate, DebateBrief $brief): void
    {
        Cache::forget($this->key($debate));

        rescue(fn () => DebateBriefReady::dispatch($debate, $brief));
    }

    /**
     * The brief could not be written. The reason is kept, so the page can say why after a reload.
     */
    public function failed(Debate $debate, string $reason): void
    {
        $this->remember($debate, self::FAILED, $reason);

        Log::warning('The guided debate brief failed.', ['debate_id' => $debate->getKey(), 'reason' => $reason]);

        rescue(fn () => DebateBriefFailed::dispatch($debate, $reason));
    }

    private function open(Debate $debate): void
    {
        try {
            OpenGuidedDebate::dispatch($debate);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array{status: string, reason: ?string}|null
     */
    private function state(Debate $debate): ?array
    {
        return Cache::get($this->key($debate));
    }

    private function remember(Debate $debate, string $status, ?string $reason): void
    {
        $until = $status === self::PREPARING
            ? now()->addMinutes(self::PREPARING_MINUTES)
            : now()->addHours(self::FAILED_HOURS);

        Cache::put($this->key($debate), ['status' => $status, 'reason' => $reason], $until);
    }

    private function key(Debate $debate): string
    {
        return 'debate-brief-state:'.$debate->news_article_id.':'.($debate->level?->value ?? 'none');
    }
}

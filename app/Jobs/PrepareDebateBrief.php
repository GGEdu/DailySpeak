<?php

namespace App\Jobs;

use App\Events\DebateBriefFailed;
use App\Models\Debate;
use App\Models\DebateBrief;
use App\Services\AiBudgetUsedUp;
use App\Services\Guided\BriefWriter;
use App\Services\Guided\DebateBriefs;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the brief of a guided debate's article at the debate's level, then opens the debate on it.
 *
 * One brief serves every debate on the article at that level: the job runs one at a time per article and level, so a
 * second debate waits for the first and then finds the brief written. It is not unique: each debate needs its own job
 * to open it.
 */
class PrepareDebateBrief implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run: one brief call (config debate.guided.brief.timeout) and the checks.
     */
    public int $timeout = 90;

    public function __construct(public Debate $debate)
    {
        $this->onQueue(config('debate.queue'));
    }

    /**
     * The lock is per article and level, so the briefs of different levels are written side by side.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->lockKey()))->releaseAfter(5)->expireAfter(180)];
    }

    /**
     * Determine the time at which the job should stop being retried.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    /**
     * Execute the job.
     */
    public function handle(BriefWriter $writer, DebateBriefs $briefs): void
    {
        $debate = $this->debate->fresh();

        if ($debate === null || ! $debate->isGuided()) {
            return;
        }

        $brief = DebateBrief::forDebate($debate);

        if ($brief === null) {
            try {
                $brief = $writer->write($debate);
            } catch (AiBudgetUsedUp) {
                $briefs->failed($debate, DebateBriefFailed::BUDGET);

                return;
            } catch (Throwable $e) {
                report($e);
                Log::warning('The guided debate brief could not be written.', [
                    'debate_id' => $debate->getKey(),
                    'exception' => $e::class,
                ]);

                $briefs->failed($debate, DebateBriefFailed::FAILED);

                return;
            }
        }

        $briefs->ready($debate, $brief);

        // The debate opens on its own brief, whether this job wrote it or found it written by another debate's job.
        rescue(fn () => OpenGuidedDebate::dispatch($debate));
    }

    /**
     * Handle a job failure. A brief that was written stays; otherwise the failure is recorded for the page.
     */
    public function failed(?Throwable $exception): void
    {
        $debate = $this->debate->fresh();

        if ($debate === null || DebateBrief::forDebate($debate) !== null) {
            return;
        }

        rescue(fn () => app(DebateBriefs::class)->failed($debate, DebateBriefFailed::FAILED));
    }

    private function lockKey(): string
    {
        return 'debate-brief:'.$this->debate->news_article_id.':'.($this->debate->level?->value ?? 'none');
    }
}

<?php

namespace App\Jobs;

use App\Events\LearnerTurnReviewed;
use App\Models\DebateMessage;
use App\Services\Guided\LearnerCorrections;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reviews one learner turn of a guided debate in writing. It is queued as soon as the turn is transcribed, on the
 * default queue, so it runs beside the tutor's answer and never delays the voice. It is not run again for a turn that
 * has its corrections already (a turn is paid for once).
 */
class ReviewLearnerTurn implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run: one review call (config debate.guided.review.timeout) and the store.
     */
    public int $timeout = 60;

    /**
     * Seconds the unique lock is held, so a duplicate queued for the same turn is dropped.
     */
    public int $uniqueFor = 300;

    public function __construct(public DebateMessage $turn)
    {
        $this->onQueue('default');
    }

    /**
     * One review per turn.
     */
    public function uniqueId(): string
    {
        return (string) $this->turn->getKey();
    }

    /**
     * Execute the job.
     */
    public function handle(LearnerCorrections $corrections): void
    {
        $turn = $this->turn->fresh(['debate.user']);

        // The turn may have been dropped (its voice turn failed), or reviewed by an earlier attempt.
        if ($turn === null || $turn->corrections !== null) {
            return;
        }

        $stored = $corrections->store($turn, $corrections->review($turn));

        // Nothing was stored: the turn was reviewed by another attempt meanwhile.
        if ($stored === null) {
            return;
        }

        rescue(fn () => LearnerTurnReviewed::dispatch($turn->debate, $turn, $stored));
    }

    /**
     * A review that could not run leaves the turn with no corrections, as a refused one does, so the page settles.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('The learner turn review failed.', [
            'message_id' => $this->turn->getKey(),
            'exception' => $exception === null ? null : $exception::class,
        ]);

        rescue(function () {
            $turn = $this->turn->fresh(['debate.user']);

            if ($turn !== null && $turn->corrections === null) {
                $corrections = app(LearnerCorrections::class);
                $stored = $corrections->store($turn, []);

                if ($stored !== null) {
                    LearnerTurnReviewed::dispatch($turn->debate, $turn, []);
                }
            }
        });
    }
}

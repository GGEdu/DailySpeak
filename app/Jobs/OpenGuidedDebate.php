<?php

namespace App\Jobs;

use App\Enums\MessageRole;
use App\Events\TutorOpened;
use App\Models\Debate;
use App\Models\DebateBrief;
use App\Services\Debate\TutorVoice;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes the tutor's opening message of a guided debate (the framing and the debate question from its brief) and
 * speaks it. It runs under the same lock as the debate's voice turns, and does nothing once the debate has messages.
 */
class OpenGuidedDebate implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run: one speech synthesis, the backup voice and the broadcast.
     */
    public int $timeout = 90;

    public function __construct(public Debate $debate)
    {
        $this->onQueue(config('debate.queue'));
    }

    /**
     * Get the middleware the job should pass through. Shared with ProcessVoiceDebate, so an opening never interleaves
     * with a turn: whichever runs first, the other one sees its message.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->debate->jobLockKey()))->shared()->releaseAfter(1)->expireAfter(180)];
    }

    /**
     * Determine the time at which the job should stop being retried.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(5);
    }

    /**
     * Execute the job.
     */
    public function handle(TutorVoice $voice): void
    {
        $debate = $this->debate->fresh();

        // The opening is the first message of the debate. A debate that already has messages (the learner spoke
        // first, or this job ran before) is left as it is.
        if ($debate === null || ! $debate->isActive() || $debate->messages()->exists()) {
            return;
        }

        $brief = DebateBrief::forDebate($debate);

        if ($brief === null) {
            Log::warning('A guided debate has no brief to open with.', ['debate_id' => $debate->getKey()]);

            return;
        }

        $message = $debate->messages()->create([
            'role' => MessageRole::Assistant,
            'transcript' => $brief->opening,
            'audio_path' => $this->speak($voice, $debate, $brief->opening),
        ]);

        // The opening is stored, so a broadcasting outage must not run it again. The client can reload the history.
        rescue(fn () => TutorOpened::dispatch($debate, $message));
    }

    /**
     * The opening's audio path, or null when no voice could speak it: the text is still shown.
     */
    private function speak(TutorVoice $voice, Debate $debate, string $text): ?string
    {
        try {
            return $voice->synthesise($debate, $text);
        } catch (Throwable $e) {
            report($e);

            Log::warning('The guided debate opening could not be spoken; it is shown as text.', [
                'debate_id' => $debate->getKey(),
                'exception' => $e::class,
            ]);

            return null;
        }
    }
}

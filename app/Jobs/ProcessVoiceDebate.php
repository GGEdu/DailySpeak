<?php

namespace App\Jobs;

use App\Ai\Agents\DebateTutor;
use App\Enums\MessageRole;
use App\Events\AIResponseGenerated;
use App\Events\DebateTurnFailed;
use App\Events\UserTurnTranscribed;
use App\Models\Debate;
use App\Models\DebateMessage;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Laravel\Ai\Audio;
use Laravel\Ai\Transcription;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * Answers one voice turn: speech-to-text, tutor reply and text-to-speech.
 */
class ProcessVoiceDebate implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run (STT + LLM + TTS round trips).
     */
    public int $timeout = 120;

    /**
     * The number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 2;

    /**
     * The number of seconds to wait before retrying after an exception.
     */
    public int $backoff = 3;

    /**
     * Create a new job instance.
     *
     * @param  string  $audioPath  Path of the user's recording on the debate audio disk.
     */
    public function __construct(
        public Debate $debate,
        public string $audioPath,
    ) {
        $this->onQueue(config('debate.queue'));
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // Answer one turn at a time per debate so the conversation history stays in order.
        return [(new WithoutOverlapping($this->debate->jobLockKey()))->shared()->releaseAfter(3)->expireAfter(180)];
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
    public function handle(): void
    {
        $turn = $this->userTurn();

        if ($turn === null) {
            $this->notifyFailure(DebateTurnFailed::NO_SPEECH);

            return;
        }

        // Show the user what was understood while the tutor is still thinking.
        rescue(fn () => UserTurnTranscribed::dispatch($turn));

        $reply = $this->reply($turn);

        $message = $this->debate->messages()->create([
            'role' => MessageRole::Assistant,
            'transcript' => $reply,
            'audio_path' => $this->synthesise($reply),
        ]);

        // The reply is already stored, so a broadcasting outage must not trigger a retry
        // (and a second, different answer). Clients can reload the history instead.
        rescue(fn () => AIResponseGenerated::dispatch($message, $turn));
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        $this->notifyFailure(DebateTurnFailed::PROCESSING_FAILED);
    }

    /**
     * Transcribe the recording into a user message, reusing it if a previous attempt already did.
     */
    private function userTurn(): ?DebateMessage
    {
        $existing = $this->debate->messages()
            ->where('role', MessageRole::User)
            ->where('audio_path', $this->audioPath)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $transcript = trim(Transcription::fromStorage($this->audioPath, config('debate.audio.disk'))
            ->language(config('debate.stt.language'))
            ->generate(config('debate.stt.provider'), config('debate.stt.model'))
            ->text);

        if ($transcript === '') {
            return null;
        }

        return $this->debate->messages()->create([
            'role' => MessageRole::User,
            'transcript' => $transcript,
            'audio_path' => $this->audioPath,
        ]);
    }

    /**
     * Ask the tutor for its reply to the given turn.
     */
    private function reply(DebateMessage $turn): string
    {
        $reply = trim((new DebateTutor($this->debate, $turn))->prompt($turn->transcript)->text);

        if ($reply === '') {
            throw new UnexpectedValueException('The tutor returned an empty reply.');
        }

        return $reply;
    }

    /**
     * Synthesise the reply to an MP3 file and return its storage path.
     */
    private function synthesise(string $text): string
    {
        $path = Audio::of($text)
            ->voice(config('debate.tts.voice'))
            ->generate(config('debate.tts.provider'), config('debate.tts.model'))
            ->storeAs("debates/{$this->debate->id}/replies", Str::uuid().'.mp3', config('debate.audio.disk'));

        if (! is_string($path)) {
            throw new RuntimeException('The synthesised reply could not be stored.');
        }

        return $path;
    }

    private function notifyFailure(string $reason): void
    {
        rescue(fn () => DebateTurnFailed::dispatch($this->debate, $reason));
    }
}

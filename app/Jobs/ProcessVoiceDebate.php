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
use Illuminate\Support\Benchmark;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
     * How long a tutor reply is kept so a retry can reuse it. Longer than retryUntil(), so every retry sees it.
     */
    private const REPLY_TTL_MINUTES = 10;

    /**
     * The number of seconds the job can run (STT + LLM + TTS round trips). The per-request timeouts in
     * config/debate.php (stt, llm, tts) must add up to less than this, or the job is killed mid-turn.
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
     * When the turn was sent (Unix time with microseconds), to measure the full wait.
     */
    public float $dispatchedAt;

    /**
     * Create a new job instance.
     *
     * @param  string  $audioPath  Path of the user's recording on the debate audio disk.
     */
    public function __construct(
        public Debate $debate,
        public string $audioPath,
    ) {
        $this->dispatchedAt = microtime(true);
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
        // The debate may have been finished while this turn waited in the queue. Its report is already
        // written, so the turn is dropped: no message is added and no reply is sent.
        if (! $this->debate->fresh()?->isActive()) {
            return;
        }

        $startedAt = microtime(true);

        [$turn, $sttMs] = Benchmark::value(fn () => $this->userTurn());

        if ($turn === null) {
            $this->notifyFailure(DebateTurnFailed::NO_SPEECH);

            return;
        }

        // Show the user what was understood while the tutor is still thinking.
        rescue(fn () => UserTurnTranscribed::dispatch($turn));

        [$reply, $llmMs] = Benchmark::value(fn () => $this->reply($turn));
        [$audioPath, $ttsMs] = Benchmark::value(fn () => $this->synthesise($reply));

        $message = $this->debate->messages()->create([
            'role' => MessageRole::Assistant,
            'transcript' => $reply,
            'audio_path' => $audioPath,
        ]);

        Cache::forget($this->replyCacheKey($turn));

        // The reply is already stored, so a broadcasting outage must not trigger a retry
        // (and a second, different answer). Clients can reload the history instead.
        rescue(fn () => AIResponseGenerated::dispatch($message, $turn));

        // How long the user waited for the answer, and where the time went.
        Log::info('Voice turn answered.', [
            'debate_id' => $this->debate->id,
            'message_id' => $message->id,
            'queue_ms' => (int) round(($startedAt - $this->dispatchedAt) * 1000),
            'stt_ms' => (int) round($sttMs),
            'llm_ms' => (int) round($llmMs),
            'tts_ms' => (int) round($ttsMs),
            'total_ms' => (int) round((microtime(true) - $this->dispatchedAt) * 1000),
            'llm' => config('debate.llm.provider').'/'.(config('debate.llm.model') ?? 'default'),
        ]);
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
            ->timeout(config('debate.stt.timeout'))
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
     * Ask the tutor for its reply to the given turn. A retry after a failed synthesis reuses the reply
     * already produced, so it costs no second LLM call and the user gets the answer that was spoken.
     */
    private function reply(DebateMessage $turn): string
    {
        $cached = Cache::get($this->replyCacheKey($turn));

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $reply = trim((new DebateTutor($this->debate, $turn))->prompt($turn->transcript)->text);

        if ($reply === '') {
            throw new UnexpectedValueException('The tutor returned an empty reply.');
        }

        Cache::put($this->replyCacheKey($turn), $reply, now()->addMinutes(self::REPLY_TTL_MINUTES));

        return $reply;
    }

    private function replyCacheKey(DebateMessage $turn): string
    {
        return 'debate-reply:'.$turn->getKey();
    }

    /**
     * Synthesise the reply to an MP3 file and return its storage path.
     */
    private function synthesise(string $text): string
    {
        $path = Audio::of($text)
            ->voice(config('debate.tts.voice'))
            ->timeout(config('debate.tts.timeout'))
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

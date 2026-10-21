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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Audio;
use Laravel\Ai\Responses\AudioResponse;
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
     * Seconds of the turn spent outside the provider calls and the broadcasts (database, storage, cache).
     * The budget in config/debate.php must leave room for them.
     */
    public const OVERHEAD_SECONDS = 3;

    /**
     * The number of seconds the job can run (STT + LLM + TTS round trips, the backup voice, two broadcasts and
     * OVERHEAD_SECONDS). The per-request timeouts in config/debate.php must add up to less than this, or the job
     * is killed mid-turn.
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
        // Answer one turn at a time per debate so the conversation history stays in order. A turn that finds
        // another one running is released for a second, not parked for minutes.
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
    public function handle(): void
    {
        // The debate may have been finished while this turn waited in the queue. Its report is already
        // written, so the turn is dropped: no message is added and no reply is sent.
        if (! $this->debate->fresh()?->isActive()) {
            $this->dropUnansweredTurn();
            $this->notifyFailure(DebateTurnFailed::DEBATE_ENDED);

            return;
        }

        $startedAt = microtime(true);

        [$turn, $sttMs] = Benchmark::value(fn () => $this->userTurn());

        if ($turn === null) {
            $this->discardRecording();
            $this->notifyFailure(DebateTurnFailed::NO_SPEECH);

            return;
        }

        // A reply that is already stored answers this turn: an earlier attempt got that far and then stopped
        // (a timeout, a lost connection). Send it again instead of paying for a second answer.
        if ($answer = $this->answer($turn)) {
            rescue(fn () => AIResponseGenerated::dispatch($answer, $turn));

            Log::info('Voice turn was already answered; the stored reply was broadcast again.', $this->context([
                'message_id' => $answer->id,
            ]));

            return;
        }

        // Show the user what was understood while the tutor is still thinking.
        rescue(fn () => UserTurnTranscribed::dispatch($turn));

        [$reply, $llmMs] = Benchmark::value(fn () => $this->reply($turn));
        [[$audioPath, $backupVoice], $ttsMs] = Benchmark::value(fn () => $this->synthesise($reply));

        $message = $this->debate->messages()->create([
            'role' => MessageRole::Assistant,
            'transcript' => $reply,
            'audio_path' => $audioPath,
            // The unique index on reply_to_id allows one reply per turn, even if two attempts race.
            'reply_to_id' => $turn->getKey(),
        ]);

        Cache::forget($this->replyCacheKey($turn));

        // The reply is already stored, so a broadcasting outage must not trigger a retry
        // (and a second, different answer). Clients can reload the history instead.
        rescue(fn () => AIResponseGenerated::dispatch($message, $turn));

        // How long the user waited for the answer, and where the time went.
        Log::info('Voice turn answered.', $this->context([
            'message_id' => $message->id,
            'queue_ms' => (int) round(($startedAt - $this->dispatchedAt) * 1000),
            'stt_ms' => (int) round($sttMs),
            'llm_ms' => (int) round($llmMs),
            'tts_ms' => (int) round($ttsMs),
            'total_ms' => (int) round((microtime(true) - $this->dispatchedAt) * 1000),
            'stt' => config('debate.stt.provider'),
            'llm' => config('debate.llm.provider').'/'.(config('debate.llm.model') ?? 'default'),
            'tts' => config('debate.tts.provider'),
            'tts_backup' => $backupVoice,
        ]));
    }

    /**
     * Handle a job failure. Only the final failure drops the turn (its message and recording), and the client is
     * told so the user can speak again. While retries remain, the next attempt reuses both.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Voice turn failed.', $this->context([
            'exception' => $exception === null ? null : $exception::class,
        ]));

        $this->dropUnansweredTurn();
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
     * The tutor's stored reply to the turn, if one was saved.
     */
    private function answer(DebateMessage $turn): ?DebateMessage
    {
        return $turn->reply()->first();
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

        $tutor = new DebateTutor($this->debate, $turn);
        $reply = trim($tutor->prompt($tutor->question())->text);

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
     * Synthesise the reply to an MP3 file, and return its storage path and whether the backup voice made it.
     *
     * @return array{0: string, 1: bool}
     */
    private function synthesise(string $text): array
    {
        $backupVoice = false;

        try {
            $audio = $this->speak($text, config('debate.tts'));
        } catch (Throwable $e) {
            if (config('debate.tts.fallback.model') === null) {
                throw $e;
            }

            // The user still hears the reply, in the backup voice; the failure stays in the log.
            Log::warning('Main voice failed; answering with the backup voice.', $this->context([
                'tts' => config('debate.tts.provider'),
                'exception' => $e::class,
            ]));
            report($e);

            $audio = $this->speak($text, config('debate.tts.fallback'));
            $backupVoice = true;
        }

        $path = $audio->storeAs("debates/{$this->debate->id}/replies", Str::uuid().'.mp3', config('debate.audio.disk'));

        if (! is_string($path)) {
            throw new RuntimeException('The synthesised reply could not be stored.');
        }

        return [$path, $backupVoice];
    }

    /**
     * @param  array{provider: string, model: ?string, voice: string, timeout: int}  $tts
     */
    private function speak(string $text, array $tts): AudioResponse
    {
        return Audio::of($text)
            ->voice($tts['voice'])
            ->timeout($tts['timeout'])
            ->generate($tts['provider'], $tts['model']);
    }

    /**
     * Delete the user message of this turn when no reply was stored for it, together with its recording. The
     * history then goes on alternating roles, and a turn the user repeats is transcribed afresh.
     */
    private function dropUnansweredTurn(): void
    {
        $turn = $this->debate->messages()
            ->where('role', MessageRole::User)
            ->where('audio_path', $this->audioPath)
            ->first();

        // An answered turn keeps its recording: its reply and the history refer to it.
        if ($turn !== null && $this->answer($turn) !== null) {
            return;
        }

        $turn?->delete();
        $this->discardRecording();
    }

    /**
     * Delete this turn's recording: the turn is over and its audio is not needed any more.
     */
    private function discardRecording(): void
    {
        rescue(fn () => Storage::disk(config('debate.audio.disk'))->delete($this->audioPath));
    }

    private function notifyFailure(string $reason): void
    {
        rescue(fn () => DebateTurnFailed::dispatch($this->debate, $reason, $this->turnId()));
    }

    /**
     * The voice turn this job answers: the recording's name without the extension (the id the client was given).
     */
    private function turnId(): string
    {
        return pathinfo($this->audioPath, PATHINFO_FILENAME);
    }

    /**
     * The fields every log line about this turn carries.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function context(array $extra = []): array
    {
        return [
            'debate_id' => $this->debate->getKey(),
            'turn' => $this->turnId(),
            ...$extra,
        ];
    }
}

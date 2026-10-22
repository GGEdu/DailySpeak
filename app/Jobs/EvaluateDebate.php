<?php

namespace App\Jobs;

use App\Ai\Agents\DebateEvaluator;
use App\Enums\MessageRole;
use App\Events\DebateEvaluated;
use App\Events\DebateEvaluationFailed;
use App\Models\Debate;
use App\Services\Ai\AiCalls;
use App\Services\Ai\AiConnection;
use App\Services\Guided\LearnerCorrections;
use App\Services\Vocabulary\WordLookup;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * Post-session fluency analysis (Architecture.md, flow C): feedback on the user's
 * turns, stored in debates.ai_feedback, and recommended words added to their vocabulary.
 */
class EvaluateDebate implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Feedback for a debate in which the user never spoke.
     */
    public const EMPTY_FEEDBACK = ['crutch_words' => [], 'grammar_errors' => [], 'recommended_vocabulary' => []];

    /**
     * Function words models sometimes list as "crutch words"; they are not useful feedback.
     */
    private const FUNCTION_WORDS = [
        'a', 'an', 'the', 'and', 'or', 'but', 'so', 'if', 'of', 'in', 'on', 'at', 'to', 'for', 'with', 'from', 'by', 'as',
        'is', 'are', 'was', 'were', 'be', 'been', 'am', 'do', 'does', 'did', 'have', 'has', 'had', 'will', 'would',
        'i', 'you', 'he', 'she', 'it', 'we', 'they', 'me', 'my', 'your', 'our', 'their', 'this', 'that', 'these', 'those',
    ];

    /**
     * Longest word kept from the answer. Matches the limit on words saved by the user (SaveWordController).
     */
    private const MAX_WORD_CHARACTERS = 200;

    /**
     * How long a model answer is kept for a retry of the same transcript (the retry window is 10 minutes).
     */
    private const ANSWER_CACHE_MINUTES = 15;

    /**
     * Seconds the job can run. The evaluator's timeout (config/debate.php, evaluator.timeout) must stay below this.
     */
    public int $timeout = 90;

    public int $maxExceptions = 2;

    public int $backoff = 5;

    /**
     * Seconds the unique lock is held. A second request for the same debate inside this window is dropped,
     * so a double click on "Finish" does not pay for two reports. It matches retryUntil(), so the lock cannot
     * outlive the job's retries, and it is released as soon as the job succeeds or finally fails.
     */
    public int $uniqueFor = 600;

    public function __construct(public Debate $debate)
    {
        $this->onQueue(config('debate.queue'));
    }

    /**
     * The unique lock is per debate.
     */
    public function uniqueId(): string
    {
        return (string) $this->debate->getKey();
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // Runs after any voice turn that is still being answered (same lock as ProcessVoiceDebate).
        return [(new WithoutOverlapping($this->debate->jobLockKey()))->shared()->releaseAfter(1)->expireAfter(180)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    /**
     * Execute the job.
     */
    public function handle(LearnerCorrections $corrections, AiCalls $calls): void
    {
        // A report is written once. A duplicate that got past the unique lock, or a retry queued after the
        // report was already saved, must not pay for a second evaluation.
        $current = $this->debate->fresh();

        if ($current === null || $current->ai_feedback !== null) {
            return;
        }

        $turns = $this->recentTurns();
        $feedback = $turns->isEmpty() ? self::EMPTY_FEEDBACK : $this->evaluate($turns, $calls);

        // The corrections written during the debate are part of its report, beside the evaluator's own.
        $feedback['grammar_errors'] = $corrections->mergeIntoReport($this->debate, $feedback['grammar_errors']);

        // The words and the report are saved together: if either fails, both roll back, so a retry starts
        // from the same deck and reports the same added words.
        $addedWords = DB::transaction(function () use ($feedback) {
            $added = $this->rememberVocabulary($feedback['recommended_vocabulary']);

            $this->debate->update(['ai_feedback' => $feedback]);

            return $added;
        });

        Log::info('Debate evaluated.', [
            'debate_id' => $this->debate->getKey(),
            'turns' => $turns->count(),
            'words_added' => count($addedWords),
        ]);

        // The report is stored; a broadcasting outage must not trigger a second evaluation.
        rescue(fn () => DebateEvaluated::dispatch($this->debate, $addedWords));
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Debate evaluation failed.', [
            'debate_id' => $this->debate->getKey(),
            'exception' => $exception === null ? null : $exception::class,
        ]);

        rescue(fn () => DebateEvaluationFailed::dispatch($this->debate));
    }

    /**
     * The learner's latest turns, oldest first, within the evaluator's turn and character limits. A turn is kept
     * whole, except that a single turn longer than the limit is cut to it.
     *
     * @return Collection<int, string>
     */
    private function recentTurns(): Collection
    {
        $limit = (int) config('debate.evaluator.max_characters');

        $latest = $this->debate->messages()
            ->where('role', MessageRole::User)
            ->latest('id')
            ->limit((int) config('debate.evaluator.max_turns'))
            ->pluck('transcript');

        $kept = [];
        $characters = 0;

        foreach ($latest as $transcript) {
            $length = mb_strlen($transcript);

            if ($kept !== [] && $characters + $length > $limit) {
                break;
            }

            $kept[] = mb_substr($transcript, 0, $limit);
            $characters += $length;
        }

        return collect(array_reverse($kept))->values();
    }

    /**
     * Ask the evaluator for feedback and keep only well-formed entries. The answer is cached for the retry window,
     * so a retry after a failed save reuses it instead of paying for the model again. An answer with nothing
     * usable throws and is not cached, so the retry asks again.
     *
     * @param  Collection<int, string>  $turns
     * @return array{crutch_words: list<string>, grammar_errors: list<array{error: string, correction: string}>, recommended_vocabulary: list<array{word: string, context: string}>}
     */
    private function evaluate(Collection $turns, AiCalls $calls): array
    {
        $transcript = $turns->map(fn (string $turn, int $index) => ($index + 1).'. '.$turn)->implode("\n");

        return Cache::remember(
            'debate-evaluation:'.$this->debate->getKey().':'.sha1($transcript),
            now()->addMinutes(self::ANSWER_CACHE_MINUTES),
            function () use ($transcript, $calls) {
                // The learner's own connection for writing pays for the report (see App\Services\Ai\AiCalls).
                $feedback = $this->clean($calls->forUser(
                    $this->debate->user,
                    'writing',
                    fn (AiConnection $connection) => (new DebateEvaluator($this->debate->user->current_level, $connection))->prompt(
                        "Debate topic: {$this->debate->newsArticle->title}\n\nLearner's turns:\n{$transcript}"
                    ),
                    fn ($response) => AiCalls::textUsage($response),
                ));

                if ($feedback['crutch_words'] === [] && $feedback['grammar_errors'] === [] && $feedback['recommended_vocabulary'] === []) {
                    throw new UnexpectedValueException('The evaluator returned no usable feedback.');
                }

                return $feedback;
            },
        );
    }

    /**
     * Keep only the well-formed entries of the model's answer, within the limits.
     *
     * @param  \ArrayAccess<string, mixed>|array<string, mixed>  $response
     * @return array{crutch_words: list<string>, grammar_errors: list<array{error: string, correction: string}>, recommended_vocabulary: list<array{word: string, context: string}>}
     */
    private function clean($response): array
    {
        return [
            'crutch_words' => collect($response['crutch_words'] ?? [])
                ->filter(fn ($word) => is_string($word) && trim($word) !== '')
                ->map(fn (string $word) => trim($word))
                ->reject(fn (string $word) => in_array(mb_strtolower($word), self::FUNCTION_WORDS, true))
                ->unique(fn (string $word) => mb_strtolower($word))
                ->take(DebateEvaluator::MAX_CRUTCH_WORDS)
                ->values()
                ->all(),
            'grammar_errors' => collect($response['grammar_errors'] ?? [])
                ->filter(fn ($item) => is_array($item) && is_string($item['error'] ?? null) && is_string($item['correction'] ?? null))
                ->map(fn (array $item) => ['error' => trim($item['error']), 'correction' => trim($item['correction'])])
                ->filter(fn (array $item) => $item['error'] !== '' && $item['correction'] !== '')
                ->take(DebateEvaluator::MAX_GRAMMAR_ERRORS)
                ->values()
                ->all(),
            'recommended_vocabulary' => collect($response['recommended_vocabulary'] ?? [])
                ->filter(fn ($item) => is_array($item) && is_string($item['word'] ?? null) && is_string($item['context'] ?? null))
                ->map(fn (array $item) => [
                    'word' => Str::limit(WordLookup::normalise($item['word']), self::MAX_WORD_CHARACTERS, ''),
                    'context' => trim($item['context']),
                ])
                ->filter(fn (array $item) => $item['word'] !== '' && $item['context'] !== '')
                ->unique(fn (array $item) => mb_strtolower($item['word']))
                ->take(DebateEvaluator::RECOMMENDED_WORDS)
                ->values()
                ->all(),
        ];
    }

    /**
     * Add the recommended words to the user's spaced-repetition deck.
     *
     * @param  list<array{word: string, context: string}>  $recommended
     * @return list<string> Words that were not in the deck yet.
     */
    private function rememberVocabulary(array $recommended): array
    {
        $added = [];

        foreach ($recommended as ['word' => $word, 'context' => $context]) {
            // The same form SaveWordController stores, so a word saved by hand and one recommended match.
            // createOrFirst, not firstOrCreate: a concurrent save of the same word must not abort this transaction.
            $entry = $this->debate->user->vocabularies()->createOrFirst(
                ['word' => mb_strtolower($word)],
                ['context' => $context, 'next_review_at' => now()],
            );

            if ($entry->wasRecentlyCreated) {
                $added[] = $entry->word;
            }
        }

        return $added;
    }
}

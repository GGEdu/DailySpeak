<?php

namespace App\Jobs;

use App\Ai\Agents\DebateEvaluator;
use App\Enums\MessageRole;
use App\Events\DebateEvaluated;
use App\Events\DebateEvaluationFailed;
use App\Models\Debate;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Post-session fluency analysis (Architecture.md, flow C): feedback on the user's
 * turns, stored in debates.ai_feedback, and recommended words added to their vocabulary.
 */
class EvaluateDebate implements ShouldQueue
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
     * Seconds the job can run. The evaluator's timeout (config/debate.php, evaluator.timeout) must stay below this.
     */
    public int $timeout = 90;

    public int $maxExceptions = 2;

    public int $backoff = 5;

    public function __construct(public Debate $debate)
    {
        $this->onQueue(config('debate.queue'));
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        // Runs after any voice turn that is still being answered (same lock as ProcessVoiceDebate).
        return [(new WithoutOverlapping($this->debate->jobLockKey()))->shared()->releaseAfter(3)->expireAfter(180)];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $turns = $this->debate->messages()
            ->where('role', MessageRole::User)
            ->orderBy('id')
            ->pluck('transcript');

        $feedback = $turns->isEmpty() ? self::EMPTY_FEEDBACK : $this->evaluate($turns);
        $addedWords = $this->rememberVocabulary($feedback['recommended_vocabulary']);

        $this->debate->update(['ai_feedback' => $feedback]);

        // The report is stored; a broadcasting outage must not trigger a second evaluation.
        rescue(fn () => DebateEvaluated::dispatch($this->debate, $addedWords));
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        rescue(fn () => DebateEvaluationFailed::dispatch($this->debate));
    }

    /**
     * Ask the evaluator for feedback and keep only well-formed entries.
     *
     * @param  Collection<int, string>  $turns
     * @return array{crutch_words: list<string>, grammar_errors: list<array{error: string, correction: string}>, recommended_vocabulary: list<array{word: string, context: string}>}
     */
    private function evaluate(Collection $turns): array
    {
        $transcript = $turns->map(fn (string $turn, int $index) => ($index + 1).'. '.$turn)->implode("\n");

        $response = DebateEvaluator::make()->prompt(
            "Debate topic: {$this->debate->newsArticle->title}\n\nLearner's turns:\n{$transcript}"
        );

        return [
            'crutch_words' => collect($response['crutch_words'] ?? [])
                ->filter(fn ($word) => is_string($word) && trim($word) !== '')
                ->map(fn (string $word) => trim($word))
                ->reject(fn (string $word) => in_array(mb_strtolower($word), self::FUNCTION_WORDS, true))
                ->unique(fn (string $word) => mb_strtolower($word))
                ->take(8)
                ->values()
                ->all(),
            'grammar_errors' => collect($response['grammar_errors'] ?? [])
                ->filter(fn ($item) => is_array($item) && filled($item['error'] ?? null) && filled($item['correction'] ?? null))
                ->map(fn (array $item) => ['error' => trim($item['error']), 'correction' => trim($item['correction'])])
                ->take(10)
                ->values()
                ->all(),
            'recommended_vocabulary' => collect($response['recommended_vocabulary'] ?? [])
                ->filter(fn ($item) => is_array($item) && filled($item['word'] ?? null) && filled($item['context'] ?? null))
                ->map(fn (array $item) => ['word' => trim($item['word']), 'context' => trim($item['context'])])
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
            $entry = $this->debate->user->vocabularies()->firstOrCreate(
                ['word' => mb_strtolower(trim($word))],
                ['context' => $context, 'next_review_at' => now()],
            );

            if ($entry->wasRecentlyCreated) {
                $added[] = $entry->word;
            }
        }

        return $added;
    }
}

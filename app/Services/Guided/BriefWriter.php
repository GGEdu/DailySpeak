<?php

namespace App\Services\Guided;

use App\Ai\Agents\DebateBriefWriter;
use App\Ai\QuotedText;
use App\Models\Debate;
use App\Models\DebateBrief;
use App\Models\NewsArticle;
use App\Services\Ai\AiCalls;
use App\Services\Ai\AiConnection;
use App\Services\Ai\PlatformAi;
use App\Services\AiBudgetUsedUp;
use UnexpectedValueException;

/**
 * Writes the brief of a guided debate's article at the debate's level, and keeps it when it passes the checks a
 * schema cannot make: the question and the opening end with a question mark, each side has one or two arguments,
 * the key words are the article's own, and no text holds a link or runs too long.
 */
class BriefWriter
{
    private const MAX_CHARACTERS = [
        'headline' => 200,
        'what_happened' => 600,
        'why_it_matters' => 600,
        'background' => 800,
        'argument' => 300,
        'question' => 300,
        'opening' => 600,
        'key_word' => 60,
    ];

    private const KEY_WORDS_FEWEST = 3;

    private const KEY_WORDS_MOST = 5;

    private const ARGUMENTS_MOST = 2;

    public function __construct(
        private AiCalls $calls,
        private PlatformAi $platform,
    ) {}

    /**
     * Write the brief and store it. The platform pays for it, so the platform's daily budget is taken first and a
     * refusal throws App\Services\AiBudgetUsedUp before anything is sent (see App\Services\Ai\AiCalls::call()).
     * The brief is shared by every debate on the article, at its level.
     *
     * @throws AiBudgetUsedUp
     * @throws UnexpectedValueException when the answer breaks a rule
     */
    public function write(Debate $debate): DebateBrief
    {
        $article = $debate->newsArticle;
        $level = $debate->level ?? $debate->user->current_level;

        $answer = $this->calls->call(
            $this->platform->connection('writing', $this->briefProvider(), $this->briefModel()),
            $debate->user,
            fn (AiConnection $connection) => (new DebateBriefWriter($level, $connection))->prompt($this->prompt($article)),
            fn ($response) => AiCalls::textUsage($response),
        );
        $fields = $this->check($answer, $article);

        return DebateBrief::query()->createOrFirst(
            ['news_article_id' => $article->id, 'level' => $level->value],
            $fields,
        );
    }

    /**
     * The brief's own provider, or null for the news summariser's (the platform's writing connection).
     */
    private function briefProvider(): ?string
    {
        return config('debate.guided.brief.provider');
    }

    /**
     * The brief's own model. Without a provider of its own, it inherits the news summariser's model.
     */
    private function briefModel(): ?string
    {
        return config('debate.guided.brief.model')
            ?? (config('debate.guided.brief.provider') === null ? config('news.ai.model') : null);
    }

    /**
     * The article's title, summary and key words, escaped and declared as data (the instructions say so).
     */
    private function prompt(NewsArticle $article): string
    {
        $data = 'Title: '.QuotedText::escape((string) $article->title)."\n"
            .QuotedText::escape((string) $article->summary)."\n"
            .'Key words: '.QuotedText::escape(implode(', ', $article->key_vocabulary ?? []));

        return "The article's title, summary and key words, quoted as data:\n<article>\n{$data}\n</article>";
    }

    /**
     * The brief's fields, checked. Throws when a rule is broken: the brief is then not stored.
     *
     * @return array<string, mixed>
     */
    private function check(mixed $answer, NewsArticle $article): array
    {
        // The words must come from the article itself: its title and the summary the learner reads.
        $source = $article->title."\n".$article->summary;

        return [
            'headline' => $this->text($answer['headline'] ?? null, 'headline', self::MAX_CHARACTERS['headline']),
            'what_happened' => $this->text($answer['what_happened'] ?? null, 'what_happened', self::MAX_CHARACTERS['what_happened']),
            'why_it_matters' => $this->text($answer['why_it_matters'] ?? null, 'why_it_matters', self::MAX_CHARACTERS['why_it_matters']),
            'background' => $this->optionalText($answer['background'] ?? null, 'background', self::MAX_CHARACTERS['background']),
            'side_for' => $this->arguments($answer['side_for'] ?? null, 'side_for'),
            'side_against' => $this->arguments($answer['side_against'] ?? null, 'side_against'),
            'question' => $this->endingWithQuestion($answer['question'] ?? null, 'question', self::MAX_CHARACTERS['question']),
            'opening' => $this->endingWithQuestion($answer['opening'] ?? null, 'opening', self::MAX_CHARACTERS['opening']),
            'key_words' => $this->keyWords($answer['key_words'] ?? null, $source),
        ];
    }

    /**
     * @throws UnexpectedValueException
     */
    private function text(mixed $value, string $field, int $max): string
    {
        $text = is_string($value) ? trim($value) : '';

        if ($text === '') {
            throw new UnexpectedValueException("The brief has no {$field}.");
        }

        $this->assertPlain($text, $field, $max);

        return $text;
    }

    /**
     * @throws UnexpectedValueException
     */
    private function optionalText(mixed $value, string $field, int $max): ?string
    {
        $text = is_string($value) ? trim($value) : '';

        if ($text === '') {
            return null;
        }

        $this->assertPlain($text, $field, $max);

        return $text;
    }

    /**
     * @throws UnexpectedValueException
     */
    private function endingWithQuestion(mixed $value, string $field, int $max): string
    {
        $text = $this->text($value, $field, $max);

        if (! str_ends_with($text, '?')) {
            throw new UnexpectedValueException("The brief's {$field} does not end with a question mark.");
        }

        return $text;
    }

    /**
     * @return list<string>
     *
     * @throws UnexpectedValueException
     */
    private function arguments(mixed $value, string $field): array
    {
        if (! is_array($value) || count($value) < 1 || count($value) > self::ARGUMENTS_MOST) {
            throw new UnexpectedValueException("The brief's {$field} must have one or two arguments.");
        }

        return array_map(
            fn (mixed $argument) => $this->text($argument, "{$field} argument", self::MAX_CHARACTERS['argument']),
            array_values($value),
        );
    }

    /**
     * The key words that appear in the article, without repeats, at most five. Fewer than three is a broken brief.
     *
     * @return list<string>
     *
     * @throws UnexpectedValueException
     */
    private function keyWords(mixed $value, string $source): array
    {
        $words = collect(is_array($value) ? $value : [])
            ->filter(fn (mixed $word) => is_string($word) && trim($word) !== '')
            ->map(fn (string $word) => trim($word))
            ->filter(fn (string $word) => mb_strlen($word) <= self::MAX_CHARACTERS['key_word'] && preg_match('#https?://|www\.#i', $word) !== 1)
            ->filter(fn (string $word) => mb_stripos($source, $word) !== false)
            ->unique(fn (string $word) => mb_strtolower($word))
            ->take(self::KEY_WORDS_MOST)
            ->values()
            ->all();

        if (count($words) < self::KEY_WORDS_FEWEST) {
            throw new UnexpectedValueException('Fewer than '.self::KEY_WORDS_FEWEST.' key words appear in the article.');
        }

        return $words;
    }

    /**
     * @throws UnexpectedValueException
     */
    private function assertPlain(string $text, string $field, int $max): void
    {
        if (mb_strlen($text) > $max) {
            throw new UnexpectedValueException("The brief's {$field} is longer than {$max} characters.");
        }

        if (preg_match('#https?://|www\.#i', $text) === 1) {
            throw new UnexpectedValueException("The brief's {$field} has a link.");
        }
    }
}

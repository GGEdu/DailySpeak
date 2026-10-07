<?php

namespace App\Services\Vocabulary;

use App\Ai\Agents\WordExplainer;
use App\Enums\EnglishLevel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Explains a selected word or expression in the sentence where the learner found it.
 */
class WordLookup
{
    /**
     * Explain the selection, caching the answer: the same selection in the same sentence
     * and at the same level always gets the same explanation.
     *
     * @return array{translation: string, part_of_speech: string, definition: string, example: string, synonyms: list<string>}
     */
    public function explain(string $text, ?string $context, EnglishLevel $level): array
    {
        $text = self::normalise($text);
        $context = filled($context) ? Str::limit(self::squish($context), config('lookup.max_context_characters'), '') : null;

        $key = 'lookup:'.sha1(implode("\n", [mb_strtolower($text), (string) $context, $level->value, config('lookup.target_language')]));

        return Cache::remember($key, now()->addDays(config('lookup.cache_days')), fn () => $this->ask($text, $context, $level));
    }

    /**
     * The canonical form of a selection: trimmed, single-spaced, without surrounding punctuation.
     */
    public static function normalise(string $text): string
    {
        // A regex, not trim(): trim() strips byte by byte and would cut "Brontë" or "€5" in half.
        return (string) preg_replace('/^[\s.,;:!?"\'“”‘’()\[\]{}«»—–-]+|[\s.,;:!?"\'“”‘’()\[\]{}«»—–-]+$/u', '', self::squish($text));
    }

    private static function squish(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return array{translation: string, part_of_speech: string, definition: string, example: string, synonyms: list<string>}
     */
    private function ask(string $text, ?string $context, EnglishLevel $level): array
    {
        $response = (new WordExplainer($level))->prompt(
            "Selected: {$text}".($context !== null ? "\nSentence: {$context}" : '')
        );

        $field = fn (string $key, int $limit) => Str::limit(trim((string) ($response[$key] ?? '')), $limit, '');

        // An answer that is not the structured JSON decodes to nothing: fail instead of caching
        // (and saving) an empty explanation for a month.
        if ($field('translation', 120) === '' || $field('definition', 300) === '') {
            throw new UnexpectedValueException('The word explainer returned an empty explanation.');
        }

        return [
            'translation' => $field('translation', 120),
            'part_of_speech' => $field('part_of_speech', 40),
            'definition' => $field('definition', 300),
            'example' => $field('example', 300),
            'synonyms' => collect($response['synonyms'] ?? [])
                ->filter(fn ($synonym) => is_string($synonym) && trim($synonym) !== '')
                ->map(fn (string $synonym) => trim($synonym))
                ->unique(fn (string $synonym) => mb_strtolower($synonym))
                ->take(WordExplainer::SYNONYMS)
                ->values()
                ->all(),
        ];
    }
}

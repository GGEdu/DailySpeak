<?php

namespace App\Ai\Agents;

use App\Enums\EnglishLevel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Explains a word or expression the learner selected, as used in its sentence.
 */
class WordExplainer implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Synonyms kept per explanation.
     */
    public const SYNONYMS = 3;

    public function __construct(private EnglishLevel $level) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return strtr(config('prompts.word_explainer'), [
            ':language' => config('lookup.target_language'),
            ':level_name' => mb_strtolower($this->level->label()),
            ':level' => $this->level->value,
        ]);
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'translation' => $schema->string()->description('Translation into the learner\'s language that fits the sentence.')->required(),
            'part_of_speech' => $schema->string()->description('Part of speech in English, e.g. "noun" or "phrasal verb".')->required(),
            'definition' => $schema->string()->description('One short plain-English sentence.')->required(),
            'example' => $schema->string()->description('A new English sentence using it the same way.')->required(),
            'synonyms' => $schema->array()->items($schema->string())->max(self::SYNONYMS)->required(),
        ];
    }

    /**
     * Get the AI provider the agent should use.
     */
    public function provider(): string
    {
        return config('lookup.ai.provider');
    }

    /**
     * Get the model the agent should use (null uses the provider's default).
     */
    public function model(): ?string
    {
        return config('lookup.ai.model');
    }

    /**
     * Get the timeout in seconds for each prompt.
     */
    public function timeout(): int
    {
        return config('lookup.ai.timeout');
    }
}

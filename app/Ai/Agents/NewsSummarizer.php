<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Summarises a news article for C1 learners and picks its key vocabulary.
 */
class NewsSummarizer implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Number of vocabulary terms requested per article.
     */
    public const VOCABULARY_SIZE = 5;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return config('prompts.news_summary');
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()
                ->description('Three-paragraph summary of the article in English, paragraphs separated by a blank line.')
                ->required(),
            'vocabulary' => $schema->array()
                ->items($schema->string())
                ->description('Advanced (C1) English words or expressions taken from the article.')
                ->min(self::VOCABULARY_SIZE)
                ->max(self::VOCABULARY_SIZE)
                ->required(),
        ];
    }

    /**
     * Get the AI provider the agent should use.
     */
    public function provider(): string
    {
        return config('news.ai.provider');
    }

    /**
     * Get the model the agent should use (null uses the provider's default).
     */
    public function model(): ?string
    {
        return config('news.ai.model');
    }

    /**
     * Get the timeout in seconds for each prompt.
     */
    public function timeout(): int
    {
        return config('news.ai.timeout');
    }
}

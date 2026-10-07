<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Post-session fluency analysis of the learner's side of a debate.
 */
class DebateEvaluator implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Number of vocabulary recommendations requested.
     */
    public const RECOMMENDED_WORDS = 3;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return config('prompts.debate_evaluator');
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'crutch_words' => $schema->array()
                ->items($schema->string())
                ->description('Basic words the learner overused, most frequent first.')
                ->required(),
            'grammar_errors' => $schema->array()
                ->items($schema->object([
                    'error' => $schema->string()->description('The learner\'s exact wording.')->required(),
                    'correction' => $schema->string()->description('How a proficient speaker would say it.')->required(),
                ]))
                ->required(),
            'recommended_vocabulary' => $schema->array()
                ->items($schema->object([
                    'word' => $schema->string()->required(),
                    'context' => $schema->string()->description('An example sentence about the debate topic using the word.')->required(),
                ]))
                ->min(self::RECOMMENDED_WORDS)
                ->max(self::RECOMMENDED_WORDS)
                ->required(),
        ];
    }

    /**
     * Get the AI provider the agent should use (the debate tutor's unless configured).
     */
    public function provider(): string
    {
        return config('debate.evaluator.provider') ?? config('debate.llm.provider');
    }

    /**
     * Get the model the agent should use (null uses the provider's default).
     */
    public function model(): ?string
    {
        if (config('debate.evaluator.model') !== null) {
            return config('debate.evaluator.model');
        }

        return config('debate.evaluator.provider') === null ? config('debate.llm.model') : null;
    }

    /**
     * Get the timeout in seconds for each prompt.
     */
    public function timeout(): int
    {
        return config('debate.evaluator.timeout');
    }
}

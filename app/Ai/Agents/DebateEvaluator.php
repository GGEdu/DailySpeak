<?php

namespace App\Ai\Agents;

use App\Enums\EnglishLevel;
use App\Services\Ai\AiConnection;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
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
     * Most crutch words kept from the answer.
     */
    public const MAX_CRUTCH_WORDS = 8;

    /**
     * Most grammar errors kept from the answer.
     */
    public const MAX_GRAMMAR_ERRORS = 10;

    /**
     * @param  EnglishLevel  $level  The learner's level: the recommendations go just above it.
     */
    public function __construct(
        public EnglishLevel $level,
        private AiConnection $connection,
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return strtr(config('prompts.debate_evaluator'), [
            ':level_name' => Str::lower($this->level->label()),
            ':level' => $this->level->value,
            ':recommended_words' => (string) self::RECOMMENDED_WORDS,
        ]);
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
     * Get the AI provider the agent should use: the learner's connection for writing, built where it runs.
     */
    public function provider(): string
    {
        return $this->connection->providerName();
    }

    /**
     * Get the model the agent should use: the one the learner picked for writing.
     */
    public function model(): ?string
    {
        return $this->connection->model;
    }

    /**
     * Get the timeout in seconds for each prompt.
     */
    public function timeout(): int
    {
        return config('debate.evaluator.timeout');
    }
}

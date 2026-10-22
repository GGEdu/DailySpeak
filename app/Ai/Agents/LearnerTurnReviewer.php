<?php

namespace App\Ai\Agents;

use App\Enums\EnglishLevel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Written corrections of one learner turn in a guided debate. Only the high-confidence ones are kept
 * (App\Services\Guided\LearnerCorrections).
 */
class LearnerTurnReviewer implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Most corrections given for one turn.
     */
    public const MAX_CORRECTIONS = 2;

    /**
     * @param  EnglishLevel  $level  The learner's level: only what a learner at it gets wrong is corrected.
     */
    public function __construct(public EnglishLevel $level) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return strtr(config('prompts.learner_turn_review'), [
            ':level_name' => Str::lower($this->level->label()),
            ':level' => $this->level->value,
        ]);
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'corrections' => $schema->array()
                ->items($schema->object([
                    'original' => $schema->string()->description('The learner\'s exact words, copied from the transcript.')->required(),
                    'better' => $schema->string()->description('How a proficient speaker would say it.')->required(),
                    'note' => $schema->string()->nullable()->description('One short sentence in English explaining it, or null.')->required(),
                    'confidence' => $schema->string()->enum(['high', 'medium', 'low'])->description('"high" only when certain.')->required(),
                ]))
                ->max(self::MAX_CORRECTIONS)
                ->required(),
        ];
    }

    /**
     * Get the AI provider the agent should use (the debate tutor's unless configured).
     */
    public function provider(): string
    {
        return config('debate.guided.review.provider') ?? config('debate.llm.provider');
    }

    /**
     * Get the model the agent should use (null uses the provider's default).
     */
    public function model(): ?string
    {
        if (config('debate.guided.review.model') !== null) {
            return config('debate.guided.review.model');
        }

        return config('debate.guided.review.provider') === null ? config('debate.llm.model') : null;
    }

    /**
     * Get the timeout in seconds for each prompt.
     */
    public function timeout(): int
    {
        return config('debate.guided.review.timeout');
    }
}

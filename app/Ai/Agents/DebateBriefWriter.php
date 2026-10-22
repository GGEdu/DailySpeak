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
 * Writes the brief a guided debate starts from: a structured summary of an article for one English level, with the
 * two sides of the debate and its opening question.
 */
class DebateBriefWriter implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  EnglishLevel  $level  The learner's level: the brief is written for it.
     */
    public function __construct(public EnglishLevel $level) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return strtr(config('prompts.debate_brief'), [
            ':level_name' => Str::lower($this->level->label()),
            ':level_guidance' => config("prompts.debate_brief_levels.{$this->level->value}"),
            ':level' => $this->level->value,
        ]);
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'headline' => $schema->string()
                ->description('The story in one plain sentence.')
                ->required(),
            'what_happened' => $schema->string()
                ->description('Who, what, when and where, in two or three sentences.')
                ->required(),
            'why_it_matters' => $schema->string()
                ->description('The cause or the tension behind the story, in one or two sentences.')
                ->required(),
            'background' => $schema->string()
                ->nullable()
                ->description('Only context the article gives, in one to three sentences; null when it gives none.')
                ->required(),
            'side_for' => $schema->array()
                ->items($schema->string())
                ->description('One or two arguments in favour of the debate question.')
                ->min(1)
                ->max(2)
                ->required(),
            'side_against' => $schema->array()
                ->items($schema->string())
                ->description('One or two arguments against the debate question.')
                ->min(1)
                ->max(2)
                ->required(),
            'question' => $schema->string()
                ->description('One open question for the debate, ending with a question mark.')
                ->required(),
            'opening' => $schema->string()
                ->description('The tutor\'s first message: one or two sentences framing the story, then the question. Ends with a question mark.')
                ->required(),
            'key_words' => $schema->array()
                ->items($schema->string())
                ->description('Three to five words or expressions taken from the article, exactly as written there.')
                ->min(3)
                ->max(5)
                ->required(),
        ];
    }

    /**
     * Get the AI provider the agent should use (the news summariser's unless configured).
     */
    public function provider(): string
    {
        return config('debate.guided.brief.provider') ?? config('news.ai.provider');
    }

    /**
     * Get the model the agent should use (null uses the provider's default).
     */
    public function model(): ?string
    {
        if (config('debate.guided.brief.model') !== null) {
            return config('debate.guided.brief.model');
        }

        return config('debate.guided.brief.provider') === null ? config('news.ai.model') : null;
    }

    /**
     * Get the timeout in seconds for each prompt.
     */
    public function timeout(): int
    {
        return config('debate.guided.brief.timeout');
    }
}

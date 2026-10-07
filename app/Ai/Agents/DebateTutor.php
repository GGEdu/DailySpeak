<?php

namespace App\Ai\Agents;

use App\Enums\MessageRole;
use App\Models\Debate;
use App\Models\DebateMessage;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Native-speaker debate partner that argues about a news article with the user.
 */
class DebateTutor implements Agent, Conversational
{
    use Promptable;

    /**
     * @param  DebateMessage  $turn  The user message being answered; only earlier messages form the history.
     */
    public function __construct(
        public Debate $debate,
        public DebateMessage $turn,
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return strtr(config('prompts.debate_tutor'), [
            ':title' => $this->debate->newsArticle->title,
            ':summary' => $this->debate->newsArticle->summary,
            ':level' => $this->debate->user->current_level->value,
        ]);
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        return $this->debate->messages()
            ->where('id', '<', $this->turn->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (DebateMessage $message) => $message->role === MessageRole::User
                ? new UserMessage($message->transcript)
                : new AssistantMessage($message->transcript))
            ->all();
    }

    /**
     * Get the AI provider the agent should use.
     */
    public function provider(): string
    {
        return config('debate.llm.provider');
    }

    /**
     * Get the model the agent should use (null uses the provider's default).
     */
    public function model(): ?string
    {
        return config('debate.llm.model');
    }

    /**
     * Get the timeout in seconds for each prompt.
     */
    public function timeout(): int
    {
        return config('debate.llm.timeout');
    }
}

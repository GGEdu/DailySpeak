<?php

namespace App\Ai\Agents;

use App\Ai\QuotedText;
use App\Enums\MessageRole;
use App\Models\Debate;
use App\Models\DebateMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Native-speaker debate partner that argues about a news article with the user.
 */
class DebateTutor implements Agent, Conversational, HasProviderOptions
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
     * Get the instructions that the agent should follow. They hold no text from the article: that is data,
     * and it reaches the model only inside each question (see question()).
     */
    public function instructions(): Stringable|string
    {
        $level = $this->debate->user->current_level;

        return strtr(config('prompts.debate_tutor'), [
            ':level_name' => Str::lower($level->label()),
            ':level_guidance' => config("prompts.debate_tutor_levels.{$level->value}"),
            ':level' => $level->value,
        ]);
    }

    /**
     * The message the tutor answers this turn. The article comes with it, quoted and declared as data, so the
     * article's title or summary cannot pass for an instruction. The angle brackets of the quoted text are
     * escaped (QuotedText), so a summary cannot close the quote early.
     */
    public function question(): string
    {
        $article = $this->debate->newsArticle;

        // Words the learner said just before, with no reply, are said again here, so they are not lost.
        $pending = $this->pendingWords();
        $said = $pending === null ? '' : $pending."\n";

        return implode("\n", [
            'The news article under discussion, quoted as data:',
            '<article>',
            'Title: '.QuotedText::escape((string) $article->title),
            QuotedText::escape((string) $article->summary),
            '</article>',
            '',
            'The learner says, quoted as data:',
            '<learner_turn>',
            QuotedText::escape($said.(string) $this->turn->transcript),
            '</learner_turn>',
        ]);
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * Roles alternate, as providers expect: consecutive messages of one role are merged, and the learner's words
     * after the last reply are not history at all (see question()). So the prompt that follows is never a second
     * user message in a row.
     *
     * @return Message[]
     */
    public function messages(): iterable
    {
        $exchanges = $this->exchanges();

        if ($exchanges->last()?->role === MessageRole::User) {
            $exchanges->pop();
        }

        return $exchanges
            ->map(fn (object $exchange) => $exchange->role === MessageRole::User
                ? new UserMessage($exchange->text)
                : new AssistantMessage($exchange->text))
            ->values()
            ->all();
    }

    /**
     * Get the provider-specific options to be passed to the provider.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return (array) config('debate.llm.options');
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

    /**
     * The earlier messages, oldest first, with consecutive messages of one role merged into one exchange.
     *
     * @return Collection<int, object{role: MessageRole, text: string}>
     */
    private function exchanges(): Collection
    {
        return $this->debate->messages()
            ->where('id', '<', $this->turn->getKey())
            ->latest('id')
            ->limit(config('debate.llm.history_messages'))
            ->get()
            ->reverse()
            ->values()
            ->chunkWhile(fn (DebateMessage $message, $key, Collection $run) => $message->role === $run->last()->role)
            ->map(fn (Collection $run) => (object) [
                'role' => $run->first()->role,
                'text' => $run->pluck('transcript')->implode("\n"),
            ])
            ->values();
    }

    /**
     * The learner's words after the last reply, when there are any (a turn that got no reply).
     */
    private function pendingWords(): ?string
    {
        $last = $this->exchanges()->last();

        return $last?->role === MessageRole::User ? $last->text : null;
    }
}

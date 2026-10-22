<?php

namespace App\Ai\Agents;

use App\Ai\QuotedText;
use App\Enums\EnglishLevel;
use App\Enums\MessageRole;
use App\Models\Debate;
use App\Models\DebateBrief;
use App\Models\DebateMessage;
use App\Services\Ai\AiConnection;
use App\Services\Ai\AiConnections;
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
        private ?AiConnection $connection = null,
    ) {}

    /**
     * Get the instructions that the agent should follow. They hold no text from the article: that is data,
     * and it reaches the model only inside each question (see question()).
     */
    public function instructions(): Stringable|string
    {
        $level = $this->level();
        $prompt = $this->debate->isGuided() ? 'prompts.debate_tutor_guided' : 'prompts.debate_tutor';

        return strtr(config($prompt), [
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
        // Words the learner said just before, with no reply, are said again here, so they are not lost.
        $pending = $this->pendingWords();
        $said = $pending === null ? '' : $pending."\n";

        return implode("\n", [
            'The news article under discussion, quoted as data:',
            '<article>',
            ...$this->articleLines(),
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
     * Get the AI provider the agent should use: the debate owner's connection for conversation, built where it runs.
     */
    public function provider(): string
    {
        return $this->resolvedConnection()->providerName();
    }

    /**
     * Get the model the agent should use: the one the owner picked for conversation.
     */
    public function model(): ?string
    {
        return $this->resolvedConnection()->model;
    }

    /**
     * The connection the reply is paid through. A caller that has resolved it passes it in; otherwise it is the owner's.
     */
    private function resolvedConnection(): AiConnection
    {
        return $this->connection ??= app(AiConnections::class)->forUser($this->debate->user, 'conversation');
    }

    /**
     * Get the timeout in seconds for each prompt.
     */
    public function timeout(): int
    {
        return config('debate.llm.timeout');
    }

    /**
     * The level the tutor speaks at. A guided debate keeps the level its brief was written for, so a level change in the
     * middle of it does not change the tutor; a classic debate follows the learner's current level.
     */
    private function level(): EnglishLevel
    {
        if ($this->debate->isGuided() && $this->debate->level !== null) {
            return $this->debate->level;
        }

        return $this->debate->user->current_level;
    }

    /**
     * The article as the question quotes it: its title and summary, or for a guided debate with a brief, the brief's
     * parts (the headline, what happened, why it matters, the background, both sides and the debate question). Each
     * line is escaped as data.
     *
     * @return list<string>
     */
    private function articleLines(): array
    {
        $article = $this->debate->newsArticle;
        $brief = DebateBrief::forDebate($this->debate);

        if ($brief === null) {
            return [
                'Title: '.QuotedText::escape((string) $article->title),
                QuotedText::escape((string) $article->summary),
            ];
        }

        return [
            'Title: '.QuotedText::escape((string) $article->title),
            'Headline: '.QuotedText::escape($brief->headline),
            'What happened: '.QuotedText::escape($brief->what_happened),
            'Why it matters: '.QuotedText::escape($brief->why_it_matters),
            ...($brief->background === null ? [] : ['Background: '.QuotedText::escape($brief->background)]),
            ...array_map(fn (string $argument) => 'Argument for: '.QuotedText::escape($argument), $brief->side_for),
            ...array_map(fn (string $argument) => 'Argument against: '.QuotedText::escape($argument), $brief->side_against),
            'Debate question: '.QuotedText::escape($brief->question),
        ];
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

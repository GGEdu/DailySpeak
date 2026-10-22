<?php

namespace App\Services\Guided;

use App\Ai\Agents\LearnerTurnReviewer;
use App\Ai\QuotedText;
use App\Enums\MessageRole;
use App\Models\Debate;
use App\Models\DebateMessage;
use App\Services\Ai\AiCalls;
use App\Services\Ai\AiCapReached;
use App\Services\Ai\AiConnection;
use App\Services\Ai\AiNotConfigured;
use App\Services\AiBudgetUsedUp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The written corrections of a learner's turn in a guided debate: asked for in the background, checked, stored on the
 * turn, and dismissed by the learner ("I did not say that") when the transcript was misheard.
 *
 * A correction is kept only when its "original" is really in the transcript: a correction of words the learner did
 * not say is dropped, whatever the model's confidence.
 */
class LearnerCorrections
{
    private const MAX_CHARACTERS = 300;

    private const MAX_NOTE_CHARACTERS = 200;

    public function __construct(private AiCalls $calls) {}

    /**
     * The corrections of the turn, checked, as stored: nothing when the owner cannot pay for the review (no connection,
     * the cap, the budget) or the review fails.
     *
     * @return list<array{original: string, better: string, note: ?string, dismissed: bool}>
     */
    public function review(DebateMessage $turn): array
    {
        $debate = $turn->debate;

        try {
            $level = $debate->level ?? $debate->user->current_level;
            $answer = $this->calls->forUser(
                $debate->user,
                'conversation',
                fn (AiConnection $connection) => (new LearnerTurnReviewer($level, $connection))->prompt($this->prompt($turn->transcript)),
                fn ($response) => AiCalls::textUsage($response),
            );

            return $this->check($answer, $turn->transcript);
        } catch (AiNotConfigured|AiCapReached|AiBudgetUsedUp $e) {
            Log::info('The learner turn review was not made: its owner cannot pay for it now.', [
                'debate_id' => $debate->getKey(),
                'message_id' => $turn->getKey(),
                'reason' => $e::class,
            ]);

            return [];
        } catch (Throwable $e) {
            report($e);
            Log::warning('The learner turn review failed; the turn has no corrections.', [
                'debate_id' => $debate->getKey(),
                'message_id' => $turn->getKey(),
                'exception' => $e::class,
            ]);

            return [];
        }
    }

    /**
     * Store the corrections on the turn, once. Returns them as the page shows them, or null when the turn is gone or
     * already reviewed.
     *
     * @param  list<array{original: string, better: string, note: ?string, dismissed: bool}>  $corrections
     * @return list<array{index: int, original: string, better: string, note: ?string, dismissed: bool}>|null
     */
    public function store(DebateMessage $turn, array $corrections): ?array
    {
        return DB::transaction(function () use ($turn, $corrections) {
            $locked = DebateMessage::query()->lockForUpdate()->find($turn->getKey());

            if ($locked === null || $locked->corrections !== null) {
                return null;
            }

            $locked->forceFill(['corrections' => $corrections])->save();

            return $locked->presentedCorrections();
        });
    }

    /**
     * The learner's "I did not say that": the correction leaves the report. Returns false when the turn has no
     * correction at that index.
     */
    public function dismiss(DebateMessage $turn, int $index): bool
    {
        return DB::transaction(function () use ($turn, $index): bool {
            $locked = DebateMessage::query()->lockForUpdate()->find($turn->getKey());

            if ($locked === null || ! is_array($locked->corrections) || ! array_key_exists($index, $locked->corrections)) {
                return false;
            }

            $corrections = $locked->corrections;
            $corrections[$index] = [...$corrections[$index], 'dismissed' => true];

            $locked->forceFill(['corrections' => $corrections])->save();

            return true;
        });
    }

    /**
     * The report's grammar errors, with the turns' written corrections added: the ones the learner did not dismiss, and
     * not those the evaluator already found (compared without case). The entries keep the report's shape.
     *
     * @param  list<array{error: string, correction: string}>  $grammarErrors  The evaluator's own.
     * @return list<array{error: string, correction: string}>
     */
    public function mergeIntoReport(Debate $debate, array $grammarErrors): array
    {
        $known = collect($grammarErrors)->map(fn (array $error) => $this->key($error['error']));

        $fromTurns = DebateMessage::query()
            ->where('debate_id', $debate->getKey())
            ->where('role', MessageRole::User)
            ->whereNotNull('corrections')
            ->orderBy('id')
            ->get()
            ->flatMap(fn (DebateMessage $turn) => collect($turn->corrections)
                ->reject(fn (array $correction) => $correction['dismissed'])
                ->map(fn (array $correction) => ['error' => $correction['original'], 'correction' => $correction['better']]));

        $added = $fromTurns
            ->reject(fn (array $error) => $known->contains($this->key($error['error'])))
            ->unique(fn (array $error) => $this->key($error['error']))
            ->values()
            ->all();

        return [...$grammarErrors, ...$added];
    }

    /**
     * The turn is quoted as data: the instructions say the transcript is machine text and that it is not to be obeyed.
     */
    private function prompt(string $transcript): string
    {
        return "The learner's turn, quoted as data:\n<learner_turn>\n".QuotedText::escape($transcript)."\n</learner_turn>";
    }

    /**
     * Keep the high-confidence corrections whose "original" is in the transcript, at most the agent's limit.
     *
     * @return list<array{original: string, better: string, note: ?string, dismissed: bool}>
     */
    private function check(mixed $answer, string $transcript): array
    {
        $items = is_array($answer['corrections'] ?? null) ? $answer['corrections'] : [];

        return collect($items)
            ->filter(fn (mixed $item) => is_array($item) && ($item['confidence'] ?? null) === 'high')
            ->map(fn (array $item) => [
                'original' => is_string($item['original'] ?? null) ? trim($item['original']) : '',
                'better' => is_string($item['better'] ?? null) ? trim($item['better']) : '',
                'note' => is_string($item['note'] ?? null) && trim($item['note']) !== '' ? Str::limit(trim($item['note']), self::MAX_NOTE_CHARACTERS, '') : null,
            ])
            ->filter(fn (array $correction) => $correction['original'] !== ''
                && $correction['better'] !== ''
                && $correction['original'] !== $correction['better']
                && mb_strlen($correction['original']) <= self::MAX_CHARACTERS
                && mb_strlen($correction['better']) <= self::MAX_CHARACTERS)
            ->filter(fn (array $correction) => str_contains($transcript, $correction['original']))
            ->take(LearnerTurnReviewer::MAX_CORRECTIONS)
            ->map(fn (array $correction) => [...$correction, 'dismissed' => false])
            ->values()
            ->all();
    }

    private function key(string $error): string
    {
        return mb_strtolower(trim($error));
    }
}

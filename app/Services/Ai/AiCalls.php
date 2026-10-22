<?php

namespace App\Services\Ai;

use App\Models\AiUsage;
use App\Models\User;
use App\Services\AiBudget;
use App\Services\AiBudgetUsedUp;
use Closure;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\TranscriptionUsage;
use Throwable;

/**
 * The one place a paid AI call goes through. It takes the connection (AiConnections), checks the user's monthly cap,
 * takes the platform's daily budget when the platform pays for a call a user asked for, makes the call, and writes one
 * ai_usage row for it: also when it fails, and never with the content.
 *
 * A check that refuses a call costs nothing and writes nothing. A call that was made and failed is recorded, because
 * the provider may have billed it.
 */
class AiCalls
{
    public function __construct(
        private AiConnections $connections,
        private AiBudget $budget,
    ) {}

    /**
     * The checks that come before a paid call, without making one: the user's connection for the task, the monthly
     * cap, and the platform's budget not being used up when the platform pays. Controllers run it before they queue
     * work, so a refusal leaves nothing half done.
     *
     * @throws AiNotConfigured
     * @throws AiCapReached
     * @throws AiBudgetUsedUp
     */
    public function check(User $user, string $task): AiConnection
    {
        $connection = $this->connections->forUser($user, $task);

        $this->assertWithinCap($user);

        if ($connection->paidBy === 'platform' && $this->budget->exhausted($user)) {
            throw new AiBudgetUsedUp;
        }

        return $connection;
    }

    /**
     * The checks for several tasks, as one request needs them (a voice turn needs stt, conversation and tts).
     *
     * @param  list<string>  $tasks
     * @return array<string, AiConnection>
     *
     * @throws AiNotConfigured
     * @throws AiCapReached
     * @throws AiBudgetUsedUp
     */
    public function checkAll(User $user, array $tasks): array
    {
        $connections = [];

        foreach ($tasks as $task) {
            $connections[$task] = $this->check($user, $task);
        }

        return $connections;
    }

    /**
     * Make one paid call with the given connection, for $user (null for the common work, which nobody asked for alone).
     *
     * $call receives the connection and returns the SDK's response. $usage reads the sizes out of that response:
     * tokens, characters and seconds, as the provider reports them. Its result goes to ai_usage; nothing else does.
     *
     * @param  Closure(AiConnection): mixed  $call
     * @param  (Closure(mixed): array<string, int|float|null>)|null  $usage
     *
     * @throws AiCapReached
     * @throws AiBudgetUsedUp
     */
    public function call(AiConnection $connection, ?User $user, Closure $call, ?Closure $usage = null): mixed
    {
        if ($user !== null) {
            $this->assertWithinCap($user);

            if ($connection->paidBy === 'platform') {
                $this->budget->spendOrFail($user);
            }
        }

        try {
            $response = $call($connection);
        } catch (Throwable $e) {
            $this->record($connection, $user, false);

            throw $e;
        }

        $this->record($connection, $user, true, $usage === null ? [] : $usage($response));

        return $response;
    }

    /**
     * Make a call for the user's own connection to a task (see check() and call()).
     *
     * @param  Closure(AiConnection): mixed  $call
     * @param  (Closure(mixed): array<string, int|float|null>)|null  $usage
     *
     * @throws AiNotConfigured
     * @throws AiCapReached
     * @throws AiBudgetUsedUp
     */
    public function forUser(User $user, string $task, Closure $call, ?Closure $usage = null): mixed
    {
        return $this->call($this->connections->forUser($user, $task), $user, $call, $usage);
    }

    /**
     * Refuse with AiCapReached when the user has used up the monthly calls they set for themselves.
     *
     * @throws AiCapReached
     */
    public function assertWithinCap(User $user): void
    {
        if ($user->ai_monthly_cap !== null && $this->usedThisMonth($user) >= $user->ai_monthly_cap) {
            throw new AiCapReached;
        }
    }

    /**
     * The AI calls made for the user this calendar month, paid by anybody and whether or not they worked.
     */
    public function usedThisMonth(User $user): int
    {
        return AiUsage::query()
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();
    }

    /**
     * The sizes of a text response: tokens, as the provider reports them.
     *
     * @return array<string, int|null>
     */
    public static function textUsage(object $response): array
    {
        $usage = $response->usage ?? null;

        return [
            'input_tokens' => $usage instanceof TextUsage ? $usage->inputTokens : null,
            'output_tokens' => $usage instanceof TextUsage ? $usage->outputTokens : null,
        ];
    }

    /**
     * The sizes of a transcription: tokens and the seconds of audio, when the provider reports them.
     *
     * @return array<string, int|float|null>
     */
    public static function transcriptionUsage(object $response): array
    {
        $usage = $response->usage ?? null;

        return [
            ...self::textUsage($response),
            'seconds' => $usage instanceof TranscriptionUsage ? $usage->audioSeconds : null,
        ];
    }

    /**
     * The sizes of a synthesis: its length in characters, which is what the provider bills.
     *
     * @return array<string, int|null>
     */
    public static function characters(string $text): array
    {
        return ['characters' => mb_strlen($text)];
    }

    /**
     * Write the row. A row that cannot be written must not undo the answer already given, so the failure is reported and
     * the call goes on.
     *
     * @param  array<string, int|float|null>  $figures
     */
    private function record(AiConnection $connection, ?User $user, bool $ok, array $figures = []): void
    {
        rescue(fn () => AiUsage::query()->create([
            'user_id' => $user?->getKey(),
            'task' => $connection->task,
            'provider' => $connection->provider,
            'model' => $connection->model,
            'paid_by' => $connection->paidBy,
            'input_tokens' => $figures['input_tokens'] ?? null,
            'output_tokens' => $figures['output_tokens'] ?? null,
            'characters' => $figures['characters'] ?? null,
            'seconds' => $figures['seconds'] ?? null,
            'ok' => $ok,
            'created_at' => now(),
        ]));
    }
}

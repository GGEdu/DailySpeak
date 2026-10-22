<?php

namespace App\Services\Ai;

use App\Models\User;

/**
 * Resolves the connection of a user's task, for one call (never stored, never global).
 *
 * The rules, in order:
 *  - the user has picked no provider for the task: not configured.
 *  - the user picked "platform": the platform's connection, if the user may use it (admins always, others with
 *    users.platform_ai); otherwise not configured.
 *  - the user picked a provider and has its key: that key.
 *  - the user picked a provider and has no key for it: the platform's connection if the user may use it (an explicit
 *    permission, never a silent default); otherwise not configured.
 *
 * "Not configured" is App\Services\Ai\AiNotConfigured. The platform's key is never used otherwise.
 */
class AiConnections
{
    public const PLATFORM = 'platform';

    public function __construct(private PlatformAi $platform) {}

    /**
     * The connection of the user's task.
     *
     * @throws AiNotConfigured
     */
    public function forUser(User $user, string $task): AiConnection
    {
        $picked = $user->aiTasks()->where('task', $task)->first();

        // Nothing picked yet: whoever may use the platform (the owner, and those the owner allowed) keeps using it
        // until they pick their own provider. Anybody else has not configured the task.
        if ($picked === null) {
            return $this->platformFor($user, $task);
        }

        if ($picked->provider === self::PLATFORM) {
            return $this->platformFor($user, $task);
        }

        if (! ProviderCatalog::has($picked->provider) || ! ProviderCatalog::supportsTask($picked->provider, $task)) {
            throw new AiNotConfigured;
        }

        $key = $user->aiKeys()->where('provider', $picked->provider)->first();

        if ($key === null) {
            return $this->platformFor($user, $task);
        }

        $suggested = ProviderCatalog::suggestions($picked->provider);

        return new AiConnection(
            task: $task,
            paidBy: 'user',
            provider: $picked->provider,
            model: $picked->model ?: $suggested[$task],
            voice: $task === 'tts' ? ($picked->voice ?: $suggested['voice']) : null,
            config: [
                'driver' => ProviderCatalog::driver($picked->provider),
                'url' => ProviderCatalog::url($picked->provider),
                'key' => $key->key,
            ],
        );
    }

    /**
     * The user's connections for several tasks, or the first task that has none.
     *
     * @param  list<string>  $tasks
     * @return array<string, AiConnection>
     *
     * @throws AiNotConfigured
     */
    public function forUserTasks(User $user, array $tasks): array
    {
        $connections = [];

        foreach ($tasks as $task) {
            $connections[$task] = $this->forUser($user, $task);
        }

        return $connections;
    }

    /**
     * The platform's connection for a task, for a user who may use it. Nothing is returned to anybody else.
     *
     * @throws AiNotConfigured
     */
    private function platformFor(User $user, string $task): AiConnection
    {
        if (! $user->mayUsePlatformAi()) {
            throw new AiNotConfigured;
        }

        return $this->platform->connection($task);
    }
}

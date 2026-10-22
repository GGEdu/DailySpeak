<?php

namespace Tests\Support;

use App\Models\User;
use App\Models\UserAiKey;
use App\Models\UserAiTask;
use App\Services\Ai\ProviderCatalog;

/**
 * A user with their own AI connection, as the settings page leaves it: one key, and every task the provider can serve
 * picked with its suggested model. The key is stored encrypted, like the page stores it.
 */
trait ConfiguresUserAi
{
    /**
     * @param  list<string>  $tasks
     */
    protected function userWithAi(
        string $provider = 'openai',
        string $key = 'sk-user-test-0000',
        ?User $user = null,
        array $tasks = ['conversation', 'writing', 'stt', 'tts'],
    ): User {
        $user ??= User::factory()->withoutAi()->create();

        UserAiKey::query()->create([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'key' => $key,
            'last4' => substr($key, -4),
            'verified_at' => now(),
        ]);

        $suggested = ProviderCatalog::suggestions($provider);

        foreach ($tasks as $task) {
            if (! ProviderCatalog::supportsTask($provider, $task)) {
                continue;
            }

            UserAiTask::query()->create([
                'user_id' => $user->getKey(),
                'task' => $task,
                'provider' => $provider,
                'model' => $suggested[$task] ?? null,
                'voice' => $task === 'tts' ? $suggested['voice'] : null,
            ]);
        }

        return $user;
    }

    /**
     * Lets the user use the platform's connection. Set directly: platform_ai is not mass-assignable on purpose.
     */
    protected function allowPlatformAi(User $user): User
    {
        $user->forceFill(['platform_ai' => true])->save();

        return $user;
    }
}

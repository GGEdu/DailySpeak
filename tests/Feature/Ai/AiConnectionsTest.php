<?php

namespace Tests\Feature\Ai;

use App\Models\User;
use App\Services\Ai\AiConnection;
use App\Services\Ai\AiConnections;
use App\Services\Ai\AiNotConfigured;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AiManager;
use LogicException;
use Tests\Support\ConfiguresUserAi;
use Tests\TestCase;

class AiConnectionsTest extends TestCase
{
    use ConfiguresUserAi;
    use RefreshDatabase;

    private function connections(): AiConnections
    {
        return app(AiConnections::class);
    }

    public function test_a_user_with_a_key_gets_a_connection_paid_by_the_user(): void
    {
        $user = $this->userWithAi('groq', 'gsk-alice-1111');

        $connection = $this->connections()->forUser($user, 'stt');

        $this->assertSame('user', $connection->paidBy);
        $this->assertSame('groq', $connection->provider);
        $this->assertSame('whisper-large-v3-turbo', $connection->model);
        $this->assertNull($connection->voice);
    }

    public function test_a_task_the_user_never_picked_is_not_configured(): void
    {
        $user = $this->userWithAi('openai', 'sk-alice-1111', tasks: ['conversation']);

        $this->expectException(AiNotConfigured::class);

        $this->connections()->forUser($user, 'tts');
    }

    public function test_a_picked_provider_without_its_key_is_not_configured_for_a_user_who_may_not_use_the_platform(): void
    {
        $user = $this->userWithAi('openai', 'sk-alice-1111');
        $user->aiKeys()->delete();

        $this->expectException(AiNotConfigured::class);

        $this->connections()->forUser($user, 'conversation');
    }

    public function test_a_missing_key_falls_back_to_the_platform_only_for_a_user_who_is_allowed_it(): void
    {
        $user = $this->allowPlatformAi($this->userWithAi('openai', 'sk-alice-1111'));
        $user->aiKeys()->delete();

        $connection = $this->connections()->forUser($user, 'conversation');

        $this->assertSame('platform', $connection->paidBy);
    }

    public function test_a_task_set_to_the_platform_needs_the_permission(): void
    {
        $user = $this->userWithAi('openai', 'sk-alice-1111');
        $user->aiTasks()->where('task', 'conversation')->update(['provider' => 'platform']);

        $this->expectException(AiNotConfigured::class);

        $this->connections()->forUser($user->fresh(), 'conversation');
    }

    public function test_a_task_set_to_the_platform_uses_it_when_allowed(): void
    {
        $user = $this->allowPlatformAi($this->userWithAi('openai', 'sk-alice-1111'));
        $user->aiTasks()->where('task', 'conversation')->update(['provider' => 'platform']);

        $connection = $this->connections()->forUser($user->fresh(), 'conversation');

        $this->assertSame('platform', $connection->paidBy);
    }

    public function test_an_admin_may_use_the_platform_without_a_key(): void
    {
        $admin = User::factory()->admin()->withoutAi()->create();
        $admin->aiTasks()->create(['task' => 'conversation', 'provider' => 'platform']);

        $this->assertSame('platform', $this->connections()->forUser($admin, 'conversation')->paidBy);
    }

    public function test_a_connection_cannot_be_serialised_so_its_key_never_reaches_a_job_payload(): void
    {
        $connection = $this->connections()->forUser($this->userWithAi('openai', 'sk-alice-1111'), 'conversation');

        $this->assertInstanceOf(AiConnection::class, $connection);

        $this->expectException(LogicException::class);

        serialize($connection);
    }

    public function test_each_user_gets_their_own_key_and_config_is_not_written(): void
    {
        $before = config('ai.providers.openai.key');

        $alice = $this->userWithAi('openai', 'sk-alice-1111');
        $bruno = $this->userWithAi('openai', 'sk-bruno-2222');

        $aliceName = $this->connections()->forUser($alice, 'conversation')->providerName();
        $brunoName = $this->connections()->forUser($bruno, 'conversation')->providerName();

        $manager = app(AiManager::class);

        $this->assertNotSame($aliceName, $brunoName);
        $this->assertSame('sk-alice-1111', $manager->getInstanceConfig($aliceName)['key']);
        $this->assertSame('sk-bruno-2222', $manager->getInstanceConfig($brunoName)['key']);
        $this->assertSame($before, config('ai.providers.openai.key'));
    }

    public function test_the_built_provider_takes_the_fixed_address_of_its_provider(): void
    {
        $name = $this->connections()->forUser($this->userWithAi('openai', 'sk-alice-1111'), 'conversation')->providerName();

        $this->assertSame('https://api.openai.com/v1', app(AiManager::class)->getInstanceConfig($name)['url']);
    }
}

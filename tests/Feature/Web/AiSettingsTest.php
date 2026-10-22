<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Models\UserAiKey;
use App\Models\UserAiTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ConfiguresUserAi;
use Tests\TestCase;

/**
 * Settings → AI: a user's own keys (proved before they are kept, never returned) and the provider picked per task.
 */
class AiSettingsTest extends TestCase
{
    use ConfiguresUserAi;
    use RefreshDatabase;

    private const FACTORY_KEY = 'sk-factory-test-0000';

    /**
     * The pages these tests render (settings/Ai, settings/Sources, settings/Usage, admin/Users) are built by the client
     * side; the props they receive are what is checked here.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['inertia.testing.ensure_pages_exist' => false]);
    }

    public function test_the_page_lists_the_providers_and_the_tasks_without_any_key(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/ai')
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Ai')
                ->has('providers', 7)
                ->where('providers.0.key.set', true)
                ->where('providers.0.key.last4', '0000')
                ->where('tasks.tts.voice', 'alloy')
                ->where('platform.allowed', false)
                ->has('suggestions.openai'))
            ->assertDontSee(self::FACTORY_KEY, escape: false);
    }

    public function test_a_key_is_proved_then_kept_encrypted_and_never_returned(): void
    {
        $user = User::factory()->withoutAi()->create();
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => []])]);

        $this->actingAs($user)->put('/settings/ai/keys/openai', ['key' => 'sk-new-abcd1234'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-new-abcd1234'));

        $row = UserAiKey::query()->where('user_id', $user->id)->sole();
        $this->assertSame('1234', $row->last4);
        $this->assertNotNull($row->verified_at);
        $this->assertNotSame('sk-new-abcd1234', $row->getRawOriginal('key'), 'The key is stored encrypted.');
        $this->assertSame('sk-new-abcd1234', $row->key);
    }

    public function test_a_key_the_provider_refuses_is_not_kept(): void
    {
        $user = User::factory()->withoutAi()->create();
        Http::fake(['api.openai.com/v1/models' => Http::response(['error' => 'bad key sk-new-abcd1234'], 401)]);

        $this->actingAs($user)->put('/settings/ai/keys/openai', ['key' => 'sk-new-abcd1234'])
            ->assertSessionHasErrors(['key' => 'The provider refused this key.']);

        $this->assertSame(0, UserAiKey::query()->count());
    }

    public function test_an_unreachable_provider_keeps_nothing_and_says_so(): void
    {
        $user = User::factory()->withoutAi()->create();
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->actingAs($user)->put('/settings/ai/keys/openai', ['key' => 'sk-new-abcd1234'])
            ->assertSessionHasErrors(['key' => 'The provider could not be reached.']);

        $this->assertSame(0, UserAiKey::query()->count());
    }

    public function test_a_provider_off_the_list_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/ai/keys/nvidia', ['key' => 'x'])->assertNotFound();
        $this->actingAs($user)->put('/settings/ai/keys/https%3A%2F%2Fevil.example', ['key' => 'x'])->assertNotFound();
    }

    public function test_a_key_can_be_removed_and_the_tasks_that_picked_it_keep_their_choice(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/settings/ai/keys/openai')->assertRedirect();

        $this->assertSame(0, UserAiKey::query()->count());
        $this->assertSame(4, UserAiTask::query()->where('user_id', $user->id)->count());
    }

    public function test_a_task_needs_a_provider_that_does_it(): void
    {
        $user = $this->userWithAi('anthropic', 'sk-ant-test-1234', tasks: []);

        $this->actingAs($user)->put('/settings/ai/tasks', [
            'tasks' => ['stt' => ['provider' => 'anthropic', 'model' => 'x']],
        ])->assertSessionHasErrors(['tasks.stt' => 'This provider cannot do this task.']);
    }

    public function test_a_task_needs_the_users_key_unless_the_platform_is_allowed(): void
    {
        $user = User::factory()->withoutAi()->create();

        $this->actingAs($user)->put('/settings/ai/tasks', [
            'tasks' => ['conversation' => ['provider' => 'openai', 'model' => 'gpt-4o-mini']],
        ])->assertSessionHasErrors(['tasks.conversation' => 'Save a key for this provider first.']);

        $this->assertSame(0, UserAiTask::query()->count());
    }

    public function test_the_platform_is_picked_only_by_a_user_allowed_it(): void
    {
        $user = User::factory()->withoutAi()->create();

        $this->actingAs($user)->put('/settings/ai/tasks', [
            'tasks' => ['conversation' => ['provider' => 'platform']],
        ])->assertSessionHasErrors(['tasks.conversation' => 'The platform is not available to your account.']);

        $this->allowPlatformAi($user);

        $this->actingAs($user)->put('/settings/ai/tasks', [
            'tasks' => ['conversation' => ['provider' => 'platform']],
        ])->assertSessionHasNoErrors();

        $this->assertSame('platform', UserAiTask::query()->where('user_id', $user->id)->sole()->provider);
    }

    public function test_a_valid_choice_is_saved_for_each_task_and_tts_needs_a_voice(): void
    {
        $user = $this->userWithAi('groq', 'gsk-test-1234', tasks: []);

        $this->actingAs($user)->put('/settings/ai/tasks', [
            'tasks' => [
                'conversation' => ['provider' => 'groq', 'model' => 'llama-3.3-70b-versatile'],
                'stt' => ['provider' => 'groq', 'model' => 'whisper-large-v3-turbo'],
            ],
        ])->assertSessionHasNoErrors();

        $conversation = UserAiTask::query()->where('user_id', $user->id)->where('task', 'conversation')->sole();
        $this->assertSame(['groq', 'llama-3.3-70b-versatile'], [$conversation->provider, $conversation->model]);

        $this->actingAs($user)->put('/settings/ai/tasks', [
            'tasks' => ['tts' => ['provider' => 'groq']],
        ])->assertSessionHasErrors(['tasks.tts' => 'This provider cannot do this task.']);
    }

    public function test_a_task_not_sent_keeps_its_choice_and_one_sent_without_a_provider_loses_it(): void
    {
        $user = $this->userWithAi('openai', 'sk-user-test-1234');

        $this->actingAs($user)->put('/settings/ai/tasks', [
            'tasks' => ['writing' => ['provider' => null]],
        ])->assertSessionHasNoErrors();

        $this->assertNull(UserAiTask::query()->where('user_id', $user->id)->where('task', 'writing')->first());
        $this->assertNotNull(UserAiTask::query()->where('user_id', $user->id)->where('task', 'conversation')->first());
    }

    public function test_the_saved_key_is_proved_again_on_request_and_its_result_is_returned(): void
    {
        $user = $this->userWithAi('openai', 'sk-user-test-1234');
        Http::fake(['api.openai.com/v1/models' => Http::response(['data' => []])]);

        $this->actingAs($user)->postJson('/settings/ai/test/openai')
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'The key works.']);
    }

    public function test_a_test_without_a_saved_key_says_so_and_sends_nothing(): void
    {
        $user = User::factory()->withoutAi()->create();
        Http::fake();

        $this->actingAs($user)->postJson('/settings/ai/test/openai')
            ->assertOk()
            ->assertJson(['ok' => false, 'message' => 'No key is saved for this provider.']);

        Http::assertNothingSent();
    }
}

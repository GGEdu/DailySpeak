<?php

namespace Tests\Feature\Ai;

use App\Models\AiUsage;
use App\Models\User;
use App\Services\Ai\AiCalls;
use App\Services\Ai\AiCapReached;
use App\Services\Ai\AiConnection;
use App\Services\Ai\AiNotConfigured;
use App\Services\AiBudgetUsedUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\TextUsage;
use RuntimeException;
use Tests\Support\ConfiguresUserAi;
use Tests\TestCase;

class AiCallsTest extends TestCase
{
    use ConfiguresUserAi;
    use RefreshDatabase;

    private function connection(string $paidBy = 'user'): AiConnection
    {
        return new AiConnection(
            task: 'conversation',
            paidBy: $paidBy,
            provider: 'openai',
            model: 'gpt-4o-mini',
            voice: null,
            config: ['driver' => 'openai', 'key' => 'sk-test-0000', 'url' => 'https://api.openai.com/v1'],
        );
    }

    private function answer(int $input, int $output): object
    {
        $response = new \stdClass;
        $response->usage = new TextUsage($input, $output);

        return $response;
    }

    public function test_a_call_is_recorded_with_its_sizes_and_no_content(): void
    {
        $user = User::factory()->create();
        $response = $this->answer(12, 34);

        $result = app(AiCalls::class)->call($this->connection(), $user, fn () => $response, fn ($r) => AiCalls::textUsage($r));

        $this->assertSame($response, $result);

        $row = AiUsage::query()->sole();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('conversation', $row->task);
        $this->assertSame('openai', $row->provider);
        $this->assertSame('gpt-4o-mini', $row->model);
        $this->assertSame('user', $row->paid_by);
        $this->assertSame(12, $row->input_tokens);
        $this->assertSame(34, $row->output_tokens);
        $this->assertNull($row->characters);
        $this->assertTrue($row->ok);
    }

    public function test_a_failed_call_is_recorded_as_not_ok_and_the_error_is_rethrown(): void
    {
        $user = User::factory()->create();

        try {
            app(AiCalls::class)->call($this->connection(), $user, fn () => throw new RuntimeException('provider down'));
            $this->fail('The error was swallowed.');
        } catch (RuntimeException $e) {
            $this->assertSame('provider down', $e->getMessage());
        }

        $this->assertFalse(AiUsage::query()->sole()->ok);
    }

    public function test_a_call_refused_by_the_monthly_cap_is_not_made_and_not_recorded(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['ai_monthly_cap' => 1])->save();
        AiUsage::query()->create(['user_id' => $user->id, 'task' => 'conversation', 'provider' => 'openai', 'paid_by' => 'user', 'ok' => true, 'created_at' => now()]);

        $made = false;

        try {
            app(AiCalls::class)->call($this->connection(), $user, function () use (&$made) {
                $made = true;
            });
            $this->fail('The cap did not refuse the call.');
        } catch (AiCapReached) {
            // expected
        }

        $this->assertFalse($made);
        $this->assertSame(1, AiUsage::query()->count());
    }

    public function test_the_cap_counts_the_calls_of_this_month_only(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['ai_monthly_cap' => 1])->save();
        AiUsage::query()->create(['user_id' => $user->id, 'task' => 'conversation', 'provider' => 'openai', 'paid_by' => 'user', 'ok' => true, 'created_at' => now()->subMonths(2)]);

        $this->assertSame(0, app(AiCalls::class)->usedThisMonth($user));
        $this->assertNull(app(AiCalls::class)->call($this->connection(), $user, fn () => null));
    }

    public function test_no_cap_means_no_limit(): void
    {
        $user = User::factory()->create();
        AiUsage::query()->insert(array_fill(0, 5, ['user_id' => $user->id, 'task' => 'conversation', 'provider' => 'openai', 'paid_by' => 'user', 'ok' => true, 'created_at' => now()]));

        app(AiCalls::class)->call($this->connection(), $user, fn () => 'ok');

        $this->assertSame(6, AiUsage::query()->count());
    }

    public function test_a_platform_call_made_for_a_user_takes_the_daily_budget(): void
    {
        config(['limits.ai_daily_global' => 1]);
        $user = User::factory()->create();

        app(AiCalls::class)->call($this->connection('platform'), $user, fn () => 'first');

        $this->expectException(AiBudgetUsedUp::class);

        app(AiCalls::class)->call($this->connection('platform'), $user, fn () => 'second');
    }

    public function test_a_platform_call_with_no_user_takes_no_budget_as_before(): void
    {
        config(['limits.ai_daily_global' => 1]);

        app(AiCalls::class)->call($this->connection('platform'), null, fn () => 'news');
        app(AiCalls::class)->call($this->connection('platform'), null, fn () => 'news');

        $this->assertSame(2, AiUsage::query()->whereNull('user_id')->count());
    }

    public function test_a_user_call_does_not_take_the_platform_budget(): void
    {
        config(['limits.ai_daily_global' => 1]);
        $user = User::factory()->create();

        app(AiCalls::class)->call($this->connection('user'), $user, fn () => 'first');
        app(AiCalls::class)->call($this->connection('user'), $user, fn () => 'second');

        $this->assertSame(2, AiUsage::query()->count());
    }

    public function test_check_refuses_a_user_without_a_connection_and_returns_the_connection_otherwise(): void
    {
        $user = User::factory()->withoutAi()->create();

        try {
            app(AiCalls::class)->check($user, 'conversation');
            $this->fail('A user without a connection was let through.');
        } catch (AiNotConfigured) {
            // expected
        }

        $ready = $this->userWithAi('openai', 'sk-alice-1111');

        $this->assertSame('user', app(AiCalls::class)->check($ready, 'conversation')->paidBy);
        $this->assertSame(0, AiUsage::query()->count(), 'A check writes nothing.');
    }

    public function test_check_refuses_a_platform_connection_when_the_budget_is_used_up(): void
    {
        config(['limits.ai_daily_global' => 1]);
        $user = $this->allowPlatformAi(User::factory()->withoutAi()->create());
        $user->aiTasks()->create(['task' => 'conversation', 'provider' => 'platform']);
        app(AiCalls::class)->call($this->connection('platform'), $user, fn () => 'first');

        $this->expectException(AiBudgetUsedUp::class);

        app(AiCalls::class)->check($user->fresh(), 'conversation');
    }
}

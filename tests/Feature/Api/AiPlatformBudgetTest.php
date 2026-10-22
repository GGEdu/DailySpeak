<?php

namespace Tests\Feature\Api;

use App\Ai\Agents\WordExplainer;
use App\Models\Debate;
use App\Models\User;
use App\Services\AiBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\Support\ConfiguresUserAi;
use Tests\TestCase;

/**
 * The platform's daily budget counts only the calls the platform pays for: a user's own key takes nothing from it. A
 * user the platform is allowed to serve (users.platform_ai) takes one call per paid call, as the platform does.
 */
class AiPlatformBudgetTest extends TestCase
{
    use ConfiguresUserAi;
    use RefreshDatabase;

    private const BUDGET_USED = 'The daily AI budget is used up. Try again tomorrow.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    /**
     * A user whose every task runs on the platform (and who is allowed the platform).
     */
    private function platformUser(): User
    {
        $user = $this->allowPlatformAi(User::factory()->create());
        $user->aiTasks()->update(['provider' => 'platform']);

        return $user;
    }

    public function test_a_users_own_key_takes_nothing_from_the_platform_budget(): void
    {
        config(['limits.ai_daily_global' => 1]);
        app(AiBudget::class)->spend(null);
        WordExplainer::fake([WordLookupTest::EXPLANATION]);
        Sanctum::actingAs($this->userWithAi('openai', 'sk-own-1111'));

        $this->postJson('/api/lookups', ['text' => 'powering through'])->assertOk();

        $this->assertSame(1, app(AiBudget::class)->usedToday());
    }

    public function test_a_platform_call_takes_one_call_and_is_refused_when_the_budget_is_used_up(): void
    {
        config(['limits.ai_daily_global' => 1]);
        WordExplainer::fake([WordLookupTest::EXPLANATION]);
        Sanctum::actingAs($this->platformUser());

        $this->postJson('/api/lookups', ['text' => 'powering through'])->assertOk();
        $this->postJson('/api/lookups', ['text' => 'a different phrase'])
            ->assertTooManyRequests()
            ->assertJson(['message' => self::BUDGET_USED]);
    }

    public function test_a_refused_request_takes_nothing_from_the_budget(): void
    {
        config(['limits.ai_daily_global' => 5]);
        Sanctum::actingAs(User::factory()->withoutAi()->create());

        $this->postJson('/api/lookups', ['text' => 'powering through'])->assertConflict();

        $this->assertSame(0, app(AiBudget::class)->usedToday());
    }

    public function test_a_translation_answered_from_the_cache_takes_nothing_from_the_budget(): void
    {
        config(['limits.ai_daily_global' => 1]);
        WordExplainer::fake([WordLookupTest::EXPLANATION]);
        Sanctum::actingAs($this->platformUser());

        $this->postJson('/api/lookups', ['text' => 'powering through'])->assertOk();
        $this->postJson('/api/lookups', ['text' => 'powering through'])->assertOk();

        $this->assertSame(1, app(AiBudget::class)->usedToday());
    }

    public function test_a_voice_upload_is_refused_when_the_platform_budget_is_used_up(): void
    {
        config(['limits.ai_daily_global' => 1]);
        app(AiBudget::class)->spend(null);
        $user = $this->platformUser();
        $debate = Debate::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->post("/api/debates/{$debate->id}/audio", [
            'audio' => UploadedFile::fake()->create('turn.webm', 10, 'audio/webm'),
        ])
            ->assertTooManyRequests()
            ->assertJson(['message' => self::BUDGET_USED]);
    }

    public function test_finishing_a_debate_checks_the_platform_budget_before_closing_the_debate(): void
    {
        config(['limits.ai_daily_global' => 1]);
        app(AiBudget::class)->spend(null);
        $owner = $this->platformUser();
        $debate = Debate::factory()->for($owner)->create();

        $this->actingAs($owner)->post("/debates/{$debate->id}/finish")
            ->assertSessionHasErrors(['finish' => self::BUDGET_USED]);

        $this->assertTrue($debate->fresh()->isActive(), 'A refused report leaves the debate open.');
    }

    public function test_admins_are_not_counted_against_the_budget(): void
    {
        config(['limits.ai_daily_global' => 1]);
        WordExplainer::fake([WordLookupTest::EXPLANATION]);
        $admin = User::factory()->admin()->withoutAi()->create();
        $admin->aiTasks()->create(['task' => 'conversation', 'provider' => 'platform']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/lookups', ['text' => 'powering through'])->assertOk();

        $this->assertSame(0, app(AiBudget::class)->usedToday());
    }

    public function test_reaching_the_budget_is_logged_critically_once_a_day(): void
    {
        config(['limits.ai_daily_global' => 1]);
        Log::spy();
        WordExplainer::fake([WordLookupTest::EXPLANATION]);
        Sanctum::actingAs($this->platformUser());

        $this->postJson('/api/lookups', ['text' => 'powering through'])->assertOk();
        $this->postJson('/api/lookups', ['text' => 'a different phrase'])->assertTooManyRequests();
        $this->postJson('/api/lookups', ['text' => 'another phrase'])->assertTooManyRequests();

        Log::shouldHaveReceived('critical')->once()->with(Mockery::pattern('/daily AI call budget is used up/'));
    }
}

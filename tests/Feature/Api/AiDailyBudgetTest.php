<?php

namespace Tests\Feature\Api;

use App\Ai\Agents\WordExplainer;
use App\Jobs\ProcessVoiceDebate;
use App\Models\Debate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiDailyBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const BUDGET_USED = 'The daily AI budget is used up. Try again tomorrow.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    public function test_the_budget_is_shared_by_every_user_and_every_ai_route(): void
    {
        config(['limits.ai_daily_global' => 2]);
        WordExplainer::fake([WordLookupTest::EXPLANATION])->preventStrayPrompts();

        foreach ([User::factory()->create(), User::factory()->create()] as $user) {
            $this->uploadVoiceTurn($user)->assertAccepted();
        }

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/lookups', ['text' => 'nuance'])
            ->assertStatus(429)
            ->assertJsonPath('message', self::BUDGET_USED);
        Queue::assertPushed(ProcessVoiceDebate::class, 2);
    }

    public function test_reaching_the_budget_is_logged_critically_once_a_day(): void
    {
        config(['limits.ai_daily_global' => 1]);
        Log::spy();

        $this->uploadVoiceTurn(User::factory()->create())->assertAccepted();
        $this->uploadVoiceTurn(User::factory()->create())->assertStatus(429);
        $this->uploadVoiceTurn(User::factory()->create())->assertStatus(429);

        Log::shouldHaveReceived('critical')->once();
    }

    public function test_admins_are_not_counted_against_the_budget(): void
    {
        config(['limits.ai_daily_global' => 1]);
        WordExplainer::fake([WordLookupTest::EXPLANATION, WordLookupTest::EXPLANATION, WordLookupTest::EXPLANATION])->preventStrayPrompts();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/lookups', ['text' => 'nuance'])->assertOk();
        $this->postJson('/api/lookups', ['text' => 'lapse'])->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/lookups', ['text' => 'falter'])->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/lookups', ['text' => 'brisk'])->assertStatus(429);
    }

    private function uploadVoiceTurn(User $user): TestResponse
    {
        $debate = Debate::factory()->for($user)->create();
        Sanctum::actingAs($user);

        return $this->post("/api/debates/{$debate->id}/audio", [
            'audio' => UploadedFile::fake()->create('turn.webm', 10, 'audio/webm'),
        ]);
    }
}

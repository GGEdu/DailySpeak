<?php

namespace Tests\Feature\Api;

use App\Ai\Agents\WordExplainer;
use App\Models\AiUsage;
use App\Models\Debate;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ConfiguresUserAi;
use Tests\TestCase;

/**
 * What the API and the page answer when the user cannot pay for a call: 409 when there is no connection to use, 429
 * when the monthly cap is reached. Web answers the same refusal under the "ai" key.
 */
class AiConnectionErrorsTest extends TestCase
{
    use ConfiguresUserAi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    public function test_starting_a_debate_without_a_usable_connection_is_a_409(): void
    {
        $user = User::factory()->withoutAi()->create();
        $article = NewsArticle::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/news-articles/{$article->id}/debates")
            ->assertConflict()
            ->assertJson(['message' => 'Add your AI key in Settings → AI.', 'code' => 'ai_not_configured']);

        $this->assertSame(0, Debate::query()->count());
    }

    public function test_starting_a_debate_when_the_monthly_cap_is_reached_is_a_429(): void
    {
        $user = $this->userWithAi();
        $user->forceFill(['ai_monthly_cap' => 1])->save();
        AiUsage::query()->create(['user_id' => $user->id, 'task' => 'conversation', 'provider' => 'openai', 'paid_by' => 'user', 'ok' => true, 'created_at' => now()]);
        $article = NewsArticle::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/news-articles/{$article->id}/debates")
            ->assertTooManyRequests()
            ->assertJson(['code' => 'ai_cap_reached']);

        $this->assertSame(0, Debate::query()->count());
    }

    public function test_a_voice_turn_without_a_usable_connection_is_a_409_and_keeps_no_recording(): void
    {
        $user = User::factory()->withoutAi()->create();
        $debate = Debate::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->withHeaders(['Accept' => 'application/json'])->post("/api/debates/{$debate->id}/audio", [
            'audio' => UploadedFile::fake()->create('turn.webm', 10, 'audio/webm'),
        ])
            ->assertConflict()
            ->assertJson(['code' => 'ai_not_configured']);

        Queue::assertNothingPushed();
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_a_translation_without_a_usable_connection_is_a_409(): void
    {
        WordExplainer::fake([WordLookupTest::EXPLANATION])->preventStrayPrompts();
        Sanctum::actingAs(User::factory()->withoutAi()->create());

        $this->postJson('/api/lookups', ['text' => 'powering through'])
            ->assertConflict()
            ->assertJson(['code' => 'ai_not_configured']);

        WordExplainer::assertNeverPrompted();
    }

    public function test_a_translation_at_the_monthly_cap_is_a_429(): void
    {
        WordExplainer::fake([WordLookupTest::EXPLANATION])->preventStrayPrompts();
        $user = $this->userWithAi();
        $user->forceFill(['ai_monthly_cap' => 1])->save();
        AiUsage::query()->create(['user_id' => $user->id, 'task' => 'conversation', 'provider' => 'openai', 'paid_by' => 'user', 'ok' => true, 'created_at' => now()]);
        Sanctum::actingAs($user);

        $this->postJson('/api/lookups', ['text' => 'powering through'])
            ->assertTooManyRequests()
            ->assertJson(['code' => 'ai_cap_reached']);

        WordExplainer::assertNeverPrompted();
    }

    public function test_a_translation_answered_from_the_cache_costs_nothing_even_without_a_connection(): void
    {
        WordExplainer::fake([WordLookupTest::EXPLANATION]);
        $asker = $this->userWithAi();
        Sanctum::actingAs($asker);
        $this->postJson('/api/lookups', ['text' => 'powering through'])->assertOk();

        $asker->aiKeys()->delete();
        $asker->aiTasks()->delete();

        $this->postJson('/api/lookups', ['text' => 'powering through'])->assertOk();
        $this->assertSame(1, AiUsage::query()->count());
    }

    public function test_a_refused_translation_is_not_recorded_as_a_call(): void
    {
        Sanctum::actingAs(User::factory()->withoutAi()->create());

        $this->postJson('/api/lookups', ['text' => 'powering through'])->assertConflict();

        $this->assertSame(0, AiUsage::query()->count());
    }
}

<?php

namespace Tests\Feature\Api;

use App\Ai\Agents\WordExplainer;
use App\Enums\DebateStatus;
use App\Jobs\AnalyzeVocabulary;
use App\Jobs\EvaluateDebate;
use App\Jobs\ProcessVoiceDebate;
use App\Models\Debate;
use App\Models\User;
use App\Models\UserVocabulary;
use App\Services\AiBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
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

    public function test_every_route_that_calls_the_ai_takes_the_budget_inside_its_controller(): void
    {
        // A request refused before its paid call (403, 404, 422) or answered from a cache costs nothing, so no route
        // takes the budget in middleware: each controller takes it right before the call it is about to make.
        foreach (['debates.audio.store', 'lookups.store', 'vocabulary.store', 'vocabulary.analyze', 'debates.finish'] as $name) {
            $this->assertNotContains('throttle:ai-budget', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }
    }

    public function test_requests_refused_before_the_paid_call_take_nothing_from_the_budget(): void
    {
        config(['limits.ai_daily_global' => 1]);
        WordExplainer::fake([WordLookupTest::EXPLANATION])->preventStrayPrompts();
        $owner = User::factory()->create();
        $debate = Debate::factory()->for($owner)->create();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post("/debates/{$debate->id}/finish")->assertForbidden();
        $this->actingAs($stranger)->post('/debates/999999/finish')->assertNotFound();

        Sanctum::actingAs($owner);
        $this->postJson("/api/debates/{$debate->id}/audio", [])->assertUnprocessable();

        Sanctum::actingAs($stranger);
        $this->postJson('/api/lookups', ['text' => ''])->assertUnprocessable();

        // The one call is still there for a real lookup, and that lookup is the one that takes it.
        $this->postJson('/api/lookups', ['text' => 'nuance'])->assertOk();
        $this->postJson('/api/lookups', ['text' => 'lapse'])->assertStatus(429);
    }

    public function test_a_translation_answered_from_the_cache_takes_nothing_from_the_budget(): void
    {
        config(['limits.ai_daily_global' => 1]);
        WordExplainer::fake([WordLookupTest::EXPLANATION])->preventStrayPrompts();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/lookups', ['text' => 'nuance', 'context' => 'A nuance of meaning.'])->assertOk();
        // Same selection in the same sentence: the cached answer, so the budget still has nothing left to take.
        $this->postJson('/api/lookups', ['text' => 'nuance', 'context' => 'A nuance of meaning.'])->assertOk();
        $this->postJson('/api/lookups', ['text' => 'lapse'])->assertStatus(429);

        WordExplainer::assertPromptedTimes(1);
    }

    public function test_a_repeated_upload_of_a_turn_is_answered_without_taking_the_budget(): void
    {
        config(['limits.ai_daily_global' => 1]);
        $user = User::factory()->create();
        $debate = Debate::factory()->for($user)->create();
        $turn = '3f9b2a6e-7c1d-4e8a-9b5f-2d6c8a1e4f70';
        Sanctum::actingAs($user);

        foreach (range(1, 2) as $upload) {
            $this->post("/api/debates/{$debate->id}/audio", [
                'audio' => UploadedFile::fake()->create('turn.webm', 10, 'audio/webm'),
                'turn' => $turn,
            ])->assertAccepted();
        }

        // The first upload was queued; the second was only recognised, so it cost no call.
        Queue::assertPushedTimes(ProcessVoiceDebate::class, 1);
    }

    public function test_analysing_a_word_takes_a_call_only_when_it_has_none_yet(): void
    {
        config(['limits.ai_daily_global' => 1]);
        WordExplainer::fake([WordLookupTest::EXPLANATION, WordLookupTest::EXPLANATION])->preventStrayPrompts();
        $owner = User::factory()->create();
        $analysed = UserVocabulary::factory()->for($owner)->create(['analysis' => ['definition' => 'To keep going.']]);
        $needsIt = UserVocabulary::factory()->for($owner)->create(['analysis' => null]);
        $waiting = UserVocabulary::factory()->for($owner)->create(['analysis' => null]);

        $this->actingAs($owner)->post("/vocabulary/{$analysed->id}/analyze")->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("/vocabulary/{$needsIt->id}/analyze")->assertSessionHasNoErrors();

        // The budget is used up: the page is told why, and the word is left as it was.
        $this->actingAs($owner)->post("/vocabulary/{$waiting->id}/analyze")
            ->assertSessionHasErrors(['analysis' => AiBudget::USED_UP_MESSAGE]);
        $this->assertNull($waiting->refresh()->analysis);
    }

    public function test_saving_a_word_takes_a_call_only_when_it_is_queued_for_analysis(): void
    {
        config(['limits.ai_daily_global' => 1]);
        $user = User::factory()->create();
        $user->vocabularies()->create(['word' => 'nuance', 'mastery_level' => 3, 'next_review_at' => now(), 'analysis' => ['definition' => 'To keep going.']]);
        Sanctum::actingAs($user);

        // Already analysed: nothing is queued, so nothing is taken.
        $this->postJson('/api/vocabulary', ['word' => 'nuance'])->assertOk();

        $this->postJson('/api/vocabulary', ['word' => 'lapse'])->assertCreated();
        $this->postJson('/api/vocabulary', ['word' => 'falter'])
            ->assertStatus(429)
            ->assertJsonPath('message', self::BUDGET_USED);

        Queue::assertPushed(AnalyzeVocabulary::class, 1);
        $this->assertFalse($user->vocabularies()->where('word', 'falter')->exists());
    }

    public function test_finishing_a_debate_takes_one_call_and_says_so_when_the_budget_is_used_up(): void
    {
        config(['limits.ai_daily_global' => 1]);
        $first = Debate::factory()->create();
        $second = Debate::factory()->create();

        $this->actingAs($first->user)->post("/debates/{$first->id}/finish")->assertRedirect();
        $this->actingAs($second->user)->post("/debates/{$second->id}/finish")
            ->assertSessionHasErrors(['finish' => AiBudget::USED_UP_MESSAGE]);

        // The debate stays open, so its owner can try again tomorrow.
        $this->assertSame(DebateStatus::Active, $second->refresh()->status);
        Queue::assertPushedTimes(EvaluateDebate::class, 1);
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

<?php

namespace Tests\Feature\Api;

use App\Ai\Agents\WordExplainer;
use App\Jobs\AnalyzeVocabulary;
use App\Models\User;
use App\Models\UserVocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaveWordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = User::factory()->create();
    }

    public function test_guests_cannot_save_words(): void
    {
        $this->postJson('/api/vocabulary', ['word' => 'nuance'])->assertUnauthorized();
    }

    public function test_a_selected_word_is_saved_due_now_and_queued_for_analysis(): void
    {
        $this->freezeSecond();
        Sanctum::actingAs($this->user);

        $this->postJson('/api/vocabulary', ['word' => ' Powering through ', 'context' => 'Are people just powering through illness?'])
            ->assertCreated()
            ->assertJson(['word' => 'powering through', 'created' => true]);

        $word = $this->user->vocabularies()->sole();
        $this->assertSame('Are people just powering through illness?', $word->context);
        $this->assertSame(UserVocabulary::MIN_MASTERY, $word->mastery_level);
        $this->assertTrue($word->next_review_at->lte(now()));
        Queue::assertPushed(AnalyzeVocabulary::class, fn (AnalyzeVocabulary $job) => $job->word->is($word));
    }

    public function test_saving_a_word_twice_keeps_one_entry_and_fills_a_missing_context(): void
    {
        $existing = UserVocabulary::factory()->for($this->user)->create(['word' => 'nuance', 'context' => null, 'mastery_level' => 3]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/vocabulary', ['word' => 'Nuance', 'context' => 'The report lacks nuance.'])
            ->assertOk()
            ->assertJson(['id' => $existing->id, 'created' => false]);

        $this->assertSame(1, $this->user->vocabularies()->count());
        $this->assertSame('The report lacks nuance.', $existing->fresh()->context);
        $this->assertSame(3, $existing->fresh()->mastery_level);
    }

    public function test_an_analysed_word_is_not_analysed_again(): void
    {
        UserVocabulary::factory()->for($this->user)->create(['word' => 'nuance', 'analysis' => ['definition' => 'A subtle difference.']]);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/vocabulary', ['word' => 'nuance'])->assertOk();

        Queue::assertNotPushed(AnalyzeVocabulary::class);
    }

    public function test_saving_a_word_just_translated_reuses_the_translation(): void
    {
        // This job must really run (synchronously), the rest stays faked.
        Queue::fake()->except([AnalyzeVocabulary::class]);
        WordExplainer::fake([WordLookupTest::EXPLANATION])->preventStrayPrompts();
        Sanctum::actingAs($this->user);
        $sentence = 'Are people   just powering through illness?';

        $this->postJson('/api/lookups', ['text' => 'Powering through', 'context' => $sentence])->assertOk();
        $this->postJson('/api/vocabulary', ['word' => 'Powering through', 'context' => $sentence])->assertCreated();

        // One prompt in total: the job answered from the lookup cache.
        $this->assertSame('sobrellevar', $this->user->vocabularies()->sole()->translation);
    }

    public function test_sentences_are_not_saved_as_words(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/vocabulary', ['word' => 'this is far too many words to be vocabulary'])->assertUnprocessable();

        $this->assertSame(0, $this->user->vocabularies()->count());
    }
}

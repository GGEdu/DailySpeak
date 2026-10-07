<?php

namespace Tests\Feature\Jobs;

use App\Ai\Agents\WordExplainer;
use App\Enums\EnglishLevel;
use App\Jobs\AnalyzeVocabulary;
use App\Models\User;
use App\Models\UserVocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Feature\Api\WordLookupTest;
use Tests\TestCase;

class AnalyzeVocabularyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_the_translation_and_the_analysis(): void
    {
        WordExplainer::fake([WordLookupTest::EXPLANATION])->preventStrayPrompts();
        $word = UserVocabulary::factory()
            ->for(User::factory()->level(EnglishLevel::C1))
            ->create(['word' => 'powering through', 'context' => 'Are people just powering through illness?']);

        AnalyzeVocabulary::dispatch($word);

        $word->refresh();
        $this->assertSame('sobrellevar', $word->translation);
        // jsonb reorders keys, so compare by key, not by position.
        $this->assertEquals([
            'part_of_speech' => 'phrasal verb',
            'definition' => 'To keep going despite difficulty.',
            'example' => 'She powered through the last mile of the race.',
            'synonyms' => ['push on', 'persevere', 'soldier on'],
        ], $word->analysis);
        WordExplainer::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'Sentence: Are people just powering through illness?'));
    }

    public function test_a_word_already_analysed_is_left_alone(): void
    {
        WordExplainer::fake()->preventStrayPrompts();
        $word = UserVocabulary::factory()->create(['translation' => 'matiz', 'analysis' => ['definition' => 'A subtle difference.']]);

        AnalyzeVocabulary::dispatch($word);

        WordExplainer::assertNeverPrompted();
        $this->assertSame('matiz', $word->fresh()->translation);
    }
}

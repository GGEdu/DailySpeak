<?php

namespace Tests\Feature\Api;

use App\Ai\Agents\WordExplainer;
use App\Enums\EnglishLevel;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WordLookupTest extends TestCase
{
    use RefreshDatabase;

    public const EXPLANATION = [
        'translation' => 'sobrellevar',
        'part_of_speech' => 'phrasal verb',
        'definition' => 'To keep going despite difficulty.',
        'example' => 'She powered through the last mile of the race.',
        'synonyms' => ['push on', 'persevere', 'soldier on', 'keep going'],
    ];

    private const SENTENCE = 'Are people healthier, or just powering through illness?';

    public function test_guests_cannot_look_up_words(): void
    {
        $this->postJson('/api/lookups', ['text' => 'nuance'])->assertUnauthorized();
    }

    public function test_it_explains_the_selection_in_its_sentence(): void
    {
        WordExplainer::fake([self::EXPLANATION])->preventStrayPrompts();
        Sanctum::actingAs(User::factory()->level(EnglishLevel::B2)->create());

        $this->postJson('/api/lookups', ['text' => '  powering through ', 'context' => self::SENTENCE])
            ->assertOk()
            ->assertExactJson([
                'text' => 'powering through',
                'translation' => 'sobrellevar',
                'part_of_speech' => 'phrasal verb',
                'definition' => 'To keep going despite difficulty.',
                'example' => 'She powered through the last mile of the race.',
                // At most three synonyms.
                'synonyms' => ['push on', 'persevere', 'soldier on'],
            ]);

        WordExplainer::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'Selected: powering through')
            && str_contains($prompt->prompt, 'Sentence: '.self::SENTENCE));
    }

    public function test_the_explanation_is_pitched_at_the_users_level_and_language(): void
    {
        config(['lookup.target_language' => 'Spanish']);

        $instructions = (string) (new WordExplainer(EnglishLevel::B1))->instructions();

        $this->assertStringContainsString('Spanish', $instructions);
        $this->assertStringContainsString('(B1)', $instructions);
        $this->assertStringNotContainsString(':language', $instructions);
    }

    public function test_looking_up_the_same_selection_again_costs_nothing(): void
    {
        WordExplainer::fake([self::EXPLANATION])->preventStrayPrompts();
        Sanctum::actingAs(User::factory()->create());

        $payload = ['text' => 'powering through', 'context' => self::SENTENCE];
        $this->postJson('/api/lookups', $payload)->assertOk();
        $this->postJson('/api/lookups', ['text' => 'Powering Through'] + $payload)->assertOk()->assertJsonPath('translation', 'sobrellevar');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidSelections(): array
    {
        return [
            'empty' => [['text' => '   ']],
            'a whole sentence' => [['text' => 'this is far too many words to be vocabulary']],
            'too long' => [['text' => str_repeat('a', 61)]],
            'no letters' => [['text' => '1984 —']],
            'huge context' => [['text' => 'nuance', 'context' => str_repeat('a', 2001)]],
        ];
    }

    #[DataProvider('invalidSelections')]
    public function test_selections_that_are_not_vocabulary_are_rejected(array $payload): void
    {
        WordExplainer::fake()->preventStrayPrompts();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/lookups', $payload)->assertUnprocessable();

        WordExplainer::assertNeverPrompted();
    }

    public function test_an_empty_answer_is_an_error_and_is_not_cached(): void
    {
        WordExplainer::fake([[], self::EXPLANATION])->preventStrayPrompts();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/lookups', ['text' => 'nuance'])->assertServiceUnavailable();
        $this->postJson('/api/lookups', ['text' => 'nuance'])->assertOk()->assertJsonPath('translation', 'sobrellevar');
    }

    public function test_words_with_accents_and_symbols_are_accepted(): void
    {
        WordExplainer::fake([self::EXPLANATION, self::EXPLANATION])->preventStrayPrompts();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/lookups', ['text' => 'Brontë'])->assertOk()->assertJsonPath('text', 'Brontë');
        $this->postJson('/api/lookups', ['text' => '“€5 billion”'])->assertOk()->assertJsonPath('text', '€5 billion');
    }

    public function test_the_same_word_at_another_level_is_explained_again(): void
    {
        WordExplainer::fake([self::EXPLANATION, ['translation' => 'matiz'] + self::EXPLANATION])->preventStrayPrompts();
        $user = User::factory()->level(EnglishLevel::B1)->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/lookups', ['text' => 'nuance'])->assertOk()->assertJsonPath('translation', 'sobrellevar');
        $user->update(['current_level' => EnglishLevel::C1]);
        $this->postJson('/api/lookups', ['text' => 'nuance'])->assertOk()->assertJsonPath('translation', 'matiz');
    }

    public function test_lookups_are_rate_limited_per_minute_and_per_day(): void
    {
        WordExplainer::fake([self::EXPLANATION])->preventStrayPrompts();
        Sanctum::actingAs(User::factory()->create());

        // Cached after the first one, so only the limiter can stop them.
        for ($i = 0; $i < AppServiceProvider::LOOKUPS_PER_MINUTE; $i++) {
            $this->postJson('/api/lookups', ['text' => 'nuance'])->assertOk();
        }
        $this->postJson('/api/lookups', ['text' => 'nuance'])->assertTooManyRequests();

        $this->travel(2)->minutes();
        $this->postJson('/api/lookups', ['text' => 'nuance'])->assertOk();
        $this->assertGreaterThan(AppServiceProvider::LOOKUPS_PER_MINUTE, AppServiceProvider::LOOKUPS_PER_DAY);
    }

    public function test_a_provider_failure_is_reported_as_unavailable(): void
    {
        WordExplainer::fake(fn () => throw new RuntimeException('Provider down'));
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/lookups', ['text' => 'nuance'])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', "Couldn't translate that right now. Try again in a moment.");
    }
}

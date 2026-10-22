<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\DebateEvaluator;
use App\Enums\EnglishLevel;
use App\Services\Ai\AiConnection;
use App\Services\Ai\ProviderCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\AiManager;
use Tests\TestCase;

class DebateEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private function connection(string $model = 'gpt-4o'): AiConnection
    {
        return new AiConnection(
            task: 'writing',
            paidBy: 'user',
            provider: 'openai',
            model: $model,
            voice: null,
            config: ['driver' => 'openai', 'key' => 'sk-test-0000', 'url' => ProviderCatalog::url('openai')],
        );
    }

    public function test_it_uses_the_learners_model_for_writing(): void
    {
        $evaluator = new DebateEvaluator(EnglishLevel::B2, $this->connection('gpt-4o'));

        $this->assertSame('gpt-4o', $evaluator->model());
    }

    public function test_it_is_built_on_the_connection_it_is_given(): void
    {
        $evaluator = new DebateEvaluator(EnglishLevel::B2, $this->connection());

        $this->assertSame('openai', app(AiManager::class)->getInstanceConfig($evaluator->provider())['driver']);
    }

    public function test_it_is_told_the_learners_level_and_how_many_words_to_recommend(): void
    {
        $instructions = (string) (new DebateEvaluator(EnglishLevel::B2, $this->connection()))->instructions();

        $this->assertStringContainsString('The learner is at upper-intermediate (B2) level.', $instructions);
        $this->assertStringContainsString('Provide '.DebateEvaluator::RECOMMENDED_WORDS.' specific vocabulary words', $instructions);
        $this->assertStringNotContainsString(':recommended_words', $instructions);
        $this->assertStringNotContainsString('C1-level', $instructions);
    }
}

<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\DebateEvaluator;
use Tests\TestCase;

class DebateEvaluatorTest extends TestCase
{
    public function test_it_uses_the_tutor_model_by_default(): void
    {
        config([
            'debate.llm.provider' => 'openai-compatible',
            'debate.llm.model' => 'chat',
            'debate.evaluator.provider' => null,
            'debate.evaluator.model' => null,
        ]);

        $evaluator = new DebateEvaluator;

        $this->assertSame('openai-compatible', $evaluator->provider());
        $this->assertSame('chat', $evaluator->model());
    }

    public function test_it_can_use_its_own_model_on_the_tutor_provider(): void
    {
        config([
            'debate.llm.provider' => 'openai-compatible',
            'debate.llm.model' => 'chat',
            'debate.evaluator.provider' => null,
            'debate.evaluator.model' => 'general',
        ]);

        $evaluator = new DebateEvaluator;

        $this->assertSame('openai-compatible', $evaluator->provider());
        $this->assertSame('general', $evaluator->model());
    }

    public function test_its_own_provider_never_inherits_the_tutor_model(): void
    {
        config([
            'debate.llm.provider' => 'openai-compatible',
            'debate.llm.model' => 'chat',
            'debate.evaluator.provider' => 'gemini',
            'debate.evaluator.model' => null,
        ]);

        $evaluator = new DebateEvaluator;

        $this->assertSame('gemini', $evaluator->provider());
        $this->assertNull($evaluator->model());
    }
}

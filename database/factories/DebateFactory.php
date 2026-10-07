<?php

namespace Database\Factories;

use App\Enums\DebateStatus;
use App\Models\Debate;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Debate>
 */
class DebateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'news_article_id' => NewsArticle::factory(),
            'status' => DebateStatus::Active,
            'ai_feedback' => null,
            'started_at' => now(),
            'ended_at' => null,
        ];
    }

    /**
     * Indicate that the debate has finished and been evaluated.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DebateStatus::Completed,
            'ended_at' => now(),
            'ai_feedback' => [
                'crutch_words' => ['very', 'thing'],
                'grammar_errors' => [
                    ['error' => 'I am agree', 'correction' => 'I agree'],
                ],
                'recommended_vocabulary' => [
                    ['word' => 'contentious', 'context' => 'This is a contentious policy.'],
                ],
            ],
        ]);
    }
}

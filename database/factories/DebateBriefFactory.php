<?php

namespace Database\Factories;

use App\Enums\EnglishLevel;
use App\Models\DebateBrief;
use App\Models\NewsArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebateBrief>
 */
class DebateBriefFactory extends Factory
{
    /**
     * Define the model's default state. The text is written about the article's own words.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'news_article_id' => NewsArticle::factory(),
            'level' => EnglishLevel::B1,
            'headline' => 'City approves free public transport',
            'what_happened' => 'The city council voted to scrap bus fares from next year.',
            'why_it_matters' => 'Fares pay for part of the service, so the budget has to change.',
            'background' => null,
            'side_for' => ['Free buses help people who cannot afford fares.'],
            'side_against' => ['Someone still has to pay for the service.'],
            'question' => 'Should public transport be free for everyone?',
            'opening' => 'The city council has voted to scrap bus fares. Should public transport be free for everyone?',
            'key_words' => ['fares', 'council', 'budget'],
        ];
    }

    /**
     * Indicate the level the brief was written for.
     */
    public function level(EnglishLevel $level): static
    {
        return $this->state(fn (array $attributes) => ['level' => $level]);
    }
}

<?php

namespace Database\Factories;

use App\Models\NewsArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsArticle>
 */
class NewsArticleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => rtrim(fake()->sentence(8), '.'),
            'source_url' => fake()->unique()->url(),
            'summary' => implode("\n\n", fake()->paragraphs(3)),
            'key_vocabulary' => fake()->words(5),
            'published_at' => fake()->dateTimeBetween('-1 day'),
        ];
    }
}

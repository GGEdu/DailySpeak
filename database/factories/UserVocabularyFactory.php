<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserVocabulary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserVocabulary>
 */
class UserVocabularyFactory extends Factory
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
            'word' => fake()->unique()->word(),
            'mastery_level' => fake()->numberBetween(UserVocabulary::MIN_MASTERY, UserVocabulary::MAX_MASTERY),
            'next_review_at' => fake()->dateTimeBetween('-1 day', '+1 week'),
        ];
    }

    /**
     * Indicate that the word is due for review now.
     */
    public function due(): static
    {
        return $this->state(fn (array $attributes) => [
            'next_review_at' => now()->subMinute(),
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\EnglishLevel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'current_level' => fake()->randomElement(EnglishLevel::cases()),
            'remember_token' => Str::random(10),
            // Most tests are about the app, not the sign-up: their users have accepted the current notice.
            'consented_at' => now(),
            'consent_version' => User::CONSENT_VERSION,
        ];
    }

    /**
     * Indicate that the user is an admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
        ]);
    }

    /**
     * Indicate that the user is at the given CEFR level.
     */
    public function level(EnglishLevel $level): static
    {
        return $this->state(fn (array $attributes) => [
            'current_level' => $level,
        ]);
    }

    /**
     * Indicate that the user has not accepted the privacy notice yet (accounts created before it existed).
     */
    public function withoutConsent(): static
    {
        return $this->state(fn (array $attributes) => [
            'consented_at' => null,
            'consent_version' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}

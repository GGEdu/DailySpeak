<?php

namespace Database\Factories;

use App\Enums\EnglishLevel;
use App\Models\User;
use App\Models\UserAiKey;
use App\Models\UserAiTask;
use App\Services\Ai\ProviderCatalog;
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
     * Under test, every user made by the factory has an AI connection of their own, as a user who set one up has: an
     * OpenAI key and every task picked with its suggested model. Use withoutAi() for a user with none. Seeded development
     * data gets no key: the factory is not a place to keep one.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if (! app()->runningUnitTests()) {
                return;
            }

            UserAiKey::query()->create([
                'user_id' => $user->getKey(),
                'provider' => 'openai',
                'key' => 'sk-factory-test-0000',
                'last4' => '0000',
                'verified_at' => now(),
            ]);

            $suggested = ProviderCatalog::suggestions('openai');

            foreach (['conversation', 'writing', 'stt', 'tts'] as $task) {
                UserAiTask::query()->create([
                    'user_id' => $user->getKey(),
                    'task' => $task,
                    'provider' => 'openai',
                    'model' => $suggested[$task],
                    'voice' => $task === 'tts' ? $suggested['voice'] : null,
                ]);
            }
        });
    }

    /**
     * A user with no AI connection: no key and no task picked. Runs after the connection configure() gives, so it
     * takes it back.
     */
    public function withoutAi(): static
    {
        return $this->afterCreating(function (User $user): void {
            $user->aiKeys()->delete();
            $user->aiTasks()->delete();
        });
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

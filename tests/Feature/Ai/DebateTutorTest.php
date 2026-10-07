<?php

namespace Tests\Feature\Ai;

use App\Ai\Agents\DebateTutor;
use App\Enums\EnglishLevel;
use App\Models\Debate;
use App\Models\DebateMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DebateTutorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{EnglishLevel, string, string}>
     */
    public static function levels(): array
    {
        return [
            'B1' => [EnglishLevel::B1, 'The user is an intermediate (B1) English speaker.', '5. Use clear, everyday vocabulary and short sentences'],
            'B2' => [EnglishLevel::B2, 'The user is an upper-intermediate (B2) English speaker.', '5. Speak naturally and use some idiomatic expressions'],
            'C1' => [EnglishLevel::C1, 'The user is an advanced (C1) English speaker.', '5. Speak as you would to an educated native speaker'],
        ];
    }

    #[DataProvider('levels')]
    public function test_the_tutor_speaks_at_the_users_level(EnglishLevel $level, string $profile, string $guidance): void
    {
        $debate = Debate::factory()->for(User::factory()->level($level))->create();
        $turn = DebateMessage::factory()->for($debate)->fromUser()->create();

        $instructions = (string) (new DebateTutor($debate, $turn))->instructions();

        $this->assertStringContainsString($profile, $instructions);
        $this->assertStringContainsString($guidance, $instructions);
        $this->assertStringNotContainsString(':level', $instructions);
    }

    public function test_a_level_change_applies_from_the_next_turn(): void
    {
        $debate = Debate::factory()->for(User::factory()->level(EnglishLevel::B1))->create();
        $turn = DebateMessage::factory()->for($debate)->fromUser()->create();

        $debate->user->update(['current_level' => EnglishLevel::C1]);

        $this->assertStringContainsString('(C1)', (string) (new DebateTutor($debate->fresh(), $turn))->instructions());
    }
}

<?php

namespace Database\Seeders;

use App\Enums\EnglishLevel;
use App\Models\Debate;
use App\Models\DebateMessage;
use App\Models\NewsArticle;
use App\Models\User;
use App\Models\UserVocabulary;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->admin()->level(EnglishLevel::C1)->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $articles = NewsArticle::factory(5)->create();

        $debate = Debate::factory()
            ->completed()
            ->for($user)
            ->for($articles->first())
            ->create();

        foreach (range(1, 3) as $turn) {
            DebateMessage::factory()->for($debate)->fromUser()->create();
            DebateMessage::factory()->for($debate)->fromAssistant()->create();
        }

        UserVocabulary::factory(5)->for($user)->create();
    }
}

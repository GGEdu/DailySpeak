<?php

namespace Tests\Feature\Models;

use App\Models\User;
use App\Models\UserVocabulary;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserVocabularyTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_word_starts_at_minimum_mastery(): void
    {
        $word = UserVocabulary::create([
            'user_id' => User::factory()->create()->id,
            'word' => 'nuance',
            'next_review_at' => now(),
        ]);

        $this->assertSame(1, $word->mastery_level);
        $this->assertSame(1, $word->fresh()->mastery_level);
    }

    public function test_word_is_normalised(): void
    {
        $word = UserVocabulary::factory()->create(['word' => '  Ubiquitous ']);

        $this->assertSame('ubiquitous', $word->fresh()->word);
    }

    public function test_word_is_unique_per_user(): void
    {
        $user = User::factory()->create();
        UserVocabulary::factory()->for($user)->create(['word' => 'nuance']);

        $this->expectException(UniqueConstraintViolationException::class);

        UserVocabulary::factory()->for($user)->create(['word' => 'Nuance']);
    }

    public function test_different_users_can_learn_the_same_word(): void
    {
        UserVocabulary::factory()->create(['word' => 'nuance']);
        UserVocabulary::factory()->create(['word' => 'nuance']);

        $this->assertDatabaseCount('user_vocabularies', 2);
    }

    public function test_vocabulary_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $word = UserVocabulary::factory()->for($user)->create();

        $this->assertTrue($word->user->is($user));
    }

    public function test_mastery_level_is_constrained_on_postgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The mastery_level CHECK constraint is only created on PostgreSQL.');
        }

        $this->expectException(QueryException::class);

        UserVocabulary::factory()->create(['mastery_level' => 6]);
    }
}

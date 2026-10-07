<?php

namespace Tests\Feature\Models;

use App\Enums\DebateStatus;
use App\Enums\EnglishLevel;
use App\Models\Debate;
use App\Models\NewsArticle;
use App\Models\User;
use App\Models\UserVocabulary;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_level_is_cast_to_enum(): void
    {
        $user = User::factory()->level(EnglishLevel::C1)->create();

        $this->assertSame(EnglishLevel::C1, $user->fresh()->current_level);
    }

    public function test_current_level_defaults_to_b2(): void
    {
        $user = User::create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'secret-password',
        ]);

        $this->assertSame(EnglishLevel::B2, $user->current_level);
        $this->assertSame(EnglishLevel::B2, $user->fresh()->current_level);
    }

    public function test_user_has_many_debates_and_vocabularies(): void
    {
        $user = User::factory()->create();
        Debate::factory(2)->for($user)->create();
        UserVocabulary::factory(3)->for($user)->create();

        $this->assertCount(2, $user->debates);
        $this->assertCount(3, $user->vocabularies);
        $this->assertContainsOnlyInstancesOf(Debate::class, $user->debates);
        $this->assertContainsOnlyInstancesOf(UserVocabulary::class, $user->vocabularies);
    }

    public function test_starting_a_debate_twice_resumes_the_same_one(): void
    {
        $user = User::factory()->create();
        $article = NewsArticle::factory()->create();

        $first = $user->startDebate($article);
        $second = $user->startDebate($article);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, $user->debates()->count());
    }

    public function test_a_finished_debate_does_not_block_a_new_one(): void
    {
        $user = User::factory()->create();
        $article = NewsArticle::factory()->create();
        Debate::factory()->completed()->for($user)->for($article)->create();

        $debate = $user->startDebate($article);

        $this->assertSame(DebateStatus::Active, $debate->status);
        $this->assertSame(2, $user->debates()->count());
    }

    public function test_a_concurrent_start_resolves_to_the_debate_that_won_the_race(): void
    {
        $user = User::factory()->create();
        $article = NewsArticle::factory()->create();
        $winner = null;
        $racing = false;
        // Another request inserts its debate right after this one looked for an active debate and found none.
        DB::listen(function (QueryExecuted $query) use ($user, $article, &$winner, &$racing) {
            if (! $racing && $winner === null && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"debates"')) {
                $racing = true;
                $winner = Debate::factory()->for($user)->for($article)->create();
                $racing = false;
            }
        });

        $debate = $user->startDebate($article);

        $this->assertNotNull($winner);
        $this->assertTrue($debate->is($winner));
        $this->assertSame(1, $user->debates()->count());
    }

    public function test_deleting_a_user_cascades_to_their_debates_and_vocabulary(): void
    {
        $user = User::factory()->create();
        Debate::factory()->for($user)->create();
        UserVocabulary::factory()->for($user)->create();

        $user->delete();

        $this->assertDatabaseCount('debates', 0);
        $this->assertDatabaseCount('user_vocabularies', 0);
    }
}

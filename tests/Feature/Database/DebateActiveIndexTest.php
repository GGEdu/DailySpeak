<?php

namespace Tests\Feature\Database;

use App\Enums\DebateStatus;
use App\Models\Debate;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DebateActiveIndexTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_08_120000_add_unique_active_debate_index_to_debates_table.php';

    public function test_a_user_cannot_have_two_active_debates_about_the_same_article(): void
    {
        $user = User::factory()->create();
        $article = NewsArticle::factory()->create();
        Debate::factory()->for($user)->for($article)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        Debate::factory()->for($user)->for($article)->create();
    }

    public function test_the_limit_only_applies_to_active_debates(): void
    {
        $user = User::factory()->create();
        $article = NewsArticle::factory()->create();

        Debate::factory(2)->completed()->for($user)->for($article)->create();
        Debate::factory()->for(User::factory())->for($article)->create();

        $this->assertSame(2, $user->debates()->where('status', DebateStatus::Completed)->count());
    }

    public function test_the_migration_completes_older_duplicates_before_creating_the_index(): void
    {
        DB::statement('DROP INDEX debates_one_active_per_article');
        $user = User::factory()->create();
        $article = NewsArticle::factory()->create();
        $oldest = Debate::factory()->for($user)->for($article)->create();
        $duplicate = Debate::factory()->for($user)->for($article)->create();
        $otherArticle = Debate::factory()->for($user)->create();

        $migration = require database_path('migrations/'.self::MIGRATION);
        $migration->up();

        $this->assertTrue($oldest->fresh()->isActive());
        $this->assertSame(DebateStatus::Completed, $duplicate->fresh()->status);
        $this->assertNotNull($duplicate->fresh()->ended_at);
        $this->assertTrue($otherArticle->fresh()->isActive());

        $this->expectException(UniqueConstraintViolationException::class);
        Debate::factory()->for($user)->for($article)->create();
    }
}

<?php

namespace Tests\Feature\Api;

use App\Enums\DebateStatus;
use App\Models\Debate;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StartDebateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_start_a_debate(): void
    {
        $article = NewsArticle::factory()->create();

        $this->postJson("/api/news-articles/{$article->id}/debates")->assertUnauthorized();
    }

    public function test_a_user_starts_a_debate_about_an_article(): void
    {
        $user = Sanctum::actingAs(User::factory()->create());
        $article = NewsArticle::factory()->create();

        $response = $this->postJson("/api/news-articles/{$article->id}/debates")->assertCreated();

        $debate = Debate::sole();
        $this->assertTrue($debate->user->is($user));
        $this->assertSame(DebateStatus::Active, $debate->status);
        $response->assertExactJson([
            'data' => [
                'id' => $debate->id,
                'news_article_id' => $article->id,
                'status' => 'active',
                'started_at' => $debate->started_at->toJSON(),
                'ended_at' => null,
                'channel' => "debates.{$debate->id}",
            ],
        ]);
    }

    public function test_an_active_debate_about_the_same_article_is_resumed(): void
    {
        $user = Sanctum::actingAs(User::factory()->create());
        $article = NewsArticle::factory()->create();
        $active = Debate::factory()->for($user)->for($article)->create();
        Debate::factory()->for($article)->create(); // someone else's

        $this->postJson("/api/news-articles/{$article->id}/debates")
            ->assertOk()
            ->assertJsonPath('data.id', $active->id);

        $this->assertSame(2, Debate::count());
    }

    public function test_a_finished_debate_is_not_resumed(): void
    {
        $user = Sanctum::actingAs(User::factory()->create());
        $article = NewsArticle::factory()->create();
        $finished = Debate::factory()->completed()->for($user)->for($article)->create();

        $this->postJson("/api/news-articles/{$article->id}/debates")->assertCreated();

        $this->assertSame(2, $user->debates()->count());
        $this->assertNotSame($finished->id, $user->debates()->latest('id')->first()->id);
    }

    public function test_unknown_articles_return_not_found(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/news-articles/999/debates')->assertNotFound();
    }
}

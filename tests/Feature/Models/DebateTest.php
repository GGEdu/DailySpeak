<?php

namespace Tests\Feature\Models;

use App\Enums\DebateStatus;
use App\Models\Debate;
use App\Models\DebateMessage;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebateTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_debate_is_active_and_started_now(): void
    {
        $this->freezeSecond();

        $debate = Debate::create([
            'user_id' => User::factory()->create()->id,
            'news_article_id' => NewsArticle::factory()->create()->id,
        ]);

        $this->assertSame(DebateStatus::Active, $debate->status);
        $this->assertTrue($debate->started_at->equalTo(now()));
        $this->assertNull($debate->ended_at);
        $this->assertNull($debate->ai_feedback);
    }

    public function test_status_and_ai_feedback_are_cast(): void
    {
        $debate = Debate::factory()->completed()->create();

        $fresh = $debate->fresh();

        $this->assertSame(DebateStatus::Completed, $fresh->status);
        $this->assertIsArray($fresh->ai_feedback);
        $this->assertSame(['very', 'thing'], $fresh->ai_feedback['crutch_words']);
        $this->assertSame('I agree', $fresh->ai_feedback['grammar_errors'][0]['correction']);
        $this->assertNotNull($fresh->ended_at);
    }

    public function test_debate_belongs_to_user_and_article_and_has_messages(): void
    {
        $user = User::factory()->create();
        $article = NewsArticle::factory()->create();
        $debate = Debate::factory()->for($user)->for($article)->create();
        DebateMessage::factory()->for($debate)->fromUser()->create();
        DebateMessage::factory()->for($debate)->fromAssistant()->create();

        $this->assertTrue($debate->user->is($user));
        $this->assertTrue($debate->newsArticle->is($article));
        $this->assertCount(2, $debate->messages);
    }

    public function test_deleting_a_debate_cascades_to_its_messages(): void
    {
        $debate = Debate::factory()->create();
        DebateMessage::factory(3)->for($debate)->create();

        $debate->delete();

        $this->assertDatabaseCount('debate_messages', 0);
    }

    public function test_a_stale_copy_does_not_close_a_debate_again(): void
    {
        $debate = Debate::factory()->create();
        $stale = Debate::find($debate->id);   // still active in this copy
        $this->travel(1)->minutes();
        $debate->finish();
        $endedAt = $debate->fresh()->ended_at;

        $this->travel(5)->minutes();
        $stale->finish();

        // The database already holds the first end time: the stale copy must not overwrite it.
        $this->assertTrue($debate->fresh()->ended_at->equalTo($endedAt));
        $this->assertSame(DebateStatus::Completed, $stale->status);
        $this->assertTrue($stale->ended_at->equalTo($endedAt));
    }

    public function test_finishing_an_active_debate_closes_it_and_refreshes_the_copy(): void
    {
        $debate = Debate::factory()->create();

        $debate->finish();

        $this->assertSame(DebateStatus::Completed, $debate->status);
        $this->assertNotNull($debate->ended_at);
        $this->assertSame(DebateStatus::Completed, $debate->fresh()->status);
    }
}
